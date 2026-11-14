<?php
header('Content-Type: application/json; charset=utf-8');

$CSV_URL = "https://docs.google.com/spreadsheets/d/e/2PACX-1vR6Y2bE2n2rWeIwFoA-YPyVuFa9BOjEFdWfTIyph-1PcyeaaRacmM_4-Q_QdYo1dOrAldVSy4hi334J/pub?gid=1137251897&single=true&output=csv";

$CACHE_DIR = __DIR__ . "/../cache";
$CACHE_FILE = $CACHE_DIR . "/data.csv";
$CACHE_TTL_SECONDS = 6 * 3600;

date_default_timezone_set("Europe/Warsaw");

// ostatni pełny miesiąc (poprzedni miesiąc kalendarzowy)
$firstThisMonth = strtotime(date("Y-m-01 00:00:00"));
$toTs = $firstThisMonth - 1;
$fromTs = strtotime(date("Y-m-01 00:00:00", strtotime("-1 month", $firstThisMonth)));

$from = date("Y-m-d", $fromTs);
$to = date("Y-m-d", $toTs);

// cache wyniku
if (!is_dir($CACHE_DIR)) mkdir($CACHE_DIR, 0775, true);
$SUM_TTL = 6 * 3600;
$sumFile = $CACHE_DIR . "/summary_last_full_month.json";
if (file_exists($sumFile) && (time() - filemtime($sumFile) < $SUM_TTL)) {
  readfile($sumFile);
  exit;
}

// cache CSV
$needDownload = true;
if (file_exists($CACHE_FILE) && (time() - filemtime($CACHE_FILE) < $CACHE_TTL_SECONDS)) {
  $needDownload = false;
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

$fh = fopen($CACHE_FILE, "r");
$header = fgetcsv($fh);
if (!$header) {
  http_response_code(500);
  echo json_encode(["error" => "CSV nie ma nagłówka"], JSON_UNESCAPED_UNICODE);
  exit;
}

$headerNorm = array_map(fn($h) => trim($h), $header);
$idxDate = array_search("date", $headerNorm, true);
if ($idxDate === false) {
  http_response_code(500);
  echo json_encode(["error" => "Brak kolumny date"], JSON_UNESCAPED_UNICODE);
  exit;
}

// liczniki w kolejności CSV + mapowanie indeksów (szybko)
$counters = [];
$counterIdx = [];
for ($i = 0; $i < count($headerNorm); $i++) {
  if ($i === $idxDate) continue;
  $id = $headerNorm[$i];
  $counters[] = $id;
  $counterIdx[$id] = $i;
}

// sumy osobno
$sumAll = array_fill_keys($counters, 0);
$sumWeekday = array_fill_keys($counters, 0);
$sumWeekend = array_fill_keys($counters, 0);

// zbiory dni (żeby policzyć liczbę dni w miesiącu realnie)
$daysAll = [];
$daysWeekday = [];
$daysWeekend = [];

$rows_scanned = 0;

while (($row = fgetcsv($fh)) !== false) {
  $rows_scanned++;

  $dtStr = trim($row[$idxDate] ?? '');
  if ($dtStr === '') continue;

  $ts = strtotime($dtStr);
  if (!$ts) continue;
  if ($ts < $fromTs || $ts > $toTs) continue;

  $dayKey = date("Y-m-d", $ts);
  $dow = (int)date("N", $ts); // 1..7 (Mon..Sun)

  $daysAll[$dayKey] = true;
  $isWeekend = ($dow >= 6);
  if ($isWeekend) $daysWeekend[$dayKey] = true; else $daysWeekday[$dayKey] = true;

  // sumuj per licznik
  foreach ($counters as $id) {
    $idx = $counterIdx[$id];
    $valStr = trim($row[$idx] ?? '');
    if ($valStr === '') continue;

    $val = (int)preg_replace('/[^0-9]/', '', $valStr);
    if ($val <= 0) continue;

    $sumAll[$id] += $val;
    if ($isWeekend) $sumWeekend[$id] += $val; else $sumWeekday[$id] += $val;
  }
}
fclose($fh);

$allDaysCount = max(1, count($daysAll));
$weekdayDaysCount = max(1, count($daysWeekday));
$weekendDaysCount = max(1, count($daysWeekend));

$items = [];
foreach ($counters as $id) {
  $items[] = [
    "id" => $id,
    "month_sum" => $sumAll[$id],
    "avg_daily" => (int)round($sumAll[$id] / $allDaysCount),
    "avg_weekday" => (int)round($sumWeekday[$id] / $weekdayDaysCount),
    "avg_weekend" => (int)round($sumWeekend[$id] / $weekendDaysCount)
  ];
}

$outArr = [
  "from" => $from,
  "to" => $to,
  "days_all" => $allDaysCount,
  "days_weekday" => $weekdayDaysCount,
  "days_weekend" => $weekendDaysCount,
  "rows_scanned" => $rows_scanned,
  "items" => $items
];

$out = json_encode($outArr, JSON_UNESCAPED_UNICODE);
file_put_contents($sumFile, $out);
echo $out;