
<?php
ob_start();

ini_set('display_errors', '0');
error_reporting(E_ALL);

// ==================================================
// 1. VALIDASI REQUEST DAN KONFIGURASI
// ==================================================

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Metode tidak didukung.');
}

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('Ekstensi PHP ZipArchive tidak tersedia.');
}

$apiUrl = 'https://voltview.vercel.app/api/voltview';

$ranges = [
    'day'   => 1,
    'week'  => 7,
    'month' => 30,
];

$periodLabels = [
    'day'   => '24 jam terakhir',
    'week'  => '7 hari terakhir',
    'month' => '30 hari terakhir',
];

$range = $_GET['range'] ?? 'day';

if (!isset($ranges[$range])) {
    http_response_code(400);
    exit('Periode laporan tidak valid.');
}

// ==================================================
// 2. AMBIL DATA DARI VERCEL API
// ==================================================

$queryUrl = $apiUrl . '?' . http_build_query([
    'export' => '1',
    'range'  => $range,
]);

$responseBody = false;
$httpStatus = 0;

if (function_exists('curl_init')) {
    $ch = curl_init($queryUrl);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
        ],
    ]);

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($responseBody === false) {
        error_log('VoltView export cURL: ' . $curlError);
        http_response_code(502);
        exit('Gagal menghubungi API VoltView.');
    }
} else {
    $context = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'timeout'       => 60,
            'header'        => "Accept: application/json\r\n",
            'ignore_errors' => true,
        ],
    ]);

    $responseBody = @file_get_contents(
        $queryUrl,
        false,
        $context
    );

    if (isset($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match(
                '/^HTTP\/\S+\s+(\d+)/',
                $headerLine,
                $matches
            )) {
                $httpStatus = (int) $matches[1];
            }
        }
    }

    if ($responseBody === false) {
        http_response_code(502);
        exit('Gagal menghubungi API VoltView.');
    }
}

if ($httpStatus < 200 || $httpStatus >= 300) {
    error_log('VoltView export API HTTP: ' . $httpStatus);
    http_response_code(502);
    exit('API VoltView gagal menyediakan data ekspor.');
}

$payload = json_decode($responseBody, true);

if (
    !is_array($payload) ||
    ($payload['success'] ?? false) !== true ||
    !isset($payload['rows']) ||
    !is_array($payload['rows'])
) {
    error_log('VoltView export: respons API tidak valid.');
    http_response_code(502);
    exit('Format data dari API VoltView tidak valid.');
}

$data = $payload['rows'];

foreach ($data as $record) {
    if (!is_array($record)) {
        http_response_code(502);
        exit('Struktur baris data tidak valid.');
    }
}

if (count($data) === 0) {
    http_response_code(404);
    exit('Tidak ada data pada periode yang dipilih.');
}

// ==================================================
// 3. DEFINISI KOLOM DAN LABEL
// ==================================================

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
    'id'                    => 'ID',
    'measured_at'           => 'Waktu Pengukuran',
    'voltage'               => 'Tegangan (V)',
    'current_a'             => 'Arus (A)',
    'power_w'               => 'Daya Aktif (W)',
    'energy_kwh'            => 'Energi Aktif (kWh)',
    'frequency_hz'          => 'Frekuensi (Hz)',
    'power_factor'          => 'Faktor Daya',
    'source'                => 'Sumber Data',
    'voltage_ln_r'          => 'Tegangan L-N R (V)',
    'voltage_ln_s'          => 'Tegangan L-N S (V)',
    'voltage_ln_t'          => 'Tegangan L-N T (V)',
    'voltage_ll_rs'         => 'Tegangan L-L RS (V)',
    'voltage_ll_st'         => 'Tegangan L-L ST (V)',
    'voltage_ll_tr'         => 'Tegangan L-L TR (V)',
    'current_r'             => 'Arus Fasa R (A)',
    'current_s'             => 'Arus Fasa S (A)',
    'current_t'             => 'Arus Fasa T (A)',
    'current_n'             => 'Arus Netral (A)',
    'reactive_power_kvar'   => 'Daya Reaktif (kvar)',
    'apparent_power_kva'    => 'Daya Semu (kVA)',
    'reactive_energy_kvarh' => 'Energi Reaktif (kvarh)',
    'run_hours'             => 'Jam Operasi',
    'avg_load_percent'      => 'Beban Rata-rata (%)',
];

// ==================================================
// 4. HELPER XML DAN EXCEL
// ==================================================

