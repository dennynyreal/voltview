<?php
ob_start();

require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Metode tidak didukung.');
}

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('Ekstensi PHP ZipArchive tidak tersedia.');
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    error_log('VoltView export: koneksi MySQLi $conn tidak tersedia.');
    http_response_code(500);
    exit('Koneksi database tidak tersedia.');
}

// --------------------------------------------------
// 1. Filter periode
// --------------------------------------------------

$ranges = [
    'day'   => 1,
    'week'  => 7,
    'month' => 30,
];

$range = $_GET['range'] ?? 'day';

if (!isset($ranges[$range])) {
    http_response_code(400);
    exit('Periode tidak valid.');
}

$days = $ranges[$range];

$periodLabels = [
    'day'   => '24 jam terakhir',
    'week'  => '7 hari terakhir',
    'month' => '30 hari terakhir',
];

// --------------------------------------------------
// 2. Ambil seluruh kolom energy_readings
// --------------------------------------------------

$columns = [
    'id',
    'measured_at',
    'voltage',
    'current_a',
    'power_w',
    'energy_kwh',
    'frequency_hz',
    'power_factor',
    'source',
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
    'avg_load_percent',
];

$labels = [
    'id'                  => 'ID',
    'measured_at'         => 'Waktu Pengukuran',
    'voltage'             => 'Tegangan (V)',
    'current_a'           => 'Arus (A)',
    'power_w'             => 'Daya Aktif (W)',
    'energy_kwh'          => 'Energi Aktif (kWh)',
    'frequency_hz'        => 'Frekuensi (Hz)',
    'power_factor'        => 'Faktor Daya',
    'source'              => 'Sumber Data',
    'voltage_ln_r'        => 'Tegangan L-N R (V)',
    'voltage_ln_s'        => 'Tegangan L-N S (V)',
    'voltage_ln_t'        => 'Tegangan L-N T (V)',
    'voltage_ll_rs'       => 'Tegangan L-L RS (V)',
    'voltage_ll_st'       => 'Tegangan L-L ST (V)',
    'voltage_ll_tr'       => 'Tegangan L-L TR (V)',
    'current_r'           => 'Arus Fasa R (A)',
    'current_s'           => 'Arus Fasa S (A)',
    'current_t'           => 'Arus Fasa T (A)',
    'current_n'           => 'Arus Netral (A)',
    'reactive_power_kvar' => 'Daya Reaktif (kvar)',
    'apparent_power_kva'  => 'Daya Semu (kVA)',
    'reactive_energy_kvarh' => 'Energi Reaktif (kvarh)',
    'run_hours'           => 'Jam Operasi',
    'avg_load_percent'    => 'Beban Rata-rata (%)',
];

$sql = 'SELECT ' . implode(', ', $columns) . "
        FROM energy_readings
        WHERE measured_at >= NOW() - INTERVAL {$days} DAY
        ORDER BY measured_at ASC, id ASC";

$result = $conn->query($sql);

if (!$result) {
    error_log('VoltView export SQL: ' . $conn->error);
    http_response_code(500);
    exit('Gagal mengambil data pengukuran.');
}

$data = [];

while ($record = $result->fetch_assoc()) {
    $data[] = $record;
}

$result->free();

// --------------------------------------------------
// 3. Helper XML dan sel Excel
// --------------------------------------------------

function xlsxEscape($value): string
{
    $value = (string) ($value ?? '');

    // Hilangkan karakter kontrol yang tidak valid dalam XML.
    $value = preg_replace(
        '/[\x00-\x08\x0B\x0C\x0E-\x1F]/',
        '',
        $value
    );

    return htmlspecialchars(
        $value ?? '',
        ENT_XML1 | ENT_QUOTES,
        'UTF-8'
    );
}

function xlsxColumn(int $number): string
{
    $letters = '';

    while ($number > 0) {
        $remainder = ($number - 1) % 26;
        $letters = chr(65 + $remainder) . $letters;
        $number = intdiv($number - 1, 26);
    }

    return $letters;
}

/**
 * Sel berbentuk:
 * ['v' => nilai, 's' => indeks style, 't' => 's' atau 'n']
 *
 * t=s: teks inline
 * t=n: angka
 */
function xlsxCell(
    int $column,
    int $row,
    $value,
    int $style = 0,
    string $type = 's'
): array {
    return [
        'ref' => xlsxColumn($column) . $row,
        'v' => $value,
        's' => $style,
        't' => $type,
    ];
}

