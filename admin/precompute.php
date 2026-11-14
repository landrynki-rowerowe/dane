<?php
declare(strict_types=1);

date_default_timezone_set("Europe/Warsaw");
set_time_limit(0);

$CSV_URL = "https://docs.google.com/spreadsheets/d/e/2PACX-1vR6Y2bE2n2rWeIwFoA-YPyVuFa9BOjEFdWfTIyph-1PcyeaaRacmM_4-Q_QdYo1dOrAldVSy4hi334J/pub?gid=1137251897&single=true&output=csv";

$FULL_COVERAGE_THRESHOLD = 0.95;
$CSV_CACHE_TTL_SECONDS = 6 * 3600;

$root = realpath(__DIR__ . "/..");
$cacheDir = $root . "/cache";
$permDir  = $cacheDir . "/permanent";
$csvCache = $cacheDir . "/data.csv";
$geoPath  = $root . "/data/counters.geojson";

$force = isset($_POST["force"]) && $_POST["force"] === "1";

function logln(string $s): void {
  echo htmlspecialchars($s) . "<br>\n";
  @ob_flush(); @flush();
}
function ensureDir(string $dir): void {
  if (!is_dir($dir)) {
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
      throw new RuntimeException("Nie mogę utworzyć katalogu: $dir");
    }
  }
}
function readJsonFile(string $path): array {
  $raw = @file_get_contents($path);
  if ($raw === false) throw new RuntimeException("Nie mogę czytać JSON: $path");
  $data = json_decode($raw, true);
  if (!is_array($data)) throw new RuntimeException("Niepoprawny JSON: $path");
  return $data;
}
function writeJsonPretty(string $path, array $data): void {
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException("json_encode failed for $path");
  if (@file_put_contents($path, $json) === false) {
    throw new RuntimeException("Nie mogę zapisać: $path");
  }
}
function medianInt(array $vals): ?int {
  $vals = array_values(array_filter($vals, fn($v) => is_numeric($v)));
  $n = count($vals);
  if ($n === 0) return null;
  sort($vals, SORT_NUMERIC);
  $mid = intdiv($n, 2);
  if ($n % 2 === 1) return (int)round($vals[$mid]);
  return (int)round(($vals[$mid-1] + $vals[$mid]) / 2);
}
function daysInMonth(string $ym): int {
  $dt = DateTime::createFromFormat("Y-m", $ym);
  if (!$dt) return 30;
  return (int)$dt->format("t");
}
function expectedHoursInMonth(string $ym): int {
  return daysInMonth($ym) * 24;
}
function dayCountsRange(string $fromYmd, string $toYmd): array {
  // returns [days_all, days_weekday, days_weekend]
  $start = DateTime::createFromFormat("Y-m-d", $fromYmd);
  $end   = DateTime::createFromFormat("Y-m-d", $toYmd);
  if (!$start || !$end) return [0,0,0];
  $end = (clone $end)->modify("+1 day"); // inclusive loop
  $period = new DatePeriod($start, new DateInterval("P1D"), $end);

  $all=0; $wd=0; $we=0;
  foreach ($period as $d) {
    $all++;
    $n = (int)$d->format("N");
    if ($n >= 6) $we++; else $wd++;
  }
  return [$all,$wd,$we];
}
function monthToQuarter(string $ym): string {
  $y = substr($ym,0,4);
  $m = (int)substr($ym,5,2);
  $q = (int)ceil($m / 3);
  return $y . "-Q" . $q;
}
function quarterMonths(string $yq): array {
  // "2026-Q1" => ["2026-01","2026-02","2026-03"]
  $y = substr($yq,0,4);
  $q = (int)substr($yq,6,1);
  $startM = ($q-1)*3 + 1;
  return [
    sprintf("%s-%02d", $y, $startM),
    sprintf("%s-%02d", $y, $startM+1),
    sprintf("%s-%02d", $y, $startM+2),
  ];
}

?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Precompute</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <h1 class="h4 mb-3">Precompute: miesiące + kwartały + lata (baseline median)</h1>
  <div class="card shadow-sm">
    <div class="card-body">
