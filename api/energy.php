
<?php

require_once __DIR__ . '/db.php';

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

    // Rentang waktu grafik historis.
    $range = $_GET['range'] ?? 'day';

    switch ($range) {
        case 'week':
            $days = 7;
            $limit = 1200;
            break;

        case 'month':
            $days = 30;
            $limit = 5000;
            break;

        default:
            $days = 1;
            $limit = 200;
            break;
    }

    // 1. Ambil data real-time terakhir dari file sementara.
    $state = readEnergyState($stateFile);
    $latest = $state['latest'] ?? null;

    // 2. Tentukan apakah data ESP32 masih segar.
    $receivedAt = is_array($latest)
        ? strtotime($latest['received_at'] ?? '')
        : false;

    $maxAgeSeconds = 60;

    $liveAvailable = (
        is_array($latest) &&
        $receivedAt !== false &&
        (time() - $receivedAt) >= 0 &&
        (time() - $receivedAt) <= $maxAgeSeconds
    );

    // 3. Ambil riwayat pengukuran untuk grafik.
    $sql = "
        SELECT
            id,
            power_w,
            reactive_power_kvar,
            apparent_power_kva,
            measured_at
        FROM energy_readings
        WHERE measured_at >= NOW() - INTERVAL $days DAY
        ORDER BY measured_at ASC, id ASC
        LIMIT ?
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $limit);
    $stmt->execute();

    $result = $stmt->get_result();
    $history = [];

    while ($row = $result->fetch_assoc()) {
        $history[] = [
            'label' => $row['measured_at'],

            'active_kw' => $row['power_w'] === null
                ? null
                : (float) $row['power_w'] / 1000,

            'reactive_kvar' => numberOrNull(
                $row['reactive_power_kvar'] ?? null
            ),

            'apparent_kva' => numberOrNull(
                $row['apparent_power_kva'] ?? null
            )
        ];
    }

    $stmt->close();

    // 4. Ambil pengukuran terakhir dari seluruh database.
    // Query ini tidak dibatasi rentang grafik day/week/month.
    $stmt = $conn->prepare("
        SELECT *
        FROM energy_readings
        ORDER BY measured_at DESC, id DESC
        LIMIT 1
    ");

    $stmt->execute();

    $dbLatest = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    // 5. Prioritas: ESP32 real-time > database.
    if ($liveAvailable) {
        $displayData = $latest;
        $displaySource = 'realtime';
    } elseif (is_array($dbLatest)) {
        $displayData = $dbLatest;
        $displaySource = 'database';
    } else {
        $displayData = null;
        $displaySource = null;
    }

    // 6. Kedua sumber kosong: kirim metrik kosong.
    if ($displayData === null) {
        $payload = makePayload(null, $history);

        $payload['data_available'] = false;
        $payload['display_source'] = null;
        $payload['database_count'] = count($history);
        $payload['message'] =
            'Belum ada data dari ESP32 maupun database.';

        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }

    // 7. Kirim data yang dipilih ke dashboard.
    $payload = makePayload($displayData, $history);

    $payload['data_available'] = true;
    $payload['display_source'] = $displaySource;
    $payload['database_count'] = count($history);

    if ($displaySource === 'realtime') {
        $payload['message'] =
            'Menampilkan data real-time ESP32.';
    } else {
        $payload['message'] =
            'ESP32 tidak mengirim data terbaru. Menampilkan pengukuran terakhir dari database.';
    }

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| 2. POST: TERIMA DATA ESP32 DAN SIMPAN KE DATABASE
|--------------------------------------------------------------------------
*/

if ($method === 'POST') {

    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($input)) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Body JSON tidak valid.'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    // Validasi seluruh parameter numerik.
    $values = [];

    foreach ($numericFields as $field) {

        $value = $input[$field] ?? null;

        if (
            $value !== null &&
            (
                !is_numeric($value) ||
                !is_finite((float) $value)
            )
        ) {
            http_response_code(422);

            echo json_encode([
                'success' => false,
                'message' =>
                    "Nilai $field harus berupa angka atau null."
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        $values[$field] = $value === null
            ? null
            : (float) $value;
    }

    // Validasi sumber pengukuran.
    $source = $input['source'] ?? 'manual';

    if (
        !is_string($source) ||
        strlen($source) > 50 ||
        trim($source) === ''
    ) {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' =>
                'Source harus berupa teks maksimal 50 karakter.'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    // Buka dan kunci file state agar tidak bentrok.
    $handle = @fopen($stateFile, 'c+');

    if ($handle === false) {
        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' =>
                'Gagal membuka penyimpanan sementara.'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' =>
                'Gagal mengunci penyimpanan sementara.'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    rewind($handle);

    $content = stream_get_contents($handle);
    $state = json_decode($content, true);

    if (!is_array($state)) {
        $state = [];
    }

    $now = time();

    if (!isset($state['started_at'])) {
        $state['started_at'] = $now;
    }

    // Inisialisasi jadwal berdasarkan data database terakhir.
    if (empty($state['schedule_initialized'])) {

        $stmt = $conn->prepare("
            SELECT UNIX_TIMESTAMP(measured_at) AS saved_epoch
            FROM energy_readings
            ORDER BY measured_at DESC, id DESC
            LIMIT 1
        ");

        $stmt->execute();

        $lastRow = $stmt->get_result()->fetch_assoc();

        $stmt->close();

        $state['last_saved_epoch'] =
            ($lastRow && $lastRow['saved_epoch'] !== null)
                ? (int) $lastRow['saved_epoch']
                : null;

        $state['schedule_initialized'] = true;
    }

    // Perbarui data real-time pada setiap POST.
    $latest = $values;
    $latest['source'] = $source;
    $latest['received_at'] = date('Y-m-d H:i:s');

    $state['latest'] = $latest;

    writeEnergyState($handle, $state);

    // Simpan satu sampel ke database setiap 10 menit.
    $referenceTime = $state['last_saved_epoch']
        ?? $state['started_at'];

    $shouldSave = ($now - $referenceTime) >= 600;

    $saved = false;
    $savedId = null;

    if ($shouldSave) {

        try {

            $insertFields = $numericFields;

            $columns = array_merge(
                $insertFields,
                ['source']
            );

            $columnSql = implode(', ', $columns);

            $placeholders = implode(
                ', ',
                array_fill(0, count($columns), '?')
            );

            $sql = "
                INSERT INTO energy_readings
                ($columnSql)
                VALUES ($placeholders)
            ";

            $stmt = $conn->prepare($sql);

            // Parameter numerik menggunakan tipe double,
            // sedangkan source menggunakan string.
            $types = str_repeat(
                'd',
                count($insertFields)
            ) . 's';

            $params = [$types];

            foreach ($insertFields as $field) {
                $params[] = &$values[$field];
            }

            $params[] = &$source;

            call_user_func_array(
                [$stmt, 'bind_param'],
                $params
            );

            $stmt->execute();

            $savedId = $conn->insert_id;

            $stmt->close();

            $state['last_saved_epoch'] = time();

            writeEnergyState($handle, $state);

            $saved = true;

        } catch (Throwable $e) {

            flock($handle, LOCK_UN);
            fclose($handle);

            error_log(
                'VoltView database save error: '
                . $e->getMessage()
            );

            http_response_code(500);

            echo json_encode([
                'success' => false,
                'message' =>
                    'Data diterima, tetapi gagal disimpan ke database.'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }
    }

    flock($handle, LOCK_UN);
    fclose($handle);

    http_response_code($saved ? 201 : 200);

    $referenceTime = $state['last_saved_epoch']
        ?? $state['started_at'];

    echo json_encode([
        'success' => true,

        'message' => $saved
            ? 'Data pengukuran berhasil disimpan.'
            : 'Data real-time berhasil diperbarui.',

        'saved_to_database' => $saved,
        'id' => $savedId,

        'next_save_in_seconds' => max(
            0,
            600 - (time() - $referenceTime)
        )
    ], JSON_UNESCAPED_UNICODE);

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
