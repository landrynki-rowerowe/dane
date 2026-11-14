<?php
header('Content-Type: application/json; charset=utf-8');

// ====== KONFIG ======
$CSV_URL = "https://docs.google.com/spreadsheets/d/e/2PACX-1vR6Y2bE2n2rWeIwFoA-YPyVuFa9BOjEFdWfTIyph-1PcyeaaRacmM_4-Q_QdYo1dOrAldVSy4hi334J/pub?gid=1137251897&single=true&output=csv";

$CACHE_DIR = __DIR__ . "/../cache";
$CACHE_FILE = $CACHE_DIR . "/data.csv";
$CACHE_TTL_SECONDS = 6 * 3600; // 6 godzin

// ====== INPUT ======
$counter_id = $_GET['counter_id'] ?? '';
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';

if ($counter_id === '' || $from === '' || $to === '') {
  http_response_code(400);
  echo json_encode(["error" => "Brak parametrów: counter_id, from, to"], JSON_UNESCAPED_UNICODE);
  exit;
}

$fromTs = strtotime($from . " 00:00:00");
$toTs = strtotime($to . " 23:59:59");
if (!$fromTs || !$toTs) {
  http_response_code(400);
  echo json_encode(["error" => "Nieprawidłowy zakres dat"], JSON_UNESCAPED_UNICODE);
  exit;
}

// ====== CACHE ======
if (!is_dir($CACHE_DIR)) mkdir($CACHE_DIR, 0775, true);

$needDownload = true;
if (file_exists($CACHE_FILE)) {
  $age = time() - filemtime($CACHE_FILE);
  if ($age < $CACHE_TTL_SECONDS) $needDownload = false;
}

if ($needDownload) {
  $csv = @file_get_contents($CSV_URL);
  if ($csv === false || strlen($csv) < 50) {
    http_response_code(500);
    echo json_encode(["error" => "Nie udało się pobrać CSV"], JSON_UNESCAPED_UNICODE);
    exit;
  }
  file_put_contents($CACHE_FILE, $csv);
}
// ====== CACHE AGREGACJI (wyników) ======
$AGGR_CACHE_TTL = 15 * 60; // 15 min
$aggrKey = md5("v1|".$counter_id."|".$from."|".$to);
$aggrFile = $CACHE_DIR . "/aggr_" . $aggrKey . ".json";

if (file_exists($aggrFile) && (time() - filemtime($aggrFile) < $AGGR_CACHE_TTL)) {
  readfile($aggrFile);
  exit;
}
// ====== PARSE ======
$fh = fopen($CACHE_FILE, "r");
if (!$fh) {
  http_response_code(500);
  echo json_encode(["error" => "Nie można otworzyć cache CSV"], JSON_UNESCAPED_UNICODE);
  exit;
}

$header = fgetcsv($fh);
if (!$header) {
  http_response_code(500);
  echo json_encode(["error" => "CSV nie ma nagłówka"], JSON_UNESCAPED_UNICODE);
  exit;
}

$headerNorm = array_map(fn($h) => trim($h), $header);

$idxDate = array_search('date', $headerNorm, true);
$idxCounter = array_search($counter_id, $headerNorm, true);

