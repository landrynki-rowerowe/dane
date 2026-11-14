<?php

/*
 * Aktualizacja weather_daily.json
 * Google Apps Script -> dhosting
 */

header('Content-Type: application/json; charset=utf-8');


// ==================================================
// KONFIGURACJA
// ==================================================

$TARGET_FILE = __DIR__ . '/weather_daily.json';

/*
 * Maksymalny rozmiar przesyłanego JSON-a.
 * Obecny plik jest dużo mniejszy.
 */
$MAX_BYTES = 2 * 1024 * 1024;


// ==================================================
// WCZYTANIE SEKRETU SPOZA PUBLIC_HTML
// ==================================================

$secretFile =
    dirname(__DIR__, 3) . '/weather_secret.php';

if (!is_file($secretFile)) {

    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'error' => 'Server configuration error'
    ]);

    exit;
}


$config = require $secretFile;

if (
    !is_array($config) ||
    empty($config['token'])
) {

    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'error' => 'Server configuration error'
    ]);

    exit;
}

$SECRET_TOKEN = $config['token'];


// ==================================================
// TYLKO POST
// ==================================================

if (
    ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
) {

    http_response_code(405);

    header('Allow: POST');

    echo json_encode([
        'ok' => false,
        'error' => 'Method not allowed'
    ]);

    exit;
}


// ==================================================
// KONTROLA ROZMIARU
// ==================================================

$contentLength =
    isset($_SERVER['CONTENT_LENGTH'])
        ? (int) $_SERVER['CONTENT_LENGTH']
        : 0;

if (
    $contentLength > $MAX_BYTES
) {

    http_response_code(413);

    echo json_encode([
        'ok' => false,
        'error' => 'Payload too large'
    ]);

    exit;
}


// ==================================================
// AUTORYZACJA
// ==================================================

$providedToken =
    $_SERVER['HTTP_X_WEATHER_TOKEN'] ?? '';

if (
    $providedToken === '' ||
    !hash_equals(
        $SECRET_TOKEN,
        $providedToken
    )
) {

    http_response_code(403);

    echo json_encode([
        'ok' => false,
        'error' => 'Forbidden'
    ]);

    exit;
}


// ==================================================
// POBRANIE DANYCH
// ==================================================

$input =
    file_get_contents('php://input');

if (
    $input === false ||
    trim($input) === ''
) {

    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'error' => 'Empty body'
    ]);

    exit;
}


if (
    strlen($input) > $MAX_BYTES
) {

    http_response_code(413);

    echo json_encode([
        'ok' => false,
        'error' => 'Payload too large'
    ]);

    exit;
}


// ==================================================
// WALIDACJA JSON
// ==================================================

$data =
    json_decode(
        $input,
        true
    );

if (
    json_last_error() !== JSON_ERROR_NONE ||
    !is_array($data)
) {

    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'error' => 'Invalid JSON'
    ]);

    exit;
}


// ==================================================
// WALIDACJA STRUKTURY WEATHER_DAILY
// ==================================================

if (count($data) < 100) {

    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'error' => 'Too few weather records'
    ]);

    exit;
}


$requiredFields = [
    'temp_max',
    'temp_min',
    'temp_avg',
    'temp_ground_min',
    'precip_sum'
];


foreach ($data as $date => $record) {

    /*
     * Klucz musi wyglądać np.:
     * 2026-10-17
     */
    if (
        !preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            (string) $date
        )
    ) {

        http_response_code(400);

        echo json_encode([
            'ok' => false,
            'error' => 'Invalid date key'
        ]);

        exit;
    }


    if (!is_array($record)) {

        http_response_code(400);

        echo json_encode([
            'ok' => false,
            'error' => 'Invalid record'
        ]);

        exit;
    }


    foreach ($requiredFields as $field) {

        if (
            !array_key_exists(
                $field,
                $record
            )
        ) {

            http_response_code(400);

            echo json_encode([
                'ok' => false,
                'error' =>
                    'Missing field: ' . $field
            ]);

            exit;
        }

    }

}


// ==================================================
// DODATKOWA KONTROLA NAJNOWSZEJ DATY
// ==================================================

$dates =
    array_keys($data);

sort($dates);

$latestDate =
    end($dates);

if (!$latestDate) {

    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'error' => 'No dates found'
    ]);

    exit;
}


// ==================================================
// BEZPIECZNY ZAPIS
// ==================================================

$tempFile =
    $TARGET_FILE . '.tmp';

$bytes =
    file_put_contents(
        $tempFile,
        $input,
        LOCK_EX
    );

if ($bytes === false) {

    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'error' => 'Cannot write temporary file'
    ]);

    exit;
}


/*
 * Sprawdzamy jeszcze raz plik tymczasowy.
 */
if (
    !is_file($tempFile) ||
    filesize($tempFile) === 0
) {

    @unlink($tempFile);

    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'error' => 'Temporary file validation failed'
    ]);

    exit;
}


/*
 * Dopiero teraz podmieniamy plik produkcyjny.
 */
if (
    !rename(
        $tempFile,
        $TARGET_FILE
    )
) {

    @unlink($tempFile);

    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'error' => 'Cannot replace target file'
    ]);

    exit;
}


// ==================================================
// ODPOWIEDŹ
// ==================================================

echo json_encode([
    'ok' => true,
    'records' => count($data),
    'latest_date' => $latestDate,
    'bytes' => $bytes,
    'updated' => date('c')
]);