function xlsxSheetXml(
    array $rows,
    int $maxColumn,
    array $options = []
): string {
$maxRow = max(1, max(array_keys($rows)));
    $lastColumn = xlsxColumn($maxColumn);

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ';
    $xml .= 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

    $xml .= '<dimension ref="A1:' . $lastColumn . $maxRow . '"/>';

    $xml .= '<sheetViews><sheetView workbookViewId="0">';

    if (!empty($options['freeze'])) {
        $xml .= '<pane ySplit="1" topLeftCell="A2" ';
        $xml .= 'activePane="bottomLeft" state="frozen"/>';
    }

    $xml .= '</sheetView></sheetViews>';

    $xml .= '<sheetFormatPr defaultRowHeight="20"/>';

    if (!empty($options['widths'])) {
        $xml .= '<cols>';

        foreach ($options['widths'] as $index => $width) {
            $number = (int) $index;

            $xml .= '<col min="' . $number . '" max="' . $number . '" ';
            $xml .= 'width="' . (float) $width . '" customWidth="1"/>';
        }

        $xml .= '</cols>';
    }

    $xml .= '<sheetData>';

    foreach ($rows as $rowNumber => $cells) {
        $xml .= '<row r="' . $rowNumber . '"';

        if ($rowNumber === 1 && !empty($options['titleHeight'])) {
            $xml .= ' ht="' . (float) $options['titleHeight'] . '" customHeight="1"';
        }

        $xml .= '>';

        foreach ($cells as $cell) {
            $reference = $cell['ref'];
            $style = (int) $cell['s'];
            $value = $cell['v'];

            $xml .= '<c r="' . $reference . '" s="' . $style . '"';

            if ($cell['t'] === 'n' && $value !== null && $value !== '') {
                $xml .= '><v>' . xlsxEscape($value) . '</v></c>';
            } elseif ($value === null || $value === '') {
                $xml .= '/>';
            } else {
                $xml .= ' t="inlineStr"><is><t xml:space="preserve">';
                $xml .= xlsxEscape($value);
                $xml .= '</t></is></c>';
            }
        }

        $xml .= '</row>';
    }

    $xml .= '</sheetData>';

    if (!empty($options['autoFilter'])) {
        $xml .= '<autoFilter ref="A1:' . $lastColumn . $maxRow . '"/>';
    }

    if (!empty($options['merges'])) {
        $xml .= '<mergeCells count="' . count($options['merges']) . '">';

        foreach ($options['merges'] as $merge) {
            $xml .= '<mergeCell ref="' . $merge . '"/>';
        }

        $xml .= '</mergeCells>';
    }

    $xml .= '<pageMargins left="0.3" right="0.3" top="0.5" ';
    $xml .= 'bottom="0.5" header="0.2" footer="0.2"/>';

    $xml .= '</worksheet>';

    return $xml;
}

// --------------------------------------------------
// 4. Ringkasan laporan
// --------------------------------------------------

$summaryRows = [];

$summaryRows[1] = [
    xlsxCell(1, 1, 'VOLT VIEW | LAPORAN PENGUKURAN ENERGI', 1),
];

$summaryRows[3] = [
    xlsxCell(1, 3, 'Informasi Laporan', 2),
    xlsxCell(2, 3, 'Nilai', 2),
];

$summaryItems = [
    ['Periode', $periodLabels[$range], 's'],
    ['Waktu Ekspor', date('Y-m-d H:i:s'), 's'],
    ['Jumlah Sampel', count($data), 'n'],
    ['Sumber Data', 'Database energy_readings', 's'],
];

foreach ($summaryItems as $index => $item) {
    $rowNumber = $index + 4;

    $summaryRows[$rowNumber] = [
        xlsxCell(1, $rowNumber, $item[0], 3),
        xlsxCell(2, $rowNumber, $item[1], 5, $item[2]),
    ];
}

$summaryRows[9] = [
    xlsxCell(1, 9, 'Catatan', 2),
    xlsxCell(2, 9, 'Keterangan', 2),
];

$summaryRows[10] = [
    xlsxCell(1, 10, 'Rentang data', 3),
    xlsxCell(2, 10, 'Berdasarkan waktu pengukuran pada database.', 5),
];

$summaryRows[11] = [
    xlsxCell(1, 11, 'Urutan data', 3),
    xlsxCell(2, 11, 'Waktu terlama ke waktu terbaru.', 5),
];

