<?php
declare(strict_types=1);

date_default_timezone_set("Europe/Warsaw");
set_time_limit(0);

$CSV_URL = "https://docs.google.com/spreadsheets/d/e/2PACX-1vR6Y2bE2n2rWeIwFoA-YPyVuFa9BOjEFdWfTIyph-1PcyeaaRacmM_4-Q_QdYo1dOrAldVSy4hi334J/pub?gid=1137251897&single=true&output=csv";

// ===== METODOLOGIA (zatwierdzona przez Fundację) =====
// Próg kompletności okresu: licznik jest pokazywany, gdy ma >= 95% oczekiwanych godzin.
$FULL_COVERAGE_THRESHOLD = 0.95;
// Dzień wchodzi do średnich (dzienna / powszednia / weekendowa), gdy ma >= 20 godzin z danymi.
$MIN_HOURS_VALID_DAY = 20;
// Seria >= 48 kolejnych godzin zer w liczniku całodobowym (Eco-Counter) to awaria, nie ruch:
// godziny z serii są traktowane jako BRAK DANYCH. Telraam (zera w nocy są normalne) jest pomijany.
$ZERO_RUN_MIN_HOURS = 48;

$CSV_CACHE_TTL_SECONDS = 6 * 3600;
// Liczniki optyczne, jeśli GeoJSON nie ma jeszcze pola properties.sensor_type.
$FALLBACK_TELRAAM_IDS = ["chrobrego", "czarnowiejska", "pl_wolnica", "wielopole"];

$root = realpath(__DIR__ . "/..");
$cacheDir = $root . "/cache";
$permDir  = $cacheDir . "/permanent";
$csvCache = $cacheDir . "/data.csv";
$geoPath  = $root . "/data/counters.geojson";
$exclPath = $root . "/data/data_exclusions.json";

