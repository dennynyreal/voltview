
<?php
require_once __DIR__ . '/db.php';

header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $limit = (isset($_GET['range']) && $_GET['range'] === 'week')
        ? 100
        : 24;

    $stmt = $conn->prepare(
        "SELECT
            id, voltage, current_a, power_w, energy_kwh,
            frequency_hz, power_factor, source, measured_at
         FROM energy_readings
         ORDER BY measured_at DESC, id DESC
         LIMIT ?"
    );

    $stmt->bind_param('i', $limit);
    $stmt->execute();

    $result = $stmt->get_result();
    $readings = [];

    while ($row = $result->fetch_assoc()) {
        $readings[] = $row;
    }

    if (count($readings) === 0) {
        echo json_encode([
            'success' => true,
            'voltage' => [
                'ln' => ['avg' => null],
                'll' => []
            ],
            'current' => ['avg' => null],
            'power' => [
                'active_kw' => null,
                'reactive_kvar' => null,
                'apparent_kva' => null,
                'power_factor' => null,
                'avg_load_percent' => null
            ],
            'energy' => [
                'active_kwh' => null,
                'reactive_kvarh' => null
            ],
            'run_hours' => null,
            'timestamp' => null,
            'history' => []
        ]);

        exit;
    }

    // Data terbaru berada di urutan pertama.
    $latest = $readings[0];

    // Grafik membutuhkan urutan waktu dari lama ke baru.
    $history = array_reverse($readings);
    $chartHistory = [];

    foreach ($history as $row) {
        $chartHistory[] = [
            'label' => $row['measured_at'],
            'active_kw' => $row['power_w'] === null
                ? null
                : (float) $row['power_w'] / 1000,
            'reactive_kvar' => null,
            'apparent_kva' => null
        ];
    }

    echo json_encode([
        'success' => true,

        'voltage' => [
            'ln' => [
                'avg' => $latest['voltage'] === null
                    ? null
                    : (float) $latest['voltage']
            ],
            'll' => []
        ],

        'current' => [
            'avg' => $latest['current_a'] === null
                ? null
                : (float) $latest['current_a']
        ],

        'power' => [
            'active_kw' => $latest['power_w'] === null
                ? null
                : (float) $latest['power_w'] / 1000,
            'reactive_kvar' => null,
            'apparent_kva' => null,
            'power_factor' => $latest['power_factor'] === null
                ? null
                : (float) $latest['power_factor'],
            'avg_load_percent' => null
        ],

        'energy' => [
            'active_kwh' => $latest['energy_kwh'] === null
                ? null
                : (float) $latest['energy_kwh'],
            'reactive_kvarh' => null
        ],

        'run_hours' => null,
        'timestamp' => $latest['measured_at'],
        'history' => $chartHistory
    ]);

    exit;
}

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
        ]);

        exit;
    }

    $fields = [
        'voltage' => 'd',
        'current_a' => 'd',
        'power_w' => 'd',
        'energy_kwh' => 'd',
        'frequency_hz' => 'd',
        'power_factor' => 'd'
    ];

    $values = [];

    foreach ($fields as $field => $type) {
        $value = $input[$field] ?? null;

        if ($value !== null && (
            !is_numeric($value) ||
            !is_finite((float) $value)
        )) {
            http_response_code(422);

            echo json_encode([
                'success' => false,
                'message' => "Nilai $field harus berupa angka atau null."
            ]);

            exit;
        }

        $values[$field] = $value === null
            ? null
            : (float) $value;
    }

    $source = $input['source'] ?? 'manual';

    if (!is_string($source) ||
        strlen($source) > 50 ||
        trim($source) === '') {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => 'Source harus berupa teks maksimal 50 karakter.'
        ]);

        exit;
    }

    $stmt = $conn->prepare(
        "INSERT INTO energy_readings
        (voltage, current_a, power_w, energy_kwh,
         frequency_hz, power_factor, source)
        VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    $stmt->bind_param(
        'dddddds',
        $values['voltage'],
        $values['current_a'],
        $values['power_w'],
        $values['energy_kwh'],
        $values['frequency_hz'],
        $values['power_factor'],
        $source
    );

    $stmt->execute();

    http_response_code(201);

    echo json_encode([
        'success' => true,
        'message' => 'Data pengukuran berhasil disimpan.',
        'id' => $conn->insert_id
    ]);

    exit;
}

header('Allow: GET, POST');
http_response_code(405);

echo json_encode([
    'success' => false,
    'message' => 'Metode HTTP tidak didukung.'
]);