$summaryXml = xlsxSheetXml($summaryRows, 4, [
    'widths' => [1 => 28, 2 => 48, 3 => 15, 4 => 15],
    'merges' => ['A1:D1'],
    'titleHeight' => 32,
]);

// --------------------------------------------------
// 5. Data pengukuran
// --------------------------------------------------

$measurementRows = [];

$headerCells = [];

foreach ($columns as $index => $column) {
    $headerCells[] = xlsxCell(
        $index + 1,
        1,
        $labels[$column],
        2
    );
}

$measurementRows[1] = $headerCells;

foreach ($data as $rowIndex => $record) {
    $excelRow = $rowIndex + 2;
    $cells = [];

    foreach ($columns as $columnIndex => $column) {
        $value = $record[$column] ?? null;
        $isText = in_array(
            $column,
            ['measured_at', 'source'],
            true
        );

        if ($value === null || $value === '') {
            $value = null;
            $type = 's';
        } elseif (!$isText && is_numeric($value)) {
            $value = (string) $value;
            $type = 'n';
        } else {
            $value = (string) $value;
            $type = 's';
        }

        $cells[] = xlsxCell(
            $columnIndex + 1,
            $excelRow,
            $value,
            4,
            $type
        );
    }

    $measurementRows[$excelRow] = $cells;
}

$measurementWidths = [
    1 => 10, 2 => 22, 3 => 15, 4 => 15,
    5 => 18, 6 => 20, 7 => 17, 8 => 16,
    9 => 18, 10 => 19, 11 => 19, 12 => 19,
    13 => 19, 14 => 19, 15 => 17, 16 => 17,
    17 => 17, 18 => 17, 19 => 20, 20 => 20,
    21 => 22, 22 => 16, 23 => 21, 24 => 18,
];

$measurementXml = xlsxSheetXml(
    $measurementRows,
    count($columns),
    [
        'widths' => $measurementWidths,
        'freeze' => true,
        'autoFilter' => true,
    ]
);

// --------------------------------------------------
// 6. Analisis statistik
// --------------------------------------------------

$analysisRows = [];

$analysisRows[1] = [
    xlsxCell(1, 1, 'Parameter', 2),
    xlsxCell(2, 1, 'Satuan', 2),
    xlsxCell(3, 1, 'Minimum', 2),
    xlsxCell(4, 1, 'Maksimum', 2),
    xlsxCell(5, 1, 'Rata-rata', 2),
    xlsxCell(6, 1, 'Jumlah Sampel', 2),
];

$metrics = [
    ['voltage', 'Tegangan', 'V'],
    ['current_a', 'Arus', 'A'],
    ['power_w', 'Daya Aktif', 'W'],
    ['energy_kwh', 'Energi Aktif', 'kWh'],
    ['frequency_hz', 'Frekuensi', 'Hz'],
    ['power_factor', 'Faktor Daya', '-'],
    ['voltage_ln_r', 'Tegangan L-N R', 'V'],
    ['voltage_ln_s', 'Tegangan L-N S', 'V'],
    ['voltage_ln_t', 'Tegangan L-N T', 'V'],
    ['voltage_ll_rs', 'Tegangan L-L RS', 'V'],
    ['voltage_ll_st', 'Tegangan L-L ST', 'V'],
    ['voltage_ll_tr', 'Tegangan L-L TR', 'V'],
    ['current_r', 'Arus Fasa R', 'A'],
    ['current_s', 'Arus Fasa S', 'A'],
    ['current_t', 'Arus Fasa T', 'A'],
    ['current_n', 'Arus Netral', 'A'],
    ['reactive_power_kvar', 'Daya Reaktif', 'kvar'],
    ['apparent_power_kva', 'Daya Semu', 'kVA'],
    ['reactive_energy_kvarh', 'Energi Reaktif', 'kvarh'],
    ['run_hours', 'Jam Operasi', 'jam'],
    ['avg_load_percent', 'Beban Rata-rata', '%'],
];

foreach ($metrics as $index => [$field, $name, $unit]) {
    $values = [];

    foreach ($data as $record) {
        if (
            isset($record[$field]) &&
            $record[$field] !== '' &&
            is_numeric($record[$field])
        ) {
            $values[] = (float) $record[$field];
        }
    }

    $rowNumber = $index + 2;
    $count = count($values);

    $min = $count ? min($values) : null;
    $max = $count ? max($values) : null;
    $average = $count ? array_sum($values) / $count : null;

    $analysisRows[$rowNumber] = [
        xlsxCell(1, $rowNumber, $name, 5),
        xlsxCell(2, $rowNumber, $unit, 5),
        xlsxCell(3, $rowNumber, $min, 4, 'n'),
        xlsxCell(4, $rowNumber, $max, 4, 'n'),
        xlsxCell(5, $rowNumber, $average, 4, 'n'),
        xlsxCell(6, $rowNumber, $count, 4, 'n'),
    ];
}