$force = isset($_POST["force"]) && $_POST["force"] === "1";
$DT_RE = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/';

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
// Zapis atomowy: przeglądarka nigdy nie pobierze niedokończonego pliku.
function writeAtomic(string $path, string $content): void {
  $tmp = $path . ".tmp." . getmypid();
  if (@file_put_contents($tmp, $content) === false) {
    throw new RuntimeException("Nie mogę zapisać: $path");
  }
  if (!@rename($tmp, $path)) {
    @unlink($tmp);
    throw new RuntimeException("Nie mogę podmienić pliku: $path");
  }
}
function writeJsonPretty(string $path, array $data): void {
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException("json_encode failed for $path");
  writeAtomic($path, $json);
}
function countLines(string $path): int {
  $n = 0;
  $fh = @fopen($path, "r");
  if (!$fh) return 0;
  while (!feof($fh)) { $chunk = fread($fh, 1 << 20); if ($chunk === false) break; $n += substr_count($chunk, "\n"); }
  fclose($fh);
  return $n;
}
// Odrzuca pobrany plik, który nie jest CSV z danymi (np. strona błędu Google) lub jest wyraźnie krótszy od poprzedniego.
function validateCsvText(string $csv, string $oldPath): void {
  if (strlen($csv) < 1000) throw new RuntimeException("Pobrany plik jest zbyt krótki.");
  $text = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
  $nl = strpos($text, "\n");
  $first = $nl === false ? $text : substr($text, 0, $nl);
  $header = array_map('trim', str_getcsv(trim($first)));
  if (count($header) < 3 || !in_array("date", $header, true)) {
    throw new RuntimeException("Pobrany plik nie wygląda jak CSV z danymi (brak kolumny date).");
  }
  if (is_file($oldPath)) {
    $oldLines = countLines($oldPath);
    $newLines = substr_count($csv, "\n");
    if ($oldLines > 0 && $newLines < 0.95 * $oldLines) {
      throw new RuntimeException("Nowy plik ma wyraźnie mniej wierszy ($newLines) niż poprzedni ($oldLines).");
    }
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
  $dt = DateTime::createFromFormat("Y-m-d", $ym . "-01");
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
function dowOf(string $day): int {
  static $cache = [];
  if (!isset($cache[$day])) $cache[$day] = (int)date("N", strtotime($day . " 12:00:00"));
  return $cache[$day];
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
function emptyAgg(): array {
  return [
    "sum_all" => 0, "sum_weekday" => 0, "sum_weekend" => 0, "hours_present" => 0, "hours_excluded" => 0,
    "vd_sum_all" => 0, "vd_n_all" => 0, "vd_sum_wd" => 0, "vd_n_wd" => 0, "vd_sum_we" => 0, "vd_n_we" => 0,
  ];
}
function avgOf(int $sum, int $n): int {
  return $n > 0 ? (int)round($sum / $n) : 0;
}
// Szczegóły licznika z jednego lub kilku miesięcy.
// $m: daily[dzień]=suma, hsum[dzień][godz]=suma, hdup[dzień][godz]=liczba wpisów (dla zdublowanej godz. 02:00), gaps[dzień]=true
function mergeCounterMonths(array $details, array $months, string $id): array {
  $out = ["daily" => [], "hsum" => [], "hdup" => [], "gaps" => []];
  foreach ($months as $ym) {
    $d = $details[$ym][$id] ?? null;
    if (!$d) continue;
    foreach ($d["daily"] as $day => $v) $out["daily"][$day] = $v;
    foreach ($d["hsum"] as $day => $v) $out["hsum"][$day] = $v;
    foreach ($d["hdup"] as $day => $v) $out["hdup"][$day] = $v;
    foreach ($d["gaps"] as $day => $v) $out["gaps"][$day] = $v;
  }
  return $out;
}
function buildCounterDetails(string $id, string $name, array $m, int $minHours, array $weekdayNamesPL): array {
  $days = array_unique(array_merge(array_keys($m["daily"]), array_keys($m["gaps"])));
  sort($days);

  if (!$days) {
    return [
      "id" => $id, "name" => $name, "total" => 0, "daily" => [],
      "hourly_weekday" => [], "hourly_weekend" => [],
      "weekday_stats" => array_map(fn($nm) => ["weekday"=>$nm,"avg"=>0,"median"=>0], $weekdayNamesPL),
      "best_day" => null
    ];
  }

  $dailyArr = [];
  $total = 0;
  $bestDay = null;
  $valsByDow = [1=>[],2=>[],3=>[],4=>[],5=>[],6=>[],7=>[]];

  foreach ($days as $day) {
    if (isset($m["daily"][$day])) {
      $cnt = (int)$m["daily"][$day];
      $hrs = isset($m["hsum"][$day]) ? count($m["hsum"][$day]) : 0;
      $dailyArr[] = ["date" => $day, "count" => $cnt, "hours" => $hrs];
      $total += $cnt;
      if ($hrs >= $minHours) {
        $dow = dowOf($day);
        $valsByDow[$dow][] = $cnt;
        if ($bestDay === null || $cnt > $bestDay["count"]) {
          $bestDay = ["date" => $day, "weekday" => $weekdayNamesPL[$dow - 1], "count" => $cnt];
        }
      }
    } else {
      // dzień w całości wyłączony (seria zer / ręczne wyłączenie): jawna przerwa, nie zero
      $dailyArr[] = ["date" => $day, "count" => null, "hours" => 0];
    }
  }

  $hourlyWeekday = [];
  $hourlyWeekend = [];
  for ($h = 0; $h < 24; $h++) {
    $valsW = []; $valsE = [];
    foreach ($m["hsum"] as $day => $hours) {
      if (!isset($hours[$h])) continue;
      $v = (int)$hours[$h];
      $dup = (int)($m["hdup"][$day][$h] ?? 1);
      if ($dup > 1) $v = (int)round($v / $dup); // zdublowana godz. 02:00 (zmiana czasu): średnia, nie suma
      if (dowOf($day) >= 6) $valsE[] = $v; else $valsW[] = $v;
    }
    $hh = str_pad((string)$h, 2, "0", STR_PAD_LEFT);
    $hourlyWeekday[] = [
      "hour" => $hh,
      "avg" => count($valsW) ? (int)round(array_sum($valsW) / count($valsW)) : 0,
      "median" => medianInt($valsW) ?? 0,
      "min" => count($valsW) ? min($valsW) : 0,
      "max" => count($valsW) ? max($valsW) : 0
    ];
    $hourlyWeekend[] = [
      "hour" => $hh,
      "avg" => count($valsE) ? (int)round(array_sum($valsE) / count($valsE)) : 0,
      "median" => medianInt($valsE) ?? 0,
      "min" => count($valsE) ? min($valsE) : 0,
      "max" => count($valsE) ? max($valsE) : 0
    ];
  }

  $weekdayStats = [];
  for ($i = 1; $i <= 7; $i++) {
    $vals = $valsByDow[$i];
    $weekdayStats[] = [
      "weekday" => $weekdayNamesPL[$i - 1],
      "avg" => count($vals) ? (int)round(array_sum($vals) / count($vals)) : 0,
      "median" => medianInt($vals) ?? 0
    ];
  }

  return [
    "id" => $id, "name" => $name, "total" => $total, "daily" => $dailyArr,
    "hourly_weekday" => $hourlyWeekday, "hourly_weekend" => $hourlyWeekend,
    "weekday_stats" => $weekdayStats, "best_day" => $bestDay
  ];
}
// Pozycja overview z zagregowanych miesięcy ($aggs = lista emptyAgg()-podobnych tablic).
function buildOverviewItem(string $id, string $name, array $aggs, int $expectedHours, float $threshold, string $sensorType = "eco_counter"): array {
  $t = emptyAgg();
  foreach ($aggs as $a) foreach ($t as $k => $_) $t[$k] += (int)($a[$k] ?? 0);

  $coverage = $expectedHours > 0 ? min(1.0, $t["hours_present"] / $expectedHours) : 0.0;
  return [
    "id" => $id,
    "name" => $name,
    "sensor_type" => $sensorType,   // "eco_counter" | "telraam" – statystyki sieci liczone tylko z Eco-Counter
    "month_sum" => $t["sum_all"],   // kompatybilność
    "period_sum" => $t["sum_all"],  // suma wszystkich zarejestrowanych godzin (bez wyłączonych serii zer)
    "avg_daily" => avgOf($t["vd_sum_all"], $t["vd_n_all"]),
    "avg_weekday" => avgOf($t["vd_sum_wd"], $t["vd_n_wd"]),
    "avg_weekend" => avgOf($t["vd_sum_we"], $t["vd_n_we"]),
    "days_valid" => $t["vd_n_all"],
    "coverage" => round($coverage, 4),
    "is_full" => ($coverage >= $threshold),
    "hours_present" => $t["hours_present"],
    "hours_expected" => $expectedHours,
    "hours_excluded" => $t["hours_excluded"]
  ];
}

// ===== Wykrywanie anomalii (flagi do przeglądu; NIE zmieniają liczb poza istniejącymi wyłączeniami serii zer) =====
// Zwraca listę flag: zera w ciągu dnia (6–47 h), serie >= $zeroMin h (potwierdzenie przez inne liczniki), zaklinowane wartości.
function detectAnomalies(string $csvPath, array $counters, array $counterIdx, array $isTelraam, int $idxDate, string $dtRe, int $zeroMin): array {
  $fh = fopen($csvPath, "r");
  if (!$fh) return [];
  fgetcsv($fh);
  $eco = array_values(array_filter($counters, fn($id) => empty($isTelraam[$id])));
  $actN = []; $presN = []; $dts = [];
  $zrun = array_fill_keys($eco, 0); $zStart = array_fill_keys($eco, 0);
  $srun = array_fill_keys($eco, 0); $sStart = array_fill_keys($eco, 0); $sVal = array_fill_keys($eco, null);
  $hs = []; $hn = [];
  foreach ($eco as $id) { $hs[$id] = array_fill(0, 24, 0); $hn[$id] = array_fill(0, 24, 0); }
  $cand = []; $stuck = [];
  $n = -1;

  $closeZ = function (string $id, int $endRow) use (&$zrun, &$zStart, &$cand, $zeroMin) {
    $len = $zrun[$id];
    if ($len >= 6) $cand[] = [$id, $zStart[$id], $endRow, $len];
    $zrun[$id] = 0;
  };
  $closeS = function (string $id, int $endRow) use (&$srun, &$sStart, &$sVal, &$stuck) {
    if ($srun[$id] >= 8 && $sVal[$id] !== null && $sVal[$id] >= 10) $stuck[] = [$id, $sStart[$id], $endRow, $srun[$id], $sVal[$id]];
    $srun[$id] = 0; $sVal[$id] = null;
  };

  while (($row = fgetcsv($fh)) !== false) {
    $dt = trim((string)($row[$idxDate] ?? ""));
    if (!preg_match($dtRe, $dt)) continue;
    $n++;
    $dts[$n] = $dt;
    $h = (int)substr($dt, 11, 2);
    $act = 0; $pres = 0; $vals = [];
    foreach ($eco as $id) {
      $vs = trim((string)($row[$counterIdx[$id]] ?? ""));
      if ($vs === "") { $vals[$id] = null; continue; }
      $v = (int)preg_replace('/[^0-9]/', '', $vs);
      $vals[$id] = $v; $pres++; if ($v > 0) $act++;
      $hs[$id][$h] += $v; $hn[$id][$h]++;
    }
    $actN[$n] = $act; $presN[$n] = $pres;
    foreach ($eco as $id) {
      $v = $vals[$id];
      if ($v === 0) { if ($zrun[$id] === 0) $zStart[$id] = $n; $zrun[$id]++; }
      else { if ($zrun[$id] > 0) $closeZ($id, $n - 1); }
      if ($v !== null && $v > 0 && $v === $sVal[$id]) { $srun[$id]++; }
      else { if ($srun[$id] > 0) $closeS($id, $n - 1); if ($v !== null && $v > 0) { $sVal[$id] = $v; $srun[$id] = 1; $sStart[$id] = $n; } }
    }
  }
  fclose($fh);
  foreach ($eco as $id) { if ($zrun[$id] > 0) $closeZ($id, $n); if ($srun[$id] > 0) $closeS($id, $n); }

  $corro = function (string $id, int $a, int $b) use (&$actN, &$presN) {
    $sum = 0.0; $m = 0;
    for ($i = $a; $i <= $b; $i++) {
      $others = $presN[$i] - 1;   // ten licznik ma w serii zer wpis (0), więc jest w presN
      if ($others < 3) continue;
      $sum += $actN[$i] / $others; $m++;
    }
    return $m > 0 ? $sum / $m : null;
  };
  $sev = function (?float $c): string { return $c === null ? "niska" : ($c >= 0.8 ? "wysoka" : ($c >= 0.5 ? "średnia" : "niska")); };

  $flags = [];
  foreach ($cand as [$id, $a, $b, $len]) {
    $day = 0; $expSum = 0.0; $expN = 0;
    for ($i = $a; $i <= $b; $i++) {
      $h = (int)substr($dts[$i], 11, 2);
      if ($h >= 7 && $h <= 20) {
        $day++;
        if ($hn[$id][$h] > 0) { $expSum += $hs[$id][$h] / $hn[$id][$h]; $expN++; }
      }
    }
    $exp = $expN > 0 ? $expSum / $expN : 0.0;
    $c = $corro($id, $a, $b);
    if ($len >= $zeroMin) {
      $flags[] = ["counter" => $id, "type" => "seria_zer_wylaczona", "from" => $dts[$a], "to" => $dts[$b], "hours" => $len,
        "corroboration" => $c === null ? null : round($c, 2), "severity" => $sev($c),
        "note" => "≥ {$zeroMin} h samych zer; godziny pominięte w statystykach. Inne liczniki w tym czasie miały ruch w " . ($c === null ? "?" : round($c * 100) . "%") . " godzin."];
    } elseif ($day >= 4 && $exp >= 3.0) {
      $flags[] = ["counter" => $id, "type" => "zera_w_dzien", "from" => $dts[$a], "to" => $dts[$b], "hours" => $len,
        "expected_per_hour" => round($exp, 1), "corroboration" => $c === null ? null : round($c, 2), "severity" => $sev($c),
        "note" => "Seria zer w godzinach dziennych, mimo że w tych godzinach licznik zwykle notuje ok. " . round($exp, 1) . " przejazdów/godz. Dane zostają w statystykach; do przeglądu."];
    }
  }
  foreach ($stuck as [$id, $a, $b, $len, $val]) {
    $flags[] = ["counter" => $id, "type" => "zaklinowana_wartosc", "from" => $dts[$a], "to" => $dts[$b], "hours" => $len,
      "value" => $val, "severity" => "niska",
      "note" => "Ta sama wartość ({$val}) przez {$len} kolejnych godzin. Dane zostają w statystykach; do przeglądu."];
  }
  usort($flags, fn($x, $y) => [$x["counter"], $x["from"]] <=> [$y["counter"], $y["from"]]);
  return $flags;
}

// Zmiany poziomu: miesięczna średnia dzienna licznika względem mediany sieci Eco, porównana z tym samym miesiącem kalendarzowym w poprzednich latach.
function detectLevelShifts(array $monthVals, array $isTelraam): array {
  $byMonth = [];
  foreach ($monthVals as $id => $per) {
    if (!empty($isTelraam[$id])) continue;
    foreach ($per as $ym => $v) { if ($v[0] !== null && $v[0] > 0) $byMonth[$ym][$id] = (float)$v[0]; }
  }
  $med = function (array $a): ?float { $a = array_values($a); sort($a); $n = count($a); if (!$n) return null; return $n % 2 ? $a[intdiv($n, 2)] : ($a[$n/2 - 1] + $a[$n/2]) / 2; };
  $netMed = []; foreach ($byMonth as $ym => $vals) { if (count($vals) >= 5) $netMed[$ym] = $med($vals); }
  $R = [];
  foreach ($byMonth as $ym => $vals) { if (!isset($netMed[$ym])) continue; foreach ($vals as $id => $v) $R[$id][$ym] = $v / $netMed[$ym]; }
  $flags = [];
  foreach ($R as $id => $series) {
    ksort($series);
    $yms = array_keys($series);
    foreach ($yms as $ym) {
      $mm = substr($ym, 5, 2); $yy = (int)substr($ym, 0, 4);
      $prev = [];
      for ($k = 1; $k <= 3; $k++) { $pk = ($yy - $k) . "-" . $mm; if (isset($series[$pk])) $prev[] = $series[$pk]; }
      if (count($prev) < 2) continue;
      $base = $med($prev);
      $dev = $series[$ym] / $base - 1;
      if (abs($dev) < 0.40) continue;
      $next = date("Y-m", strtotime($ym . "-01 +1 month"));
      if (isset($series[$next]) && $series[$next] / $base - 1 !== 0.0 && (($series[$next] / $base - 1) * $dev) <= 0) continue; // brak utrzymania zmiany
      $flags[] = ["counter" => $id, "type" => "zmiana_poziomu", "from" => $ym, "to" => $ym, "hours" => null,
        "deviation_pct" => round($dev * 100), "severity" => "niska",
        "note" => "Średnia dzienna względem mediany sieci różni się o " . round($dev * 100) . "% od typowej dla tego miesiąca w poprzednich latach (możliwa zmiana lokalizacji, kalibracji lub realna zmiana ruchu)."];
    }
  }
  usort($flags, fn($x, $y) => [$x["counter"], $x["from"]] <=> [$y["counter"], $y["from"]]);
  // kolejne miesiące tego samego licznika i znaku odchylenia łączymy w jeden przedział
  $merged = [];
  foreach ($flags as $f) {
    $last = count($merged) - 1;
    if ($last >= 0 && $merged[$last]["counter"] === $f["counter"]
        && date("Y-m", strtotime($merged[$last]["to"] . "-01 +1 month")) === $f["from"]
        && ($merged[$last]["deviation_pct"] * $f["deviation_pct"]) > 0) {
      $merged[$last]["to"] = $f["to"];
      if (abs($f["deviation_pct"]) > abs($merged[$last]["deviation_pct"])) $merged[$last]["deviation_pct"] = $f["deviation_pct"];
    } else {
      $merged[] = $f;
    }
  }
  foreach ($merged as &$m) {
    $m["note"] = "Średnia dzienna względem mediany sieci Eco różni się o " . $m["deviation_pct"] . "% (maks. w przedziale) od typowej dla tych miesięcy w poprzednich latach. Możliwa zmiana lokalizacji, kalibracji lub realna zmiana ruchu (np. roboty drogowe, pandemia).";
  }
  unset($m);
  return $merged;
}
function loadExclusions(string $path): array {
  if (!is_file($path)) return [];
  $data = readJsonFile($path);
  $list = $data["exclusions"] ?? [];
  $out = [];
  foreach ($list as $e) {
    $id = (string)($e["counter"] ?? "");
    $from = trim((string)($e["from"] ?? ""));
    $to = trim((string)($e["to"] ?? ""));
    if ($id === "" || $from === "" || $to === "") continue;
    if (strlen($from) === 10) $from .= " 00:00";
    if (strlen($to) === 10) $to .= " 23:59";
    $out[] = ["counter" => $id, "from" => $from, "to" => $to, "reason" => (string)($e["reason"] ?? "")];
  }
  return $out;
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

  // GeoJSON id -> name, typ czujnika
  if (!file_exists($geoPath)) throw new RuntimeException("Brak GeoJSON: $geoPath");
  $geo = readJsonFile($geoPath);
  $nameById = [];
  $isTelraam = [];
  foreach (($geo["features"] ?? []) as $f) {
    $id = $f["properties"]["id"] ?? null;
    $nm = $f["properties"]["name"] ?? null;
    if (!$id) continue;
    $nameById[$id] = $nm ?: $id;
    $st = $f["properties"]["sensor_type"] ?? null;
    $isTelraam[$id] = $st !== null
      ? (stripos((string)$st, "telraam") !== false)
      : in_array($id, $FALLBACK_TELRAAM_IDS, true);
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
    try {
      if ($csv === false) throw new RuntimeException("Nie udało się pobrać CSV.");
      validateCsvText($csv, $csvCache);
      writeAtomic($csvCache, $csv);
      logln("CSV cache zapisany: " . number_format(strlen($csv)/1024/1024, 2) . " MB");
    } catch (RuntimeException $e) {
      if (!file_exists($csvCache)) throw $e;
      logln("UWAGA: " . $e->getMessage() . " Używam poprzedniego CSV z cache.");
    }
    unset($csv);
  } else {
    logln("Używam CSV z cache (TTL OK).");
  }

  // ===== Nagłówek i lista liczników (tylko te z GeoJSON; bez date_utc_key i kolumn bez opisu) =====
  $fh = fopen($csvCache, "r");
  if (!$fh) throw new RuntimeException("Nie mogę otworzyć CSV cache.");
  $header = fgetcsv($fh);
  if (!$header) throw new RuntimeException("CSV nie ma nagłówka.");
  $header = array_map(fn($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$h)), $header);

  $idxDate = array_search("date", $header, true);
  if ($idxDate === false) throw new RuntimeException("Brak kolumny date.");

  $counters = [];
  $counterIdx = [];
  $skippedCols = [];
  for ($i=0; $i<count($header); $i++) {
    if ($i === $idxDate) continue;
    $id = $header[$i];
    if ($id === "") continue;
    if (!isset($nameById[$id])) { $skippedCols[] = $id; continue; }
    $counters[] = $id;
    $counterIdx[$id] = $i;
  }
  logln("Liczników: " . count($counters) . ($skippedCols ? " (pominięte kolumny bez wpisu w GeoJSON: " . implode(", ", $skippedCols) . ")" : ""));

  // ===== PRZEBIEG 1: serie zer (Eco-Counter) i ręczne wyłączenia =====
  $manual = loadExclusions($exclPath);
  $ranges = array_fill_keys($counters, []);   // id => [[startRow,endRow,fromDt,toDt,hours,source,reason], ...]
  $runLen = array_fill_keys($counters, 0);
  $runStartRow = []; $runStartDt = []; $runLastRow = []; $runLastDt = [];
  $manualHit = [];
  $rowNo = -1;
  $invalidRows = 0;

  $closeRun = function (string $id) use (&$runLen, &$runStartRow, &$runStartDt, &$runLastRow, &$runLastDt, &$ranges, $ZERO_RUN_MIN_HOURS) {
    if ($runLen[$id] >= $ZERO_RUN_MIN_HOURS) {
      $ranges[$id][] = [$runStartRow[$id], $runLastRow[$id], $runStartDt[$id], $runLastDt[$id], $runLen[$id], "auto", "seria zer >= {$ZERO_RUN_MIN_HOURS} h"];
    }
    $runLen[$id] = 0;
  };

  while (($row = fgetcsv($fh)) !== false) {
    $rowNo++;
    $dt = trim((string)($row[$idxDate] ?? ""));
    if (!preg_match($DT_RE, $dt)) { if ($dt !== "") $invalidRows++; continue; }

    foreach ($manual as $mi => $ex) {
      if ($dt >= $ex["from"] && $dt <= $ex["to"]) {
        if (!isset($manualHit[$mi])) $manualHit[$mi] = [$rowNo, $rowNo, $dt, $dt];
        else { $manualHit[$mi][1] = $rowNo; $manualHit[$mi][3] = $dt; }
      }
    }

    foreach ($counters as $id) {
      if ($isTelraam[$id]) continue;
      $vs = trim((string)($row[$counterIdx[$id]] ?? ""));
      if ($vs !== "" && (int)preg_replace('/[^0-9]/', '', $vs) === 0) {
        if ($runLen[$id] === 0) { $runStartRow[$id] = $rowNo; $runStartDt[$id] = $dt; }
        $runLen[$id]++;
        $runLastRow[$id] = $rowNo; $runLastDt[$id] = $dt;
      } else {
        $closeRun($id);
      }
    }
  }
  fclose($fh);
  foreach ($counters as $id) if (!$isTelraam[$id]) $closeRun($id);

  foreach ($manual as $mi => $ex) {
    if (!isset($manualHit[$mi]) || !isset($ranges[$ex["counter"]])) continue;
    [$s, $e, $fdt, $tdt] = $manualHit[$mi];
    $ranges[$ex["counter"]][] = [$s, $e, $fdt, $tdt, $e - $s + 1, "manual", $ex["reason"]];
  }

  // lista do raportu + scalone zakresy wierszy do szybkiego sprawdzania w przebiegu 2
  $qualityRanges = [];
  $excl = [];
  foreach ($counters as $id) {
    $list = $ranges[$id];
    usort($list, fn($a, $b) => $a[0] <=> $b[0]);
    foreach ($list as $r) {
      $qualityRanges[] = ["counter" => $id, "from" => $r[2], "to" => $r[3], "hours" => $r[4], "source" => $r[5], "reason" => $r[6]];
    }
    $merged = [];
    foreach ($list as $r) {
      if ($merged && $r[0] <= $merged[count($merged)-1][1] + 0) {
        $merged[count($merged)-1][1] = max($merged[count($merged)-1][1], $r[1]);
      } else {
        $merged[] = [$r[0], $r[1]];
      }
    }
    $excl[$id] = $merged;
  }
  logln("Wykryte serie zer / wyłączenia: " . count($qualityRanges));
  $anomalyFlags = detectAnomalies($csvCache, $counters, $counterIdx, $isTelraam, $idxDate, $DT_RE, $ZERO_RUN_MIN_HOURS);
  logln("Flagi anomalii (zera w dzień, serie zer, zaklinowane wartości): " . count($anomalyFlags));

  // ===== PRZEBIEG 2: agregacja =====
  $fh = fopen($csvCache, "r");
  fgetcsv($fh);
  $agg = [];
  $details = [];   // details[ym][id] = ["daily"=>[], "hsum"=>[], "hdup"=>[], "gaps"=>[]]
  $ptr = array_fill_keys($counters, 0);
  $minMonth = null; $maxMonth = null;
  $rows = 0;
  $rowNo = -1;

  while (($row = fgetcsv($fh)) !== false) {
    $rowNo++;
    $dt = trim((string)($row[$idxDate] ?? ""));
    if (!preg_match($DT_RE, $dt)) continue;
    $rows++;

    $day = substr($dt, 0, 10);
    $ym  = substr($dt, 0, 7);
    $h   = (int)substr($dt, 11, 2);
    $isWeekend = (dowOf($day) >= 6);

    if ($minMonth === null || $ym < $minMonth) $minMonth = $ym;
    if ($maxMonth === null || $ym > $maxMonth) $maxMonth = $ym;
    if (!isset($agg[$ym])) $agg[$ym] = [];

    foreach ($counters as $id) {
      $valStr = trim((string)($row[$counterIdx[$id]] ?? ""));
      if ($valStr === "") continue; // brak danych; "0" to obecna godzina z wartością zero

      // czy ten wiersz należy do serii zer / wyłączenia?
      $rg = $excl[$id];
      $p = $ptr[$id];
      while ($p < count($rg) && $rg[$p][1] < $rowNo) $p++;
      $ptr[$id] = $p;
      $isExcluded = ($p < count($rg) && $rg[$p][0] <= $rowNo);

      if (!isset($agg[$ym][$id])) $agg[$ym][$id] = emptyAgg();
      if (!isset($details[$ym][$id])) $details[$ym][$id] = ["daily" => [], "hsum" => [], "hdup" => [], "gaps" => []];

      if ($isExcluded) {
        $agg[$ym][$id]["hours_excluded"]++;
        $details[$ym][$id]["gaps"][$day] = true;
        continue;
      }

      $val = (int)preg_replace('/[^0-9]/', '', $valStr);

      $A = &$agg[$ym][$id];
      $D = &$details[$ym][$id];

      // godzina zdublowana przy zmianie czasu jesienią (dwa wiersze 02:00): liczymy jako jedną godzinę obecności
      if (isset($D["hsum"][$day][$h])) {
        $D["hsum"][$day][$h] += $val;
        $D["hdup"][$day][$h] = ($D["hdup"][$day][$h] ?? 1) + 1;
      } else {
        $D["hsum"][$day][$h] = $val;
        $A["hours_present"]++;
      }
      $D["daily"][$day] = ($D["daily"][$day] ?? 0) + $val;

      $A["sum_all"] += $val;
      if ($isWeekend) $A["sum_weekend"] += $val; else $A["sum_weekday"] += $val;
      unset($A, $D);
    }
  }
  fclose($fh);

  logln("Wierszy: " . number_format($rows, 0, "", " ") . ($invalidRows ? " (pominięto niepoprawnych: $invalidRows)" : ""));
  logln("Miesiące w danych: " . ($minMonth ?? "—") . " .. " . ($maxMonth ?? "—"));

  // dni ważne do średnich (>= MIN_HOURS_VALID_DAY godzin z danymi)
  foreach ($details as $ym => $byId) {
    foreach ($byId as $id => $d) {
      $A = &$agg[$ym][$id];
      foreach ($d["daily"] as $day => $sum) {
        $hrs = isset($d["hsum"][$day]) ? count($d["hsum"][$day]) : 0;
        if ($hrs < $MIN_HOURS_VALID_DAY) continue;
        $A["vd_sum_all"] += $sum; $A["vd_n_all"]++;
        if (dowOf($day) >= 6) { $A["vd_sum_we"] += $sum; $A["vd_n_we"]++; }
        else { $A["vd_sum_wd"] += $sum; $A["vd_n_wd"]++; }
      }
      unset($A);
    }
  }

  // last full month = previous calendar month
  $firstThisMonth = strtotime(date("Y-m-01 00:00:00"));
  $lastFullMonth = date("Y-m", strtotime("-1 month", $firstThisMonth));
  logln("Ostatni pełny miesiąc: " . $lastFullMonth);

  $months = array_keys($agg);
  sort($months);

  $weekdayNamesPL = ["Pon","Wto","Śro","Czw","Pią","Sob","Nie"];

  // ===== MONTH OVERVIEW + DETAILS =====
  $generatedOverviewM = 0; $generatedDetailsM = 0;
  $availableMonths = [];

  foreach ($months as $ym) {
    if ($ym > $lastFullMonth) continue;
    $availableMonths[] = $ym;

    $expectedHours = expectedHoursInMonth($ym);
    $from = $ym . "-01";
    $to   = date("Y-m-t", strtotime($from));

    $items = [];
    foreach ($counters as $id) {
      $items[] = buildOverviewItem($id, $nameById[$id] ?? $id, [$agg[$ym][$id] ?? emptyAgg()], $expectedHours, $FULL_COVERAGE_THRESHOLD, $isTelraam[$id] ? "telraam" : "eco_counter");
    }
    writeJsonPretty($permDir . "/overview_month_" . $ym . ".json", [
      "mode" => "month", "period" => $ym, "from" => $from, "to" => $to,
      "generated_at" => date("c"), "threshold_full" => $FULL_COVERAGE_THRESHOLD,
      "min_hours_valid_day" => $MIN_HOURS_VALID_DAY, "items" => $items
    ]);
    $generatedOverviewM++;

    $outCounters = [];
    foreach ($counters as $id) {
      $outCounters[$id] = buildCounterDetails($id, $nameById[$id] ?? $id, mergeCounterMonths($details, [$ym], $id), $MIN_HOURS_VALID_DAY, $weekdayNamesPL);
    }
    writeJsonPretty($permDir . "/details_month_" . $ym . ".json", [
      "mode" => "month", "period" => $ym, "from" => $from, "to" => $to,
      "generated_at" => date("c"), "counters" => $outCounters
    ]);
    $generatedDetailsM++;
  }
  logln("overview_month wygenerowane: " . $generatedOverviewM);
  logln("details_month wygenerowane: " . $generatedDetailsM);

  // ===== BASELINE: wartości per okres (JS liczy medianę bez okresu, który właśnie oglądasz) =====
  // $periodValues[$id][$periodKey] = [daily, weekday, weekend] dla okresów spełniających próg kompletności
  $baselineFile = function (string $path, string $mode, string $key, array $periodValuesById, array $periodsForKey) use ($counters, $nameById, $FULL_COVERAGE_THRESHOLD): void {
    $items = [];
    foreach ($counters as $id) {
      $listD = []; $listW = []; $listE = []; $by = [];
      foreach ($periodsForKey as $pk) {
        $v = $periodValuesById[$id][$pk] ?? null;
        if (!$v) continue;
        $listD[] = $v[0]; $listW[] = $v[1]; $listE[] = $v[2];
        $by[$pk] = ["daily" => $v[0], "weekday" => $v[1], "weekend" => $v[2]];
      }
      $items[] = [
        "id" => $id,
        "name" => $nameById[$id] ?? $id,
        "median_daily" => medianInt($listD),
        "median_weekday" => medianInt($listW),
        "median_weekend" => medianInt($listE),
        "n_periods" => count($listD),
        "by_period" => $by
      ];
    }
    writeJsonPretty($path, [
      "mode" => $mode, "key" => $key, "generated_at" => date("c"),
      "threshold_full" => $FULL_COVERAGE_THRESHOLD, "items" => $items
    ]);
  };

  $monthVals = [];
  foreach ($counters as $id) {
    foreach ($availableMonths as $ym) {
      $it = buildOverviewItem($id, "", [$agg[$ym][$id] ?? emptyAgg()], expectedHoursInMonth($ym), $FULL_COVERAGE_THRESHOLD, $isTelraam[$id] ? "telraam" : "eco_counter");
      if ($it["is_full"]) $monthVals[$id][$ym] = [$it["avg_daily"], $it["avg_weekday"], $it["avg_weekend"]];
    }
  }
  $generatedBaselineM = 0;
  for ($mm = 1; $mm <= 12; $mm++) {
    $mmStr = str_pad((string)$mm, 2, "0", STR_PAD_LEFT);
    $periods = array_values(array_filter($availableMonths, fn($ym) => substr($ym, 5, 2) === $mmStr));
    $baselineFile($permDir . "/baseline_median_month_" . $mmStr . ".json", "month", $mmStr, $monthVals, $periods);
    $generatedBaselineM++;
  }
  logln("baseline_median_month wygenerowane: " . $generatedBaselineM);

  // ===== QUARTERS =====
  $quartersSet = [];
  foreach ($availableMonths as $ym) $quartersSet[monthToQuarter($ym)] = true;
  $quarters = array_keys($quartersSet);
  sort($quarters);

  $availableQuarters = [];
  foreach ($quarters as $yq) {
    $ms = quarterMonths($yq);
    if (in_array($ms[0], $availableMonths, true) &&
        in_array($ms[1], $availableMonths, true) &&
        in_array($ms[2], $availableMonths, true)) {
      $availableQuarters[] = $yq;
    }
  }
  $lastFullQuarter = count($availableQuarters) ? end($availableQuarters) : null;
  if ($lastFullQuarter) logln("Ostatni pełny kwartał: " . $lastFullQuarter);

  $quarterVals = [];
  $generatedDetailsQ = 0; $generatedOverviewQ = 0;
  foreach ($availableQuarters as $yq) {
    $ms = quarterMonths($yq);
    $from = $ms[0] . "-01";
    $to   = date("Y-m-t", strtotime($ms[2] . "-01"));
    $expectedHours = expectedHoursInMonth($ms[0]) + expectedHoursInMonth($ms[1]) + expectedHoursInMonth($ms[2]);

    $outCounters = [];
    $items = [];
    foreach ($counters as $id) {
      $outCounters[$id] = buildCounterDetails($id, $nameById[$id] ?? $id, mergeCounterMonths($details, $ms, $id), $MIN_HOURS_VALID_DAY, $weekdayNamesPL);
      $aggs = array_map(fn($m) => $agg[$m][$id] ?? emptyAgg(), $ms);
      $it = buildOverviewItem($id, $nameById[$id] ?? $id, $aggs, $expectedHours, $FULL_COVERAGE_THRESHOLD, $isTelraam[$id] ? "telraam" : "eco_counter");
      $items[] = $it;
      if ($it["is_full"]) $quarterVals[$id][$yq] = [$it["avg_daily"], $it["avg_weekday"], $it["avg_weekend"]];
    }
    writeJsonPretty($permDir . "/details_quarter_" . $yq . ".json", [
      "mode" => "quarter", "period" => $yq, "from" => $from, "to" => $to,
      "generated_at" => date("c"), "counters" => $outCounters
    ]);
    $generatedDetailsQ++;
    writeJsonPretty($permDir . "/overview_quarter_" . $yq . ".json", [
      "mode" => "quarter", "period" => $yq, "from" => $from, "to" => $to,
      "generated_at" => date("c"), "threshold_full" => $FULL_COVERAGE_THRESHOLD,
      "min_hours_valid_day" => $MIN_HOURS_VALID_DAY, "items" => $items
    ]);
    $generatedOverviewQ++;
  }
  logln("details_quarter wygenerowane: " . $generatedDetailsQ);
  logln("overview_quarter wygenerowane: " . $generatedOverviewQ);

  $generatedBaselineQ = 0;
  for ($q = 1; $q <= 4; $q++) {
    $qKey = "Q" . $q;
    $periods = array_values(array_filter($availableQuarters, fn($yq) => substr($yq, 5, 2) === $qKey));
    $baselineFile($permDir . "/baseline_median_quarter_" . $qKey . ".json", "quarter", $qKey, $quarterVals, $periods);
    $generatedBaselineQ++;
  }
  logln("baseline_median_quarter wygenerowane: " . $generatedBaselineQ);

  // ===== YEARS (pełne lata: wszystkie 12 miesięcy istnieją) =====
  $yearsSet = [];
  foreach ($availableMonths as $ym) $yearsSet[substr($ym,0,4)] = true;
  $years = array_keys($yearsSet); sort($years);

  $availableYears = [];
  foreach ($years as $y) {
    $ok = true;
    for ($m = 1; $m <= 12; $m++) {
      if (!in_array(sprintf("%s-%02d", $y, $m), $availableMonths, true)) { $ok = false; break; }
    }
    if ($ok) $availableYears[] = $y;
  }
  $lastFullYear = count($availableYears) ? end($availableYears) : null;
  if ($lastFullYear) logln("Ostatni pełny rok: " . $lastFullYear);

  $yearVals = [];
  $generatedDetailsY = 0; $generatedOverviewY = 0;
  foreach ($availableYears as $y) {
    $from = $y . "-01-01";
    $to   = $y . "-12-31";
    $ms = [];
    $expectedHours = 0;
    for ($m = 1; $m <= 12; $m++) { $ym = sprintf("%s-%02d", $y, $m); $ms[] = $ym; $expectedHours += expectedHoursInMonth($ym); }

    $outCounters = [];
    $items = [];
    foreach ($counters as $id) {
      $outCounters[$id] = buildCounterDetails($id, $nameById[$id] ?? $id, mergeCounterMonths($details, $ms, $id), $MIN_HOURS_VALID_DAY, $weekdayNamesPL);
      $aggs = array_map(fn($m) => $agg[$m][$id] ?? emptyAgg(), $ms);
      $it = buildOverviewItem($id, $nameById[$id] ?? $id, $aggs, $expectedHours, $FULL_COVERAGE_THRESHOLD, $isTelraam[$id] ? "telraam" : "eco_counter");
      $items[] = $it;
      if ($it["is_full"]) $yearVals[$id][(string)$y] = [$it["avg_daily"], $it["avg_weekday"], $it["avg_weekend"]];
    }
    writeJsonPretty($permDir . "/details_year_" . $y . ".json", [
      "mode" => "year", "period" => $y, "from" => $from, "to" => $to,
      "generated_at" => date("c"), "counters" => $outCounters
    ]);
    $generatedDetailsY++;
    writeJsonPretty($permDir . "/overview_year_" . $y . ".json", [
      "mode" => "year", "period" => $y, "from" => $from, "to" => $to,
      "generated_at" => date("c"), "threshold_full" => $FULL_COVERAGE_THRESHOLD,
      "min_hours_valid_day" => $MIN_HOURS_VALID_DAY, "items" => $items
    ]);
    $generatedOverviewY++;
  }
  logln("details_year wygenerowane: " . $generatedDetailsY);
  logln("overview_year wygenerowane: " . $generatedOverviewY);

  $baselineFile($permDir . "/baseline_median_year.json", "year", "ALL", $yearVals, $availableYears);
  logln("baseline_median_year zapisane.");

  $levelFlags = detectLevelShifts($monthVals, $isTelraam);
  logln("Flagi zmiany poziomu: " . count($levelFlags));

  // ===== RAPORT JAKOŚCI DANYCH =====
  writeJsonPretty($permDir . "/data_quality.json", [
    "generated_at" => date("c"),
    "zero_run_min_hours" => $ZERO_RUN_MIN_HOURS,
    "min_hours_valid_day" => $MIN_HOURS_VALID_DAY,
    "excluded_ranges" => $qualityRanges,
    "flags" => array_merge($anomalyFlags, $levelFlags),
    "skipped_columns" => $skippedCols,
    "invalid_rows" => $invalidRows
  ]);
  logln("data_quality.json zapisane.");

  // ===== META (na końcu: przeglądarka widzi nowe okresy dopiero po wygenerowaniu reszty) =====
  $meta = [
    "generated_at" => date("c"),
    "threshold_full" => $FULL_COVERAGE_THRESHOLD,
    "min_hours_valid_day" => $MIN_HOURS_VALID_DAY,
    "zero_run_min_hours" => $ZERO_RUN_MIN_HOURS,

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
  logln("OK. Czas: " . number_format($t1 - $t0, 2) . " s, pamięć szczytowa: " . number_format(memory_get_peak_usage(true)/1024/1024, 1) . " MB");

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