if ($idxDate === false) {
  http_response_code(500);
  echo json_encode(["error" => "Brak kolumny 'date' w CSV"], JSON_UNESCAPED_UNICODE);
  exit;
}
if ($idxCounter === false) {
  http_response_code(400);
  echo json_encode([
    "error" => "Nie znaleziono counter_id w nagłówkach CSV",
    "counter_id" => $counter_id,
    "available" => $headerNorm
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

function median_int(array $arr): ?int {
  $vals = array_values(array_filter($arr, fn($v) => $v !== null));
  $n = count($vals);
  if ($n === 0) return null;
  sort($vals, SORT_NUMERIC);
  $mid = intdiv($n, 2);
  if ($n % 2 === 1) return (int)round($vals[$mid]);
  return (int)round(($vals[$mid-1] + $vals[$mid]) / 2);
}

// ====== AGGREGACJE ======
$daily = [];                 // YYYY-MM-DD => suma
$hourly = array_fill(0, 24, 0);
$weekday = [ "Mon"=>0,"Tue"=>0,"Wed"=>0,"Thu"=>0,"Fri"=>0,"Sat"=>0,"Sun"=>0 ];

// NOWE: do profili i median potrzebujemy rozkładów dziennych
$byDay = [];  // YYYY-MM-DD => ['isWeekend'=>bool, 'total'=>int, 'hours'=>[0..23=>int]]
$byWeekdayTotals = [ "Mon"=>[], "Tue"=>[], "Wed"=>[], "Thu"=>[], "Fri"=>[], "Sat"=>[], "Sun"=>[] ];

$total = 0;
$rows_scanned = 0;
$rows_in_range = 0;
$rows_used = 0;

while (($row = fgetcsv($fh)) !== false) {
  $rows_scanned++;

  $dtStr = trim($row[$idxDate] ?? '');
  if ($dtStr === '') continue;

  // format: "2016-11-18 04:00"
  $ts = strtotime($dtStr);
  if (!$ts) continue;

  if ($ts < $fromTs || $ts > $toTs) continue;
  $rows_in_range++;

  $valStr = trim($row[$idxCounter] ?? '');
  if ($valStr === '') continue; // puste pola (brak licznika w 2016 itd.)

  $count = (int)preg_replace('/[^0-9]/', '', $valStr);
  if ($count < 0) $count = 0;

  $rows_used++;

$isoDay = date("Y-m-d", $ts);
if (!isset($daily[$isoDay])) $daily[$isoDay] = 0;
$daily[$isoDay] += $count;

$h = (int)date("G", $ts);
$hourly[$h] += $count;

$w = date("D", $ts);
if (isset($weekday[$w])) $weekday[$w] += $count;

// NOWE: byDay
$dowNum = (int)date('N', $ts); // 1..7
$isWeekend = ($dowNum >= 6);

if (!isset($byDay[$isoDay])) {
  $byDay[$isoDay] = [
    "isWeekend" => $isWeekend,
    "total" => 0,
    "hours" => array_fill(0, 24, 0)
  ];
}
$byDay[$isoDay]["total"] += $count;
$byDay[$isoDay]["hours"][$h] += $count;

$total += $count;
}
fclose($fh);
foreach ($byDay as $day => $info) {
  $w = date("D", strtotime($day)); // Mon..Sun
  if (isset($byWeekdayTotals[$w])) {
    $byWeekdayTotals[$w][] = (int)$info["total"];
  }
}

// ====== WYJŚCIE ======
ksort($daily);
$dailyArr = [];
foreach ($daily as $d => $c) $dailyArr[] = ["date" => $d, "count" => $c];

$hourlyArr = [];
for ($h=0; $h<24; $h++) {
  $hourlyArr[] = ["hour" => str_pad((string)$h, 2, "0", STR_PAD_LEFT), "count" => $hourly[$h]];
}

$weekdayOrder = ["Mon","Tue","Wed","Thu","Fri","Sat","Sun"];
$weekdayNamesPL = ["Pon","Wto","Śro","Czw","Pią","Sob","Nie"];
$weekdayArr = [];
for ($i=0; $i<7; $i++) {
  $key = $weekdayOrder[$i];
  $weekdayArr[] = ["weekday" => $weekdayNamesPL[$i], "count" => $weekday[$key] ?? 0];
}
// BEST DAY
$bestDay = null;
foreach ($byDay as $day => $info) {
  if ($bestDay === null || $info["total"] > $bestDay["count"]) {
    $weekdayNamesPL = ["Pon","Wto","Śro","Czw","Pią","Sob","Nie"];
    $wd = $weekdayNamesPL[(int)date("N", strtotime($day)) - 1];
    $bestDay = ["date" => $day, "weekday" => $wd, "count" => (int)$info["total"]];
  }
}

// HOURLY split: weekday/weekend (avg + median)
$hourlyWeekday = [];
$hourlyWeekend = [];
for ($h=0; $h<24; $h++) {
  $valsW = [];
  $valsE = [];

  foreach ($byDay as $day => $info) {
    $v = (int)$info["hours"][$h]; // zawsze int
    if ($info["isWeekend"]) $valsE[] = $v;
    else $valsW[] = $v;
  }

  $avgW = count($valsW) ? (int)round(array_sum($valsW) / count($valsW)) : 0;
  $avgE = count($valsE) ? (int)round(array_sum($valsE) / count($valsE)) : 0;

  $medW = median_int($valsW); if ($medW === null) $medW = 0;
  $medE = median_int($valsE); if ($medE === null) $medE = 0;

  $hourlyWeekday[] = ["hour" => str_pad((string)$h, 2, "0", STR_PAD_LEFT), "avg" => $avgW, "median" => $medW];
  $hourlyWeekend[] = ["hour" => str_pad((string)$h, 2, "0", STR_PAD_LEFT), "avg" => $avgE, "median" => $medE];
}

// WEEKDAY stats (avg + median z dziennych sum)
$weekdayStats = [];
$weekdayOrder = ["Mon","Tue","Wed","Thu","Fri","Sat","Sun"];
$weekdayNamesPL = ["Pon","Wto","Śro","Czw","Pią","Sob","Nie"];
for ($i=0; $i<7; $i++) {
  $key = $weekdayOrder[$i];
  $vals = $byWeekdayTotals[$key] ?? [];
  $avg = count($vals) ? (int)round(array_sum($vals) / count($vals)) : 0;
  $med = median_int($vals); if ($med === null) $med = 0;
  $weekdayStats[] = ["weekday" => $weekdayNamesPL[$i], "avg" => $avg, "median" => $med];
}
$response = [
  "counter_id" => $counter_id,
  "from" => $from,
  "to" => $to,
  "total" => $total,
  "rows_scanned" => $rows_scanned,
  "rows_in_range" => $rows_in_range,
  "rows_used" => $rows_used,
  "daily" => $dailyArr,
    "best_day" => $bestDay,
"hourly_weekday" => $hourlyWeekday,
"hourly_weekend" => $hourlyWeekend,
"weekday_stats" => $weekdayStats,
  "hourly" => $hourlyArr,
  "weekday" => $weekdayArr
];

$out = json_encode($response, JSON_UNESCAPED_UNICODE);
file_put_contents($aggrFile, $out);
echo $out;

if (mt_rand(1, 30) === 1) {
  foreach (glob($CACHE_DIR . "/aggr_*.json") as $f) {
    if (time() - filemtime($f) > 24*3600) @unlink($f); // starsze niż 24h
  }
}