<?php
try {
  $t0 = microtime(true);

  ensureDir($cacheDir);
  ensureDir($permDir);

  // GeoJSON id->name
  if (!file_exists($geoPath)) throw new RuntimeException("Brak GeoJSON: $geoPath");
  $geo = readJsonFile($geoPath);
  $nameById = [];
  foreach (($geo["features"] ?? []) as $f) {
    $id = $f["properties"]["id"] ?? null;
    $nm = $f["properties"]["name"] ?? null;
    if ($id) $nameById[$id] = $nm ?: $id;
  }
  logln("GeoJSON OK: nazw: " . count($nameById));

  // CSV cache
  $needDownload = $force;
  if (!$needDownload && file_exists($csvCache)) {
    $age = time() - filemtime($csvCache);
    if ($age >= $CSV_CACHE_TTL_SECONDS) $needDownload = true;
  } else {
    $needDownload = true;
  }

  if ($needDownload) {
    logln("Pobieram CSV…");
    $csv = @file_get_contents($CSV_URL);
    if ($csv === false || strlen($csv) < 100) throw new RuntimeException("Nie udało się pobrać CSV.");
    if (@file_put_contents($csvCache, $csv) === false) throw new RuntimeException("Nie mogę zapisać CSV cache.");
    logln("CSV cache zapisany: " . number_format(strlen($csv)/1024/1024, 2) . " MB");
  } else {
    logln("Używam CSV z cache (TTL OK).");
  }

  // Parse CSV -> monthly aggregates for all counters
  $fh = fopen($csvCache, "r");
  if (!$fh) throw new RuntimeException("Nie mogę otworzyć CSV cache.");
  $header = fgetcsv($fh);
  if (!$header) throw new RuntimeException("CSV nie ma nagłówka.");
  $header = array_map(fn($h) => trim((string)$h), $header);

  $idxDate = array_search("date", $header, true);
  if ($idxDate === false) throw new RuntimeException("Brak kolumny date.");

  $counters = [];
  $counterIdx = [];
  for ($i=0; $i<count($header); $i++) {
    if ($i === $idxDate) continue;
    $id = $header[$i];
    $counters[] = $id;
    $counterIdx[$id] = $i;
  }
  logln("Liczników: " . count($counters));

  // agg[ym][id] = sums + hours_present
  $agg = [];
  $minMonth = null; $maxMonth = null;
  $rows = 0;
// ===== DETAILS (miesięczne) =====
// details[ym][id]["daily"][YYYY-MM-DD] = suma dzienna
// details[ym][id]["hour"][YYYY-MM-DD][H] = suma w danej godzinie danego dnia
// details[ym][id]["hour_present"][YYYY-MM-DD][H] = true jeśli była dana (komórka niepusta)
$details = [];

  while (($row = fgetcsv($fh)) !== false) {
    $rows++;
    $dtStr = trim((string)($row[$idxDate] ?? ""));
    if ($dtStr === "") continue;
    $ts = strtotime($dtStr);
    if (!$ts) continue;

    $ym = date("Y-m", $ts);
    if ($minMonth === null || $ym < $minMonth) $minMonth = $ym;
    if ($maxMonth === null || $ym > $maxMonth) $maxMonth = $ym;

    $dow = (int)date("N", $ts);
    $isWeekend = ($dow >= 6);

    if (!isset($agg[$ym])) $agg[$ym] = [];

    foreach ($counters as $id) {
      $idx = $counterIdx[$id];
      $valStr = trim((string)($row[$idx] ?? ""));
      if ($valStr === "") continue; // brak danych

      // "0" traktujemy jako obecność danych (godzina obecna)
      $val = (int)preg_replace('/[^0-9]/', '', $valStr);

      if (!isset($agg[$ym][$id])) {
        $agg[$ym][$id] = [
          "sum_all" => 0,
          "sum_weekday" => 0,
          "sum_weekend" => 0,
          "hours_present" => 0
        ];
      }

      $agg[$ym][$id]["hours_present"] += 1;
      if ($val > 0) {
        $agg[$ym][$id]["sum_all"] += $val;
        if ($isWeekend) $agg[$ym][$id]["sum_weekend"] += $val;
        else $agg[$ym][$id]["sum_weekday"] += $val;
      }
    // ===== DETAILS: daily + hourly per dzień =====
$day = date("Y-m-d", $ts);
$h = (int)date("G", $ts);

if (!isset($details[$ym])) $details[$ym] = [];
if (!isset($details[$ym][$id])) {
  $details[$ym][$id] = [
    "daily" => [],
    "hour" => [],
    "hour_present" => []
  ];
}

if (!isset($details[$ym][$id]["daily"][$day])) $details[$ym][$id]["daily"][$day] = 0;
$details[$ym][$id]["daily"][$day] += $val;

if (!isset($details[$ym][$id]["hour"][$day])) $details[$ym][$id]["hour"][$day] = [];
if (!isset($details[$ym][$id]["hour"][$day][$h])) $details[$ym][$id]["hour"][$day][$h] = 0;
$details[$ym][$id]["hour"][$day][$h] += $val;

if (!isset($details[$ym][$id]["hour_present"][$day])) $details[$ym][$id]["hour_present"][$day] = [];
$details[$ym][$id]["hour_present"][$day][$h] = true;
    }
  }
  fclose($fh);

  logln("Wierszy: " . number_format($rows, 0, "", " "));
  logln("Miesiące w danych: " . ($minMonth ?? "—") . " .. " . ($maxMonth ?? "—"));

  // last full month = previous calendar month
  $firstThisMonth = strtotime(date("Y-m-01 00:00:00"));
  $lastFullMonth = date("Y-m", strtotime("-1 month", $firstThisMonth));
  logln("Ostatni pełny miesiąc: " . $lastFullMonth);

  $months = array_keys($agg);
  sort($months);

  // ===== MONTH OVERVIEW =====
  $generatedOverviewM = 0;
  $availableMonths = [];

  foreach ($months as $ym) {
    if ($ym > $lastFullMonth) continue;
    $availableMonths[] = $ym;

    $expectedHours = expectedHoursInMonth($ym);
    $from = $ym . "-01";
    $to   = date("Y-m-t", strtotime($from));
    [$daysAll, $daysWeekday, $daysWeekend] = dayCountsRange($from, $to);

    $items = [];
    foreach ($counters as $id) {
      $m = $agg[$ym][$id] ?? ["sum_all"=>0,"sum_weekday"=>0,"sum_weekend"=>0,"hours_present"=>0];
      $coverage = $expectedHours > 0 ? ($m["hours_present"] / $expectedHours) : 0.0;
      $isFull = ($coverage >= $FULL_COVERAGE_THRESHOLD);

      $avgDaily   = $daysAll > 0 ? (int)round($m["sum_all"] / $daysAll) : 0;
      $avgWeekday = $daysWeekday > 0 ? (int)round($m["sum_weekday"] / $daysWeekday) : 0;
      $avgWeekend = $daysWeekend > 0 ? (int)round($m["sum_weekend"] / $daysWeekend) : 0;

      $items[] = [
        "id" => $id,
        "name" => $nameById[$id] ?? $id,

        "month_sum" => (int)$m["sum_all"],   // kompatybilność
        "period_sum" => (int)$m["sum_all"],  // uniwersalne

        "avg_daily" => $avgDaily,
        "avg_weekday" => $avgWeekday,
        "avg_weekend" => $avgWeekend,
        "coverage" => round($coverage, 4),
        "is_full" => $isFull,
        "hours_present"  => (int)($m["hours_present"] ?? 0),
        "hours_expected" => (int)$expectedHours
      ];
    }

    $out = [
      "mode" => "month",
      "period" => $ym,
      "from" => $from,
      "to" => $to,
      "generated_at" => date("c"),
      "threshold_full" => $FULL_COVERAGE_THRESHOLD,
      "items" => $items
    ];

    writeJsonPretty($permDir . "/overview_month_" . $ym . ".json", $out);
    $generatedOverviewM++;
  }

  logln("overview_month wygenerowane: " . $generatedOverviewM);

    // ===== DETAILS MONTH: daily + hourly profiles + weekday stats + best day =====
$generatedDetailsM = 0;

$weekdayOrder = ["Mon","Tue","Wed","Thu","Fri","Sat","Sun"];
$weekdayNamesPL = ["Pon","Wto","Śro","Czw","Pią","Sob","Nie"];

foreach ($availableMonths as $ym) {
  // budujemy jeden plik na miesiąc (z wszystkimi licznikami)
  $from = $ym . "-01";
  $to   = date("Y-m-t", strtotime($from));

  $outCounters = [];

  foreach ($counters as $id) {
    $d = $details[$ym][$id] ?? null;
    if (!$d) {
      // brak danych dla licznika w tym miesiącu
      $outCounters[$id] = [
        "id" => $id,
        "name" => $nameById[$id] ?? $id,
        "total" => 0,
        "daily" => [],
        "hourly_weekday" => [],
        "hourly_weekend" => [],
        "weekday_stats" => array_map(fn($nm) => ["weekday"=>$nm,"avg"=>0,"median"=>0], $weekdayNamesPL),
        "best_day" => null
      ];
      continue;
    }

    // DAILY array
    $dailyMap = $d["daily"] ?? [];
    ksort($dailyMap);
    $dailyArr = [];
    $total = 0;

    $bestDay = null;
    foreach ($dailyMap as $day => $cnt) {
      $cnt = (int)$cnt;
      $dailyArr[] = ["date" => $day, "count" => $cnt];
      $total += $cnt;

      if ($bestDay === null || $cnt > $bestDay["count"]) {
        $wd = $weekdayNamesPL[(int)date("N", strtotime($day)) - 1];
        $bestDay = ["date" => $day, "weekday" => $wd, "count" => $cnt];
      }
    }

    // HOURLY split: avg + median per hour (weekday vs weekend)
    $hourMap = $d["hour"] ?? [];
    $presentMap = $d["hour_present"] ?? [];

    $hourlyWeekday = [];
    $hourlyWeekend = [];

    for ($h=0; $h<24; $h++) {
      $valsW = [];
      $valsE = [];

      foreach ($hourMap as $day => $hours) {
        // bierzemy tylko godziny, które faktycznie były obecne w CSV dla tego licznika
        if (empty($presentMap[$day][$h])) continue;

        $v = (int)($hours[$h] ?? 0);
        $dow = (int)date("N", strtotime($day));
        $isWeekend = ($dow >= 6);

        if ($isWeekend) $valsE[] = $v;
        else $valsW[] = $v;
      }

      $avgW = count($valsW) ? (int)round(array_sum($valsW) / count($valsW)) : 0;
      $avgE = count($valsE) ? (int)round(array_sum($valsE) / count($valsE)) : 0;

      $medW = medianInt($valsW); if ($medW === null) $medW = 0;
      $medE = medianInt($valsE); if ($medE === null) $medE = 0;
        
        $minW = count($valsW) ? min($valsW) : 0;
        $maxW = count($valsW) ? max($valsW) : 0;

        $minE = count($valsE) ? min($valsE) : 0;
        $maxE = count($valsE) ? max($valsE) : 0;

      $hourlyWeekday[] = [
  "hour" => str_pad((string)$h, 2, "0", STR_PAD_LEFT),
  "avg" => $avgW,
  "median" => $medW,
  "min" => $minW,
  "max" => $maxW
];

$hourlyWeekend[] = [
  "hour" => str_pad((string)$h, 2, "0", STR_PAD_LEFT),
  "avg" => $avgE,
  "median" => $medE,
  "min" => $minE,
  "max" => $maxE
];
    }
      

    // WEEKDAY stats: avg + median z dziennych sum
    $valsByWeekday = [ "Mon"=>[], "Tue"=>[], "Wed"=>[], "Thu"=>[], "Fri"=>[], "Sat"=>[], "Sun"=>[] ];
    foreach ($dailyMap as $day => $cnt) {
      $key = date("D", strtotime($day)); // Mon..Sun
      if (isset($valsByWeekday[$key])) $valsByWeekday[$key][] = (int)$cnt;
    }

    $weekdayStats = [];
    for ($i=0; $i<7; $i++) {
      $key = $weekdayOrder[$i];
      $vals = $valsByWeekday[$key] ?? [];
      $avg = count($vals) ? (int)round(array_sum($vals) / count($vals)) : 0;
      $med = medianInt($vals); if ($med === null) $med = 0;
      $weekdayStats[] = ["weekday" => $weekdayNamesPL[$i], "avg" => $avg, "median" => $med];
    }

    $outCounters[$id] = [
      "id" => $id,
      "name" => $nameById[$id] ?? $id,
      "total" => $total,
      "daily" => $dailyArr,
      "hourly_weekday" => $hourlyWeekday,
      "hourly_weekend" => $hourlyWeekend,
      "weekday_stats" => $weekdayStats,
      "best_day" => $bestDay
    ];
  }

  $out = [
    "mode" => "month",
    "period" => $ym,
    "from" => $from,
    "to" => $to,
    "generated_at" => date("c"),
    "counters" => $outCounters
  ];

  writeJsonPretty($permDir . "/details_month_" . $ym . ".json", $out);
  $generatedDetailsM++;
}

logln("details_month wygenerowane: " . $generatedDetailsM);
    


  // ===== BASELINE MONTH (MM=01..12) =====
  $generatedBaselineM = 0;
  for ($mm=1; $mm<=12; $mm++) {
    $mmStr = str_pad((string)$mm, 2, "0", STR_PAD_LEFT);
    $items = [];

    foreach ($counters as $id) {
      $listDaily=[]; $listWeekday=[]; $listWeekend=[];

      foreach ($availableMonths as $ym) {
        if (substr($ym, 5, 2) !== $mmStr) continue;

        $expectedHours = expectedHoursInMonth($ym);
        $from = $ym . "-01";
        $to   = date("Y-m-t", strtotime($from));
        [$daysAll, $daysWeekday, $daysWeekend] = dayCountsRange($from, $to);

        $m = $agg[$ym][$id] ?? null;
        if (!$m) continue;
        $coverage = $expectedHours > 0 ? ($m["hours_present"] / $expectedHours) : 0.0;
        if ($coverage < $FULL_COVERAGE_THRESHOLD) continue;

        $listDaily[]   = $daysAll > 0 ? (int)round($m["sum_all"] / $daysAll) : 0;
        $listWeekday[] = $daysWeekday > 0 ? (int)round($m["sum_weekday"] / $daysWeekday) : 0;
        $listWeekend[] = $daysWeekend > 0 ? (int)round($m["sum_weekend"] / $daysWeekend) : 0;
      }

      $items[] = [
        "id" => $id,
        "name" => $nameById[$id] ?? $id,
        "median_daily" => medianInt($listDaily),
        "median_weekday" => medianInt($listWeekday),
        "median_weekend" => medianInt($listWeekend),
        "n_periods" => count($listDaily)
      ];
    }

    writeJsonPretty($permDir . "/baseline_median_month_" . $mmStr . ".json", [
      "mode" => "month",
      "key" => $mmStr,
      "generated_at" => date("c"),
      "threshold_full" => $FULL_COVERAGE_THRESHOLD,
      "items" => $items
    ]);
    $generatedBaselineM++;
  }
  logln("baseline_median_month wygenerowane: " . $generatedBaselineM);

  // ===== QUARTERS: list available quarters <= lastFullMonth (full 3 months) =====
  $quartersSet = [];
  foreach ($availableMonths as $ym) {
    $quartersSet[monthToQuarter($ym)] = true;
  }
  $quarters = array_keys($quartersSet);
  sort($quarters);

  $availableQuarters = [];
  foreach ($quarters as $yq) {
    $ms = quarterMonths($yq);
    // quarter is available only if all 3 months exist in availableMonths
    if (in_array($ms[0], $availableMonths, true) &&
        in_array($ms[1], $availableMonths, true) &&
        in_array($ms[2], $availableMonths, true)) {
      $availableQuarters[] = $yq;
    }
  }

  $lastFullQuarter = count($availableQuarters) ? end($availableQuarters) : null;
  if ($lastFullQuarter) logln("Ostatni pełny kwartał: " . $lastFullQuarter);
    
// ===== DETAILS QUARTER: daily + hourly profiles + weekday stats + best day =====
$generatedDetailsQ = 0;

foreach ($availableQuarters as $yq) {
  $ms = quarterMonths($yq);
  $from = $ms[0] . "-01";
  $to   = date("Y-m-t", strtotime($ms[2] . "-01"));

  $outCounters = [];

  foreach ($counters as $id) {
    // Zbierz dane z 3 miesięcy do jednego "quarter details"
    $dailyMap = [];
    $hourMap = [];
    $presentMap = [];

    foreach ($ms as $ym) {
      $d = $details[$ym][$id] ?? null;
      if (!$d) continue;

      foreach (($d["daily"] ?? []) as $day => $cnt) {
        if (!isset($dailyMap[$day])) $dailyMap[$day] = 0;
        $dailyMap[$day] += (int)$cnt;
      }
      foreach (($d["hour"] ?? []) as $day => $hours) {
        if (!isset($hourMap[$day])) $hourMap[$day] = [];
        foreach ($hours as $h => $cnt) {
          $h = (int)$h;
          if (!isset($hourMap[$day][$h])) $hourMap[$day][$h] = 0;
          $hourMap[$day][$h] += (int)$cnt;
        }
      }
      foreach (($d["hour_present"] ?? []) as $day => $hoursPresent) {
        if (!isset($presentMap[$day])) $presentMap[$day] = [];
        foreach ($hoursPresent as $h => $_true) {
          $presentMap[$day][(int)$h] = true;
        }
      }
    }

    if (!$dailyMap) {
      $outCounters[$id] = [
        "id" => $id,
        "name" => $nameById[$id] ?? $id,
        "total" => 0,
        "daily" => [],
        "hourly_weekday" => [],
        "hourly_weekend" => [],
        "weekday_stats" => array_map(fn($nm) => ["weekday"=>$nm,"avg"=>0,"median"=>0], $weekdayNamesPL),
        "best_day" => null
      ];
      continue;
    }

    ksort($dailyMap);
    $dailyArr = [];
    $total = 0;
    $bestDay = null;

    foreach ($dailyMap as $day => $cnt) {
      $cnt = (int)$cnt;
      $dailyArr[] = ["date" => $day, "count" => $cnt];
      $total += $cnt;

      if ($bestDay === null || $cnt > $bestDay["count"]) {
        $wd = $weekdayNamesPL[(int)date("N", strtotime($day)) - 1];
        $bestDay = ["date" => $day, "weekday" => $wd, "count" => $cnt];
      }
    }

    // hourly weekday/weekend (avg + median) na bazie dni
    $hourlyWeekday = [];
    $hourlyWeekend = [];
    for ($h=0; $h<24; $h++) {
      $valsW = [];
      $valsE = [];

      foreach ($hourMap as $day => $hours) {
        if (empty($presentMap[$day][$h])) continue;

        $v = (int)($hours[$h] ?? 0);
        $dow = (int)date("N", strtotime($day));
        $isWeekend = ($dow >= 6);

        if ($isWeekend) $valsE[] = $v;
        else $valsW[] = $v;
      }

      $avgW = count($valsW) ? (int)round(array_sum($valsW) / count($valsW)) : 0;
      $avgE = count($valsE) ? (int)round(array_sum($valsE) / count($valsE)) : 0;

      $medW = medianInt($valsW); if ($medW === null) $medW = 0;
      $medE = medianInt($valsE); if ($medE === null) $medE = 0;
    $minW = count($valsW) ? min($valsW) : 0;
$maxW = count($valsW) ? max($valsW) : 0;

$minE = count($valsE) ? min($valsE) : 0;
$maxE = count($valsE) ? max($valsE) : 0;

      $hourlyWeekday[] = [
  "hour" => str_pad((string)$h, 2, "0", STR_PAD_LEFT),
  "avg" => $avgW,
  "median" => $medW,
  "min" => $minW,
  "max" => $maxW
];

$hourlyWeekend[] = [
  "hour" => str_pad((string)$h, 2, "0", STR_PAD_LEFT),
  "avg" => $avgE,
  "median" => $medE,
  "min" => $minE,
  "max" => $maxE
];
    }

    // weekday stats (avg + median) z dziennych sum
    $valsByWeekday = [ "Mon"=>[], "Tue"=>[], "Wed"=>[], "Thu"=>[], "Fri"=>[], "Sat"=>[], "Sun"=>[] ];
    foreach ($dailyMap as $day => $cnt) {
      $key = date("D", strtotime($day));
      if (isset($valsByWeekday[$key])) $valsByWeekday[$key][] = (int)$cnt;
    }

    $weekdayStats = [];
    for ($i=0; $i<7; $i++) {
      $key = $weekdayOrder[$i];
      $vals = $valsByWeekday[$key] ?? [];
      $avg = count($vals) ? (int)round(array_sum($vals) / count($vals)) : 0;
      $med = medianInt($vals); if ($med === null) $med = 0;
      $weekdayStats[] = ["weekday" => $weekdayNamesPL[$i], "avg" => $avg, "median" => $med];
    }

    $outCounters[$id] = [
      "id" => $id,
      "name" => $nameById[$id] ?? $id,
      "total" => $total,
      "daily" => $dailyArr,
      "hourly_weekday" => $hourlyWeekday,
      "hourly_weekend" => $hourlyWeekend,
      "weekday_stats" => $weekdayStats,
      "best_day" => $bestDay
    ];
  }

  writeJsonPretty($permDir . "/details_quarter_" . $yq . ".json", [
    "mode" => "quarter",
    "period" => $yq,
    "from" => $from,
    "to" => $to,
    "generated_at" => date("c"),
    "counters" => $outCounters
  ]);

  $generatedDetailsQ++;
}
logln("details_quarter wygenerowane: " . $generatedDetailsQ);

  // ===== QUARTER OVERVIEW ===== 
  $generatedOverviewQ = 0;
  foreach ($availableQuarters as $yq) {
    $ms = quarterMonths($yq);
    $from = $ms[0] . "-01";
    $to   = date("Y-m-t", strtotime($ms[2] . "-01"));
    [$daysAll, $daysWeekday, $daysWeekend] = dayCountsRange($from, $to);

    $expectedHours = expectedHoursInMonth($ms[0]) + expectedHoursInMonth($ms[1]) + expectedHoursInMonth($ms[2]);

    $items = [];
    foreach ($counters as $id) {
      $sumAll=0; $sumWd=0; $sumWe=0; $hours=0;

      foreach ($ms as $m) {
        $a = $agg[$m][$id] ?? null;
        if (!$a) continue;
        $sumAll += (int)$a["sum_all"];
        $sumWd  += (int)$a["sum_weekday"];
        $sumWe  += (int)$a["sum_weekend"];
        $hours  += (int)$a["hours_present"];
      }

      $coverage = $expectedHours > 0 ? ($hours / $expectedHours) : 0.0;
      $isFull = ($coverage >= $FULL_COVERAGE_THRESHOLD);

      $avgDaily   = $daysAll > 0 ? (int)round($sumAll / $daysAll) : 0;
      $avgWeekday = $daysWeekday > 0 ? (int)round($sumWd / $daysWeekday) : 0;
      $avgWeekend = $daysWeekend > 0 ? (int)round($sumWe / $daysWeekend) : 0;

      $items[] = [
        "id" => $id,
        "name" => $nameById[$id] ?? $id,
        "period_sum" => $sumAll,
        "avg_daily" => $avgDaily,
        "avg_weekday" => $avgWeekday,
        "avg_weekend" => $avgWeekend,
        "coverage" => round($coverage, 4),
        "is_full" => $isFull,
        "hours_present" => (int)$hours,
        "hours_expected" => (int)$expectedHours
      ];
    }

    writeJsonPretty($permDir . "/overview_quarter_" . $yq . ".json", [
      "mode" => "quarter",
      "period" => $yq,
      "from" => $from,
      "to" => $to,
      "generated_at" => date("c"),
      "threshold_full" => $FULL_COVERAGE_THRESHOLD,
      "items" => $items
    ]);
    $generatedOverviewQ++;
  }
  logln("overview_quarter wygenerowane: " . $generatedOverviewQ);

  // ===== BASELINE QUARTER (Q1..Q4) =====
  $generatedBaselineQ = 0;
  for ($q=1; $q<=4; $q++) {
    $qKey = "Q" . $q;
    $items = [];

    foreach ($counters as $id) {
      $listDaily=[]; $listWeekday=[]; $listWeekend=[];

      foreach ($availableQuarters as $yq) {
        if (substr($yq, 5, 2) !== $qKey) continue;

        $ms = quarterMonths($yq);
        $from = $ms[0] . "-01";
        $to   = date("Y-m-t", strtotime($ms[2] . "-01"));
        [$daysAll, $daysWeekday, $daysWeekend] = dayCountsRange($from, $to);
        $expectedHours = expectedHoursInMonth($ms[0]) + expectedHoursInMonth($ms[1]) + expectedHoursInMonth($ms[2]);

        $sumAll=0; $sumWd=0; $sumWe=0; $hours=0;
        foreach ($ms as $m) {
          $a = $agg[$m][$id] ?? null;
          if (!$a) continue;
          $sumAll += (int)$a["sum_all"];
          $sumWd  += (int)$a["sum_weekday"];
          $sumWe  += (int)$a["sum_weekend"];
          $hours  += (int)$a["hours_present"];
        }

        $coverage = $expectedHours > 0 ? ($hours / $expectedHours) : 0.0;
        if ($coverage < $FULL_COVERAGE_THRESHOLD) continue;

        $listDaily[]   = $daysAll > 0 ? (int)round($sumAll / $daysAll) : 0;
        $listWeekday[] = $daysWeekday > 0 ? (int)round($sumWd / $daysWeekday) : 0;
        $listWeekend[] = $daysWeekend > 0 ? (int)round($sumWe / $daysWeekend) : 0;
      }

      $items[] = [
        "id" => $id,
        "name" => $nameById[$id] ?? $id,
        "median_daily" => medianInt($listDaily),
        "median_weekday" => medianInt($listWeekday),
        "median_weekend" => medianInt($listWeekend),
        "n_periods" => count($listDaily)
      ];
    }

    writeJsonPretty($permDir . "/baseline_median_quarter_" . $qKey . ".json", [
      "mode" => "quarter",
      "key" => $qKey,
      "generated_at" => date("c"),
      "threshold_full" => $FULL_COVERAGE_THRESHOLD,
      "items" => $items
    ]);
    $generatedBaselineQ++;
  }
  logln("baseline_median_quarter wygenerowane: " . $generatedBaselineQ);

  // ===== YEARS available (full years: Jan..Dec all months exist) =====
  $yearsSet = [];
  foreach ($availableMonths as $ym) $yearsSet[substr($ym,0,4)] = true;
  $years = array_keys($yearsSet); sort($years);

  $availableYears = [];
  foreach ($years as $y) {
    $ok = true;
    for ($m=1; $m<=12; $m++) {
      $ym = sprintf("%s-%02d", $y, $m);
      if (!in_array($ym, $availableMonths, true)) { $ok=false; break; }
    }
    if ($ok) $availableYears[] = $y;
  }
  $lastFullYear = count($availableYears) ? end($availableYears) : null;
  if ($lastFullYear) logln("Ostatni pełny rok: " . $lastFullYear);
    
// ===== DETAILS YEAR: daily + hourly profiles + weekday stats + best day =====
$generatedDetailsY = 0;

foreach ($availableYears as $y) {
  $from = $y . "-01-01";
  $to   = $y . "-12-31";

  $outCounters = [];

  foreach ($counters as $id) {
    $dailyMap = [];
    $hourMap = [];
    $presentMap = [];

    for ($m=1; $m<=12; $m++) {
      $ym = sprintf("%s-%02d", $y, $m);
      $d = $details[$ym][$id] ?? null;
      if (!$d) continue;

      foreach (($d["daily"] ?? []) as $day => $cnt) {
        if (!isset($dailyMap[$day])) $dailyMap[$day] = 0;
        $dailyMap[$day] += (int)$cnt;
      }
      foreach (($d["hour"] ?? []) as $day => $hours) {
        if (!isset($hourMap[$day])) $hourMap[$day] = [];
        foreach ($hours as $h => $cnt) {
          $h = (int)$h;
          if (!isset($hourMap[$day][$h])) $hourMap[$day][$h] = 0;
          $hourMap[$day][$h] += (int)$cnt;
        }
      }
      foreach (($d["hour_present"] ?? []) as $day => $hoursPresent) {
        if (!isset($presentMap[$day])) $presentMap[$day] = [];
        foreach ($hoursPresent as $h => $_true) {
          $presentMap[$day][(int)$h] = true;
        }
      }
    }

    if (!$dailyMap) {
      $outCounters[$id] = [
        "id" => $id,
        "name" => $nameById[$id] ?? $id,
        "total" => 0,
        "daily" => [],
        "hourly_weekday" => [],
        "hourly_weekend" => [],
        "weekday_stats" => array_map(fn($nm) => ["weekday"=>$nm,"avg"=>0,"median"=>0], $weekdayNamesPL),
        "best_day" => null
      ];
      continue;
    }

    ksort($dailyMap);
    $dailyArr = [];
    $total = 0;
    $bestDay = null;

    foreach ($dailyMap as $day => $cnt) {
      $cnt = (int)$cnt;
      $dailyArr[] = ["date" => $day, "count" => $cnt];
      $total += $cnt;

      if ($bestDay === null || $cnt > $bestDay["count"]) {
        $wd = $weekdayNamesPL[(int)date("N", strtotime($day)) - 1];
        $bestDay = ["date" => $day, "weekday" => $wd, "count" => $cnt];
      }
    }

    $hourlyWeekday = [];
    $hourlyWeekend = [];
    for ($h=0; $h<24; $h++) {
      $valsW = [];
      $valsE = [];

      foreach ($hourMap as $day => $hours) {
        if (empty($presentMap[$day][$h])) continue;

        $v = (int)($hours[$h] ?? 0);
        $dow = (int)date("N", strtotime($day));
        $isWeekend = ($dow >= 6);

        if ($isWeekend) $valsE[] = $v;
        else $valsW[] = $v;
      }

      $avgW = count($valsW) ? (int)round(array_sum($valsW) / count($valsW)) : 0;
      $avgE = count($valsE) ? (int)round(array_sum($valsE) / count($valsE)) : 0;

      $medW = medianInt($valsW); if ($medW === null) $medW = 0;
      $medE = medianInt($valsE); if ($medE === null) $medE = 0;
    $minW = count($valsW) ? min($valsW) : 0;
$maxW = count($valsW) ? max($valsW) : 0;

$minE = count($valsE) ? min($valsE) : 0;
$maxE = count($valsE) ? max($valsE) : 0;

      $hourlyWeekday[] = [
  "hour" => str_pad((string)$h, 2, "0", STR_PAD_LEFT),
  "avg" => $avgW,
  "median" => $medW,
  "min" => $minW,
  "max" => $maxW
];

$hourlyWeekend[] = [
  "hour" => str_pad((string)$h, 2, "0", STR_PAD_LEFT),
  "avg" => $avgE,
  "median" => $medE,
  "min" => $minE,
  "max" => $maxE
];
    }

    $valsByWeekday = [ "Mon"=>[], "Tue"=>[], "Wed"=>[], "Thu"=>[], "Fri"=>[], "Sat"=>[], "Sun"=>[] ];
    foreach ($dailyMap as $day => $cnt) {
      $key = date("D", strtotime($day));
      if (isset($valsByWeekday[$key])) $valsByWeekday[$key][] = (int)$cnt;
    }

    $weekdayStats = [];
    for ($i=0; $i<7; $i++) {
      $key = $weekdayOrder[$i];
      $vals = $valsByWeekday[$key] ?? [];
      $avg = count($vals) ? (int)round(array_sum($vals) / count($vals)) : 0;
      $med = medianInt($vals); if ($med === null) $med = 0;
      $weekdayStats[] = ["weekday" => $weekdayNamesPL[$i], "avg" => $avg, "median" => $med];
    }

    $outCounters[$id] = [
      "id" => $id,
      "name" => $nameById[$id] ?? $id,
      "total" => $total,
      "daily" => $dailyArr,
      "hourly_weekday" => $hourlyWeekday,
      "hourly_weekend" => $hourlyWeekend,
      "weekday_stats" => $weekdayStats,
      "best_day" => $bestDay
    ];
  }

  writeJsonPretty($permDir . "/details_year_" . $y . ".json", [
    "mode" => "year",
    "period" => $y,
    "from" => $from,
    "to" => $to,
    "generated_at" => date("c"),
    "counters" => $outCounters
  ]);

  $generatedDetailsY++;
}
logln("details_year wygenerowane: " . $generatedDetailsY);

  // ===== YEAR OVERVIEW =====
  $generatedOverviewY = 0;
  foreach ($availableYears as $y) {
    $from = $y . "-01-01";
    $to   = $y . "-12-31";
    [$daysAll, $daysWeekday, $daysWeekend] = dayCountsRange($from, $to);

    $expectedHours = 0;
    for ($m=1; $m<=12; $m++) $expectedHours += expectedHoursInMonth(sprintf("%s-%02d",$y,$m));

    $items = [];
    foreach ($counters as $id) {
      $sumAll=0; $sumWd=0; $sumWe=0; $hours=0;

      for ($m=1; $m<=12; $m++) {
        $ym = sprintf("%s-%02d", $y, $m);
        $a = $agg[$ym][$id] ?? null;
        if (!$a) continue;
        $sumAll += (int)$a["sum_all"];
        $sumWd  += (int)$a["sum_weekday"];
        $sumWe  += (int)$a["sum_weekend"];
        $hours  += (int)$a["hours_present"];
      }

      $coverage = $expectedHours > 0 ? ($hours / $expectedHours) : 0.0;
      $isFull = ($coverage >= $FULL_COVERAGE_THRESHOLD);

      $avgDaily   = $daysAll > 0 ? (int)round($sumAll / $daysAll) : 0;
      $avgWeekday = $daysWeekday > 0 ? (int)round($sumWd / $daysWeekday) : 0;
      $avgWeekend = $daysWeekend > 0 ? (int)round($sumWe / $daysWeekend) : 0;

      $items[] = [
        "id" => $id,
        "name" => $nameById[$id] ?? $id,
        "period_sum" => $sumAll,
        "avg_daily" => $avgDaily,
        "avg_weekday" => $avgWeekday,
        "avg_weekend" => $avgWeekend,
        "coverage" => round($coverage, 4),
        "is_full" => $isFull,
        "hours_present" => (int)$hours,
        "hours_expected" => (int)$expectedHours
      ];
    }

    writeJsonPretty($permDir . "/overview_year_" . $y . ".json", [
      "mode" => "year",
      "period" => $y,
      "from" => $from,
      "to" => $to,
      "generated_at" => date("c"),
      "threshold_full" => $FULL_COVERAGE_THRESHOLD,
      "items" => $items
    ]);
    $generatedOverviewY++;
  }
  logln("overview_year wygenerowane: " . $generatedOverviewY);

  // ===== BASELINE YEAR (median over all full years) =====
  $items = [];
  foreach ($counters as $id) {
    $listDaily=[]; $listWeekday=[]; $listWeekend=[];
    foreach ($availableYears as $y) {
      $from = $y . "-01-01";
      $to   = $y . "-12-31";
      [$daysAll, $daysWeekday, $daysWeekend] = dayCountsRange($from, $to);

      $expectedHours = 0;
      for ($m=1; $m<=12; $m++) $expectedHours += expectedHoursInMonth(sprintf("%s-%02d",$y,$m));

      $sumAll=0; $sumWd=0; $sumWe=0; $hours=0;
      for ($m=1; $m<=12; $m++) {
        $ym = sprintf("%s-%02d", $y, $m);
        $a = $agg[$ym][$id] ?? null;
        if (!$a) continue;
        $sumAll += (int)$a["sum_all"];
        $sumWd  += (int)$a["sum_weekday"];
        $sumWe  += (int)$a["sum_weekend"];
        $hours  += (int)$a["hours_present"];
      }
      $coverage = $expectedHours > 0 ? ($hours / $expectedHours) : 0.0;
      if ($coverage < $FULL_COVERAGE_THRESHOLD) continue;

      $listDaily[]   = $daysAll > 0 ? (int)round($sumAll / $daysAll) : 0;
      $listWeekday[] = $daysWeekday > 0 ? (int)round($sumWd / $daysWeekday) : 0;
      $listWeekend[] = $daysWeekend > 0 ? (int)round($sumWe / $daysWeekend) : 0;
    }

    $items[] = [
      "id" => $id,
      "name" => $nameById[$id] ?? $id,
      "median_daily" => medianInt($listDaily),
      "median_weekday" => medianInt($listWeekday),
      "median_weekend" => medianInt($listWeekend),
      "n_periods" => count($listDaily)
    ];
  }

  writeJsonPretty($permDir . "/baseline_median_year.json", [
    "mode" => "year",
    "key" => "ALL",
    "generated_at" => date("c"),
    "threshold_full" => $FULL_COVERAGE_THRESHOLD,
    "items" => $items
  ]);
  logln("baseline_median_year zapisane.");

  // ===== META =====
  $meta = [
    "generated_at" => date("c"),
    "threshold_full" => $FULL_COVERAGE_THRESHOLD,

    "months" => $availableMonths,
    "quarters" => $availableQuarters,
    "years" => $availableYears,

    "last_full_month" => $lastFullMonth,
    "last_full_quarter" => $lastFullQuarter,
    "last_full_year" => $lastFullYear,

    "min_month_in_data" => $minMonth,
    "max_month_in_data_raw" => $maxMonth
  ];
  writeJsonPretty($permDir . "/meta_periods.json", $meta);
  logln("meta_periods.json zapisane.");

  $t1 = microtime(true);
  logln("OK. Czas: " . number_format($t1 - $t0, 2) . " s");

} catch (Throwable $e) {
  echo "<div class='alert alert-danger'><strong>Błąd:</strong> " . htmlspecialchars($e->getMessage()) . "</div>";
}
?>
      <div class="mt-3">
        <a class="btn btn-outline-secondary" href="./">Wróć do panelu</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>