$analysisXml = xlsxSheetXml($analysisRows, 6, [
    'widths' => [
        1 => 25, 2 => 15, 3 => 18,
        4 => 18, 5 => 18, 6 => 20,
    ],
    'freeze' => true,
    'autoFilter' => true,
]);

// --------------------------------------------------
// 7. Styles workbook
// --------------------------------------------------

$stylesXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="3">
    <font><sz val="11"/><name val="Arial"/></font>
    <font><b/><sz val="16"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>
    <font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>
  </fonts>
  <fills count="4">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF142D4E"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF167D68"/><bgColor indexed="64"/></patternFill></fill>
  </fills>
  <borders count="2">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border>
      <left style="thin"><color rgb="FFD9E2F3"/></left>
      <right style="thin"><color rgb="FFD9E2F3"/></right>
      <top style="thin"><color rgb="FFD9E2F3"/></top>
      <bottom style="thin"><color rgb="FFD9E2F3"/></bottom>
      <diagonal/>
    </border>
  </borders>
  <cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
  </cellStyleXfs>
  <cellXfs count="6">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="0" applyAlignment="1" xfId="0">
      <alignment vertical="center"/>
    </xf>
    <xf numFmtId="0" fontId="2" fillId="3" borderId="1" applyAlignment="1" xfId="0">
      <alignment vertical="center" horizontal="center" wrapText="1"/>
    </xf>
    <xf numFmtId="0" fontId="2" fillId="3" borderId="1" applyAlignment="1" xfId="0">
      <alignment vertical="center"/>
    </xf>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyAlignment="1" xfId="0">
      <alignment vertical="center"/>
    </xf>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyAlignment="1" xfId="0">
      <alignment vertical="center" wrapText="1"/>
    </xf>
  </cellXfs>
</styleSheet>
XML;

// --------------------------------------------------
// 8. Susun paket XLSX
// --------------------------------------------------

$workbookXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
 xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Ringkasan" sheetId="1" r:id="rId1"/>
    <sheet name="Data Pengukuran" sheetId="2" r:id="rId2"/>
    <sheet name="Analisis" sheetId="3" r:id="rId3"/>
  </sheets>
  <calcPr calcId="191029"/>
</workbook>
XML;

$workbookRels = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>
  <Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML;

$rootRels = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML;

$contentTypes = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>
XML;

$tempFile = tempnam(sys_get_temp_dir(), 'voltview_');

if ($tempFile === false) {
    http_response_code(500);
    exit('Tidak dapat membuat file sementara.');
}

$zip = new ZipArchive();
$opened = $zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

if ($opened !== true) {
    @unlink($tempFile);
    http_response_code(500);
    exit('Gagal membuat berkas Excel.');
}

$files = [
    '[Content_Types].xml' => $contentTypes,
    '_rels/.rels' => $rootRels,
    'xl/workbook.xml' => $workbookXml,
    'xl/_rels/workbook.xml.rels' => $workbookRels,
    'xl/styles.xml' => $stylesXml,
    'xl/worksheets/sheet1.xml' => $summaryXml,
    'xl/worksheets/sheet2.xml' => $measurementXml,
    'xl/worksheets/sheet3.xml' => $analysisXml,
];

foreach ($files as $path => $contents) {
    if (!$zip->addFromString($path, $contents)) {
        $zip->close();
        @unlink($tempFile);
        http_response_code(500);
        exit('Gagal menyusun komponen workbook Excel.');
    }
}

if (!$zip->close()) {
    @unlink($tempFile);
    http_response_code(500);
    exit('Gagal menyelesaikan file Excel.');
}

// --------------------------------------------------
// 9. Kirim file unduhan
// --------------------------------------------------

if (!is_file($tempFile) || filesize($tempFile) === 0) {
    @unlink($tempFile);
    http_response_code(500);
    exit('File Excel kosong atau gagal dibuat.');
}

// Bersihkan seluruh buffer agar file XLSX dimulai dengan byte ZIP.
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="voltview-report.xlsx"');
header('Content-Length: ' . filesize($tempFile));
header('Cache-Control: no-store');

readfile($tempFile);
@unlink($tempFile);
exit;