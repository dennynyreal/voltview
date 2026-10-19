
<?php

const VERCEL_API_URL = 'https://voltview.vercel.app/api/voltview';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'];

$stateFile = sys_get_temp_dir()
    . DIRECTORY_SEPARATOR
    . 'voltview_energy_state.json';

/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

function readEnergyState($file)
{
    $handle = @fopen($file, 'c+');

    if ($handle === false) {
        return [];
    }

    if (!flock($handle, LOCK_SH)) {
        fclose($handle);
        return [];
    }

    rewind($handle);
    $content = stream_get_contents($handle);
    $state = json_decode($content, true);

    flock($handle, LOCK_UN);
    fclose($handle);

    return is_array($state) ? $state : [];
}

function writeEnergyState($handle, $state)
{
    rewind($handle);
    ftruncate($handle, 0);

    fwrite(
        $handle,
        json_encode($state, JSON_UNESCAPED_UNICODE)
    );

    fflush($handle);
}

function numberOrNull($value)
{
    if (
        $value === null ||
        !is_numeric($value) ||
        !is_finite((float) $value)
    ) {
        return null;
    }

    return (float) $value;
}

function averageValues($values)
{
    $valid = array_filter(
        $values,
        fn($value) => $value !== null
    );

    if (count($valid) === 0) {
        return null;
    }

    return array_sum($valid) / count($valid);
}

function makePayload($latest, $history)
{
    if (!is_array($latest)) {
        $latest = [];
    }

    // Tegangan line-to-neutral per fasa.
    $lnR = numberOrNull($latest['voltage_ln_r'] ?? null);
    $lnS = numberOrNull($latest['voltage_ln_s'] ?? null);
    $lnT = numberOrNull($latest['voltage_ln_t'] ?? null);

    // Kompatibilitas dengan kolom voltage lama.
    $legacyVoltage = numberOrNull($latest['voltage'] ?? null);

    $lnAvg = averageValues([$lnR, $lnS, $lnT]);

    if ($lnAvg === null) {
        $lnAvg = $legacyVoltage;
    }

    // Tegangan line-to-line.
    $llRS = numberOrNull($latest['voltage_ll_rs'] ?? null);
    $llST = numberOrNull($latest['voltage_ll_st'] ?? null);
    $llTR = numberOrNull($latest['voltage_ll_tr'] ?? null);

    // Arus tiap fasa dan netral.
    $currentR = numberOrNull($latest['current_r'] ?? null);
    $currentS = numberOrNull($latest['current_s'] ?? null);
    $currentT = numberOrNull($latest['current_t'] ?? null);
    $currentN = numberOrNull($latest['current_n'] ?? null);

    // Kompatibilitas dengan kolom current_a lama.
    $legacyCurrent = numberOrNull($latest['current_a'] ?? null);

    $currentAvg = averageValues([
        $currentR,
        $currentS,
        $currentT
    ]);

    if ($currentAvg === null) {
        $currentAvg = $legacyCurrent;
    }

    // Daya aktif.
    $powerW = numberOrNull($latest['power_w'] ?? null);

    $activeKW = $powerW === null
        ? null
        : $powerW / 1000;

    // Daya reaktif dan daya semu.
    $reactiveKvar = numberOrNull(
        $latest['reactive_power_kvar'] ?? null
    );

    $apparentKva = numberOrNull(
        $latest['apparent_power_kva'] ?? null
    );

    $powerFactor = numberOrNull(
        $latest['power_factor'] ?? null
    );

    // Energi.
    $activeKwh = numberOrNull(
        $latest['energy_kwh'] ?? null
    );

    $reactiveKvarh = numberOrNull(
        $latest['reactive_energy_kvarh'] ?? null
    );

    // Parameter operasional.
    $runHours = numberOrNull(
        $latest['run_hours'] ?? null
    );

    $avgLoad = numberOrNull(
        $latest['avg_load_percent'] ?? null
    );

    $frequency = numberOrNull(
        $latest['frequency_hz'] ?? null
    );

    $timestamp = $latest['received_at']
        ?? $latest['measured_at']
        ?? null;

    return [
        'success' => true,

        'voltage' => [
            'ln' => [
                'r' => $lnR,
                's' => $lnS,
                't' => $lnT,
                'avg' => $lnAvg
            ],

            'll' => [
                'rs' => $llRS,
                'st' => $llST,
                'tr' => $llTR,
                'avg' => averageValues([
                    $llRS,
                    $llST,
                    $llTR
                ])
            ]
        ],

        'current' => [
            'r' => $currentR,
            's' => $currentS,
            't' => $currentT,
            'n' => $currentN,
            'avg' => $currentAvg
        ],

        'power' => [
            'active_kw' => $activeKW,
            'reactive_kvar' => $reactiveKvar,
            'apparent_kva' => $apparentKva,
            'power_factor' => $powerFactor,
            'avg_load_percent' => $avgLoad
        ],

        'energy' => [
            'active_kwh' => $activeKwh,
            'reactive_kvarh' => $reactiveKvarh
        ],

        'frequency_hz' => $frequency,
        'run_hours' => $runHours,
        'timestamp' => $timestamp,
        'source' => $latest['source'] ?? null,
        'history' => $history
    ];
}

