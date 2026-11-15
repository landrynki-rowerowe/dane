<?php
declare(strict_types=1);
// Podgląd raportu jakości danych (flagi do przeglądu). Tylko do odczytu; chroniony tak jak reszta katalogu admin/.
date_default_timezone_set("Europe/Warsaw");
$file = realpath(__DIR__ . "/..") . "/cache/permanent/data_quality.json";
$q = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
$flags = is_array($q["flags"] ?? null) ? $q["flags"] : [];
$typeFilter = $_GET["typ"] ?? "";
$counterFilter = $_GET["licznik"] ?? "";
$labels = [
  "seria_zer_wylaczona" => "Seria zer (godziny pominięte)",
  "zera_w_dzien" => "Zera w ciągu dnia",
  "zaklinowana_wartosc" => "Zaklinowana wartość",
  "zmiana_poziomu" => "Zmiana poziomu",
];
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8"); }
$counters = array_values(array_unique(array_map(fn($f) => (string)$f["counter"], $flags)));
sort($counters);
$rows = array_filter($flags, fn($f) => ($typeFilter === "" || $f["type"] === $typeFilter) && ($counterFilter === "" || $f["counter"] === $counterFilter));
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Admin — Jakość danych</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <h1 class="h4 mb-1">Jakość danych — flagi do przeglądu</h1>
  <p class="text-secondary small">
    Wygenerowano: <?= h($q["generated_at"] ?? "—") ?>. Flagi nie zmieniają liczb na stronie; wyjątkiem są serie
    ≥ <?= h($q["zero_run_min_hours"] ?? 48) ?> h samych zer, których godziny są pomijane. „Potwierdzenie” to odsetek pozostałych
    liczników Eco, które w tym czasie notowały ruch (im wyżej, tym bardziej prawdopodobna awaria licznika).
    Ręczne wyłączenia dodajesz w <code>data/data_exclusions.json</code>.
  </p>
  <form class="row g-2 mb-3" method="get">
    <div class="col-auto">
      <select name="typ" class="form-select form-select-sm">
        <option value="">Wszystkie typy</option>
        <?php foreach ($labels as $k => $l): ?><option value="<?= h($k) ?>" <?= $typeFilter === $k ? "selected" : "" ?>><?= h($l) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <select name="licznik" class="form-select form-select-sm">
        <option value="">Wszystkie liczniki</option>
        <?php foreach ($counters as $c): ?><option value="<?= h($c) ?>" <?= $counterFilter === $c ? "selected" : "" ?>><?= h($c) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto"><button class="btn btn-sm btn-primary">Filtruj</button></div>
    <div class="col-auto align-self-center small text-secondary">Pozycji: <?= count($rows) ?> z <?= count($flags) ?></div>
  </form>
  <div class="table-responsive">
    <table class="table table-sm table-striped align-middle bg-white">
      <thead><tr><th>Licznik</th><th>Typ</th><th>Od</th><th>Do</th><th class="text-end">Godz.</th><th>Pewność</th><th class="text-end">Potwierdzenie</th><th>Opis</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $f): ?>
        <tr>
          <td><?= h($f["counter"]) ?></td>
          <td><?= h($labels[$f["type"]] ?? $f["type"]) ?></td>
          <td class="text-nowrap"><?= h($f["from"]) ?></td>
          <td class="text-nowrap"><?= h($f["to"]) ?></td>
          <td class="text-end"><?= h($f["hours"] ?? "") ?></td>
          <td><?= h($f["severity"] ?? "") ?></td>
          <td class="text-end"><?= isset($f["corroboration"]) ? h(round((float)$f["corroboration"] * 100) . "%") : "" ?></td>
          <td class="small"><?= h($f["note"] ?? "") ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="8" class="text-secondary">Brak flag (albo brak pliku data_quality.json — uruchom precompute).</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
