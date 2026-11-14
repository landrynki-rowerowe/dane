<?php
declare(strict_types=1);

date_default_timezone_set("Europe/Warsaw");

$root = realpath(__DIR__ . "/..");
$cacheDir = $root . "/cache";
$permDir  = $cacheDir . "/permanent";
$csvCache = $cacheDir . "/data.csv";
$metaFile = $permDir . "/meta_periods.json";

function fmtTime(?int $ts): string {
  if (!$ts) return "—";
  return date("Y-m-d H:i:s", $ts);
}

$csvMtime  = file_exists($csvCache) ? filemtime($csvCache) : null;
$metaMtime = file_exists($metaFile) ? filemtime($metaFile) : null;

$overviewCount = 0;
if (is_dir($permDir)) {
  $overviewCount = count(glob($permDir . "/overview_month_*.json") ?: []);
}
$baselineCount = 0;
if (is_dir($permDir)) {
  $baselineCount = count(glob($permDir . "/baseline_median_month_*.json") ?: []);
}

?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Admin — Precompute cache</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
  <div class="container py-4">
    <h1 class="h4 mb-3">Admin: precompute cache (miesiące + mediany)</h1>

    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <div class="fw-semibold mb-1">Status</div>
            <ul class="mb-0">
              <li>CSV cache: <code><?= htmlspecialchars($csvCache) ?></code></li>
              <li>CSV last update: <strong><?= htmlspecialchars(fmtTime($csvMtime)) ?></strong></li>
              <li>meta_periods.json last update: <strong><?= htmlspecialchars(fmtTime($metaMtime)) ?></strong></li>
              <li>overview_month_*.json: <strong><?= (int)$overviewCount ?></strong></li>
              <li>baseline_median_month_*.json: <strong><?= (int)$baselineCount ?></strong></li>
            </ul>
          </div>
          <div class="col-md-6">
            <div class="fw-semibold mb-1">Akcja</div>
            <form method="post" action="precompute.php">
              <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" value="1" id="force" name="force">
                <label class="form-check-label" for="force">
                  Wymuś ponowne pobranie CSV (ignoruj TTL)
                </label>
              </div>
              <button class="btn btn-primary">Uruchom precompute</button>
              <a class="btn btn-outline-secondary" href="./" role="button">Odśwież</a>
            </form>
            <div class="text-secondary small mt-2">
              Tip: ustaw Basic Auth na katalog <code>/dane/admin/</code>.
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="alert alert-warning small">
      Jeśli precompute trwa długo, zwiększ <code>max_execution_time</code> w PHP (albo uruchamiaj z CLI).
      Skrypt ustawia <code>set_time_limit(0)</code>, ale hosting może mieć limity.
    </div>
  </div>
</body>
</html>
