
<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

header('Content-Type: application/json; charset=utf-8');

$host = 'localhost';
$username = 'root';
$password = '';
$database = 'voltview_db';

try {
    $conn = new mysqli(
        $host,
        $username,
        $password,
        $database
    );

    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Koneksi database gagal.'
    ]);

    exit;
}