/*
|--------------------------------------------------------------------------
| FIELD PENGUKURAN
|--------------------------------------------------------------------------
*/

$numericFields = [
    'voltage',
    'current_a',
    'power_w',
    'energy_kwh',
    'frequency_hz',
    'power_factor',

    'voltage_ln_r',
    'voltage_ln_s',
    'voltage_ln_t',

    'voltage_ll_rs',
    'voltage_ll_st',
    'voltage_ll_tr',

    'current_r',
    'current_s',
    'current_t',
    'current_n',

    'reactive_power_kvar',
    'apparent_power_kva',
    'reactive_energy_kvarh',

    'run_hours',
    'avg_load_percent'
];

/*
|--------------------------------------------------------------------------
| 1. GET: REAL-TIME, DATABASE FALLBACK, DAN RIWAYAT
|--------------------------------------------------------------------------
*/

if ($method === 'GET') {
    $query = [];

    if (isset($_GET['range'])) {
        $range = (string) $_GET['range'];

        if (!in_array($range, ['day', 'week', 'month'], true)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Periode tidak valid.'
            ]);
            exit;
        }

        $query['range'] = $range;
    }

    if (($_GET['export'] ?? '') === '1') {
        $query['export'] = '1';
    }

    $requestUrl = VERCEL_API_URL;

    if (!empty($query)) {
        $requestUrl .= '?' . http_build_query($query);
    }

    $ch = curl_init($requestUrl);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json'
        ]
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        curl_close($ch);
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'message' => 'Gagal menghubungi Vercel API.'
        ]);
        exit;
    }

    curl_close($ch);

    http_response_code($status);
    echo $response;
    exit;
}

/*
|--------------------------------------------------------------------------
| 2. POST: TERIMA DATA ESP32 DAN SIMPAN KE DATABASE
|--------------------------------------------------------------------------
*/


if ($method === 'POST') {
    $requestBody = file_get_contents('php://input');

    // Validasi body request
    if ($requestBody === false || trim($requestBody) === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Request body JSON kosong.'
        ]);
        exit;
    }

    // Validasi format JSON
    json_decode($requestBody);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Format JSON tidak valid.'
        ]);
        exit;
    }

    // Teruskan data ke Vercel API untuk disimpan ke Neon
    $ch = curl_init(VERCEL_API_URL);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $requestBody,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json'
        ]
    ]);

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false || $status === 0) {
        curl_close($ch);

        http_response_code(502);
        echo json_encode([
            'success' => false,
            'message' => 'Gagal menghubungi Vercel API.'
        ]);
        exit;
    }

    curl_close($ch);

    // Teruskan respons dari Vercel ke pengirim data
    http_response_code($status);
    echo $response;
    exit;
}

/*
|--------------------------------------------------------------------------
| 3. METODE HTTP LAIN
|--------------------------------------------------------------------------
*/

header('Allow: GET, POST');

http_response_code(405);

echo json_encode([
    'success' => false,
    'message' => 'Metode HTTP tidak didukung.'
], JSON_UNESCAPED_UNICODE);