function xlsxEscape($value): string
{
    $value = (string) ($value ?? '');

    // Hapus karakter kontrol yang tidak valid untuk XML.
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

function xlsxCell(
    int $column,
    int $row,
    $value,
    int $style = 0,
    string $type = 's'
): array {
    return [
        'ref' => xlsxColumn($column) . $row,
        'v'   => $value,
        's'   => $style,
        't'   => $type,
    ];
}

function xlsxSheetXml(
    array $rows,
    int $maxColumn,
    array $options = []
): string {
    if (empty($rows)) {
        $rows = [
            1 => [
                xlsxCell(1, 1, ''),
            ],
        ];
    }

    ksort($rows);

    $maxRow = max(array_keys($rows));
    $lastColumn = xlsxColumn($maxColumn);

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ';
    $xml .= 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

    $xml .= '<dimension ref="A1:' . $lastColumn . $maxRow . '"/>';

    $xml .= '<sheetViews><sheetView workbookViewId="0">';

    if (!empty($options['freeze'])) {
        $xml .= '<pane ySplit="1" topLeftCell="A2" ';
        $xml .= 'activePane="bottomLeft" state="frozen"/>';
        $xml .= '<selection pane="bottomLeft" activeCell="A2" sqref="A2"/>';
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
        if ($rowNumber === 1 && !empty($options['titleRow'])) {
            $height = 34;
        } elseif ($rowNumber === 1 || $rowNumber === 3) {
            $height = 26;
        } else {
            $height = 22;
        }

        $xml .= '<row r="' . $rowNumber . '" ht="' . $height . '" customHeight="1">';

        foreach ($cells as $cell) {
            $reference = $cell['ref'];
            $style = (int) $cell['s'];
            $value = $cell['v'];
            $type = $cell['t'];

            $xml .= '<c r="' . $reference . '" s="' . $style . '"';

            if (
                $type === 'n' &&
                $value !== null &&
                $value !== '' &&
                is_numeric($value)
            ) {
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

// ==================================================
// 5. SHEET RINGKASAN
// ==================================================

$summaryRows = [];

$summaryRows[1] = [
    xlsxCell(
        1,
        1,
        'VOLT VIEW | LAPORAN PENGUKURAN ENERGI',
        1
    ),
];

$summaryRows[3] = [
    xlsxCell(1, 3, 'INFORMASI LAPORAN', 2),
    xlsxCell(2, 3, 'KETERANGAN', 2),
];

$summaryItems = [
    ['Periode', $periodLabels[$range], 's'],
    ['Waktu Ekspor', date('Y-m-d H:i:s'), 's'],
    ['Jumlah Sampel', count($data), 'n'],
    ['Sumber Data', 'Neon PostgreSQL melalui API Vercel', 's'],
];

foreach ($summaryItems as $index => $item) {
    $rowNumber = $index + 4;

    $summaryRows[$rowNumber] = [
        xlsxCell(1, $rowNumber, $item[0], 3),
        xlsxCell(2, $rowNumber, $item[1], $item[2] === 'n' ? 7 : 5, $item[2]),
    ];
}

$summaryRows[10] = [
    xlsxCell(1, 10, 'CATATAN', 2),
    xlsxCell(2, 10, 'KETERANGAN', 2),
];

$summaryRows[11] = [
    xlsxCell(1, 11, 'Rentang Data', 3),
    xlsxCell(2, 11, 'Berdasarkan waktu pengukuran pada database.', 5),
];

$summaryRows[12] = [
    xlsxCell(1, 12, 'Urutan Data', 3),
    xlsxCell(2, 12, 'Data disajikan mengikuti urutan dari API.', 5),
];

$summaryXml = xlsxSheetXml($summaryRows, 4, [
    'titleRow' => true,
    'widths' => [
        1 => 28,
        2 => 48,
        3 => 15,
        4 => 15,
    ],
    'merges' => ['A1:D1'],
]);

// ==================================================
// 6. SHEET DATA PENGUKURAN
// ==================================================

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

        // Baris selang-seling untuk meningkatkan keterbacaan.
        $alternate = ($excelRow % 2 === 0);

        if ($type === 'n') {
            $style = $alternate ? 8 : 7;
        } else {
            $style = $alternate ? 6 : 4;
        }

        $cells[] = xlsxCell(
            $columnIndex + 1,
            $excelRow,
            $value,
            $style,
            $type
        );
    }

    $measurementRows[$excelRow] = $cells;
}

$measurementWidths = [
    1  => 9,
    2  => 23,
    3  => 15,
    4  => 14,
    5  => 18,
    6  => 19,
    7  => 16,
    8  => 16,
    9  => 17,
    10 => 19,
    11 => 19,
    12 => 19,
    13 => 19,
    14 => 19,
    15 => 19,
    16 => 16,
    17 => 16,
    18 => 16,
    19 => 16,
    20 => 21,
    21 => 20,
    22 => 21,
    23 => 15,
    24 => 21,
];

$measurementXml = xlsxSheetXml(
    $measurementRows,
    count($columns),
    [
        'widths'    => $measurementWidths,
        'freeze'    => true,
        'autoFilter' => true,
    ]
);

// ==================================================
// 7. SHEET ANALISIS STATISTIK
// ==================================================

$analysisRows = [];

$analysisRows[1] = [
    xlsxCell(1, 1, 'PARAMETER', 2),
    xlsxCell(2, 1, 'SATUAN', 2),
    xlsxCell(3, 1, 'MINIMUM', 2),
    xlsxCell(4, 1, 'MAKSIMUM', 2),
    xlsxCell(5, 1, 'RATA-RATA', 2),
    xlsxCell(6, 1, 'JUMLAH SAMPEL', 2),
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

    $min = $count > 0 ? min($values) : null;
    $max = $count > 0 ? max($values) : null;
    $avg = $count > 0 ? array_sum($values) / $count : null;

    $alternate = ($rowNumber % 2 === 0);
    $textStyle = $alternate ? 6 : 4;
    $numberStyle = $alternate ? 8 : 7;

    $analysisRows[$rowNumber] = [
        xlsxCell(1, $rowNumber, $name, 3),
        xlsxCell(2, $rowNumber, $unit, $textStyle),
        xlsxCell(3, $rowNumber, $min, $numberStyle, 'n'),
        xlsxCell(4, $rowNumber, $max, $numberStyle, 'n'),
        xlsxCell(5, $rowNumber, $avg, $numberStyle, 'n'),
        xlsxCell(6, $rowNumber, $count, $textStyle, 'n'),
    ];
}

$analysisXml = xlsxSheetXml($analysisRows, 6, [
    'freeze' => true,
    'autoFilter' => true,
    'widths' => [
        1 => 27,
        2 => 15,
        3 => 18,
        4 => 18,
        5 => 18,
        6 => 20,
    ],
]);

// ==================================================
// 8. STYLES WORKBOOK
// ==================================================

$stylesXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">

  <numFmts count="1">
    <numFmt numFmtId="164" formatCode="#,##0.00;[Red]-#,##0.00"/>
  </numFmts>

  <fonts count="4">
    <font><sz val="10"/><name val="Arial"/><color rgb="FF243247"/></font>
    <font><b/><sz val="16"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>
    <font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>
    <font><b/><sz val="10"/><color rgb="FF142D4E"/><name val="Arial"/></font>
  </fonts>

  <fills count="5">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF142D4E"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF167D68"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF0F6FA"/><bgColor indexed="64"/></patternFill></fill>
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

  <cellXfs count="9">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center" horizontal="left"/></xf>
    <xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="center" horizontal="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="center"/></xf>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="center"/></xf>
    <xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="center" horizontal="right"/></xf>
    <xf numFmtId="164" fontId="0" fillId="4" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="center" horizontal="right"/></xf>
  </cellXfs>
</styleSheet>
XML;

// ==================================================
// 9. SUSUN KOMPONEN WORKBOOK XLSX
// ==================================================

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

// ==================================================
// 10. BUAT FILE XLSX
// ==================================================

$tempFile = tempnam(sys_get_temp_dir(), 'voltview_');

if ($tempFile === false) {
    http_response_code(500);
    exit('Tidak dapat membuat file sementara.');
}

$zip = new ZipArchive();

$opened = $zip->open(
    $tempFile,
    ZipArchive::CREATE | ZipArchive::OVERWRITE
);

if ($opened !== true) {
    @unlink($tempFile);
    http_response_code(500);
    exit('Gagal membuat file Excel.');
}

$files = [
    '[Content_Types].xml'         => $contentTypes,
    '_rels/.rels'                 => $rootRels,
    'xl/workbook.xml'             => $workbookXml,
    'xl/_rels/workbook.xml.rels'  => $workbookRels,
    'xl/styles.xml'               => $stylesXml,
    'xl/worksheets/sheet1.xml'    => $summaryXml,
    'xl/worksheets/sheet2.xml'    => $measurementXml,
    'xl/worksheets/sheet3.xml'    => $analysisXml,
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

// ==================================================
// 11. KIRIM FILE EXCEL KE BROWSER
// ==================================================

if (!is_file($tempFile) || filesize($tempFile) === 0) {
    @unlink($tempFile);
    http_response_code(500);
    exit('File Excel kosong atau gagal dibuat.');
}

$fileSize = filesize($tempFile);
$date = date('Y-m-d');
$filename = 'voltview-report-' . $range . '-' . $date . '.xlsx';

// Hapus seluruh output buffer agar file tidak rusak.
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . $fileSize);
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

readfile($tempFile);

@unlink($tempFile);
exit;
