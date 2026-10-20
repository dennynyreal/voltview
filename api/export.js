import ExcelJS from 'exceljs';

const API_URL = process.env.VOLTVIEW_API_URL ||
  `https://${process.env.VERCEL_URL || process.env.VERCEL_PROJECT_PRODUCTION_URL || 'voltview.vercel.app'}/api/voltview`;

const PERIODS = {
  day: { label: '24 jam terakhir' },
  week: { label: '7 hari terakhir' },
  month: { label: '30 hari terakhir' },
};

const COLUMNS = [
  'id', 'measured_at', 'voltage', 'current_a', 'power_w', 'energy_kwh',
  'frequency_hz', 'power_factor', 'source', 'voltage_ln_r', 'voltage_ln_s',
  'voltage_ln_t', 'voltage_ll_rs', 'voltage_ll_st', 'voltage_ll_tr',
  'current_r', 'current_s', 'current_t', 'current_n', 'reactive_power_kvar',
  'apparent_power_kva', 'reactive_energy_kvarh', 'run_hours', 'avg_load_percent',
];

const LABELS = {
  id: 'ID',
  measured_at: 'Waktu Pengukuran',
  voltage: 'Tegangan (V)',
  current_a: 'Arus (A)',
  power_w: 'Daya Aktif (W)',
  energy_kwh: 'Energi Aktif (kWh)',
  frequency_hz: 'Frekuensi (Hz)',
  power_factor: 'Faktor Daya',
  source: 'Sumber Data',
  voltage_ln_r: 'Tegangan L-N R (V)',
  voltage_ln_s: 'Tegangan L-N S (V)',
  voltage_ln_t: 'Tegangan L-N T (V)',
  voltage_ll_rs: 'Tegangan L-L RS (V)',
  voltage_ll_st: 'Tegangan L-L ST (V)',
  voltage_ll_tr: 'Tegangan L-L TR (V)',
  current_r: 'Arus Fasa R (A)',
  current_s: 'Arus Fasa S (A)',
  current_t: 'Arus Fasa T (A)',
  current_n: 'Arus Netral (A)',
  reactive_power_kvar: 'Daya Reaktif (kvar)',
  apparent_power_kva: 'Daya Semu (kVA)',
  reactive_energy_kvarh: 'Energi Reaktif (kvarh)',
  run_hours: 'Jam Operasi',
  avg_load_percent: 'Beban Rata-rata (%)',
};

const METRICS = [
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

const WIDTHS = [
  10, 23, 15, 14, 18, 19, 16, 16, 17, 19, 19, 19,
  19, 19, 19, 16, 16, 16, 16, 21, 20, 21, 15, 21,
];

const COLORS = {
  navy: 'FF142D4E',
  teal: 'FF167D68',
  white: 'FFFFFFFF',
  text: 'FF243247',
  label: 'FF142D4E',
  light: 'FFF0F6FA',
  border: 'FFD9E2F3',
};

function cellBorder() {
  return {
    top: { style: 'thin', color: { argb: COLORS.border } },
    left: { style: 'thin', color: { argb: COLORS.border } },
    bottom: { style: 'thin', color: { argb: COLORS.border } },
    right: { style: 'thin', color: { argb: COLORS.border } },
  };
}

function setHeaderStyle(row) {
  row.height = 26;
  row.eachCell((cell) => {
    cell.font = { name: 'Arial', size: 10, bold: true, color: { argb: COLORS.white } };
    cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: COLORS.teal } };
    cell.alignment = { vertical: 'middle', horizontal: 'center', wrapText: true };
    cell.border = cellBorder();
  });
}

function toCellValue(field, value) {
  if (value === null || value === undefined || value === '') return null;
  if (field === 'measured_at' || field === 'source') return String(value);
  if (typeof value === 'number' && Number.isFinite(value)) return value;
  if (typeof value === 'string' && value.trim() !== '' && Number.isFinite(Number(value))) {
    return Number(value);
  }
  return String(value);
}

function reportDate() {
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Jakarta',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(new Date());
  const part = (type) => parts.find((item) => item.type === type)?.value || '';
  return `${part('year')}-${part('month')}-${part('day')}`;
}

function styleSummary(workbook, range, rowsCount) {
  const sheet = workbook.addWorksheet('Ringkasan');
  sheet.columns = [
    { width: 28 }, { width: 48 }, { width: 15 }, { width: 15 },
  ];
  sheet.mergeCells('A1:D1');
  sheet.getCell('A1').value = 'VOLT VIEW | LAPORAN PENGUKURAN ENERGI';
  sheet.getRow(1).height = 34;
  sheet.getCell('A1').font = { name: 'Arial', size: 16, bold: true, color: { argb: COLORS.white } };
  sheet.getCell('A1').fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: COLORS.navy } };
  sheet.getCell('A1').alignment = { vertical: 'middle', horizontal: 'left' };

  sheet.getRow(3).values = ['Informasi Laporan', 'Nilai'];
  setHeaderStyle(sheet.getRow(3));
  sheet.getRow(3).height = 26;

  const items = [
    ['Periode', PERIODS[range].label],
    ['Waktu Ekspor', new Intl.DateTimeFormat('sv-SE', {
      timeZone: 'Asia/Jakarta', dateStyle: 'short', timeStyle: 'medium',
    }).format(new Date())],
    ['Jumlah Sampel', rowsCount],
    ['Sumber Data', 'API VoltView (Vercel)'],
  ];

  items.forEach(([label, value], index) => {
    const row = sheet.getRow(index + 4);
    row.values = [label, value];
    row.height = 22;
    row.getCell(1).font = { name: 'Arial', size: 10, bold: true, color: { argb: COLORS.label } };
    row.getCell(1).fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: COLORS.light } };
    row.getCell(1).alignment = { vertical: 'middle', wrapText: true };
    row.getCell(2).font = { name: 'Arial', size: 10, color: { argb: COLORS.text } };
    row.getCell(2).alignment = { vertical: 'middle', wrapText: true };
    row.getCell(1).border = cellBorder();
    row.getCell(2).border = cellBorder();
    if (label === 'Jumlah Sampel') row.getCell(2).numFmt = '#,##0';
  });

  sheet.getRow(9).values = ['Catatan', 'Keterangan'];
  setHeaderStyle(sheet.getRow(9));
  sheet.getRow(10).values = ['Rentang data', 'Berdasarkan periode yang diminta kepada API VoltView.'];
  sheet.getRow(11).values = ['Urutan data', 'Mengikuti urutan yang dikirim oleh API.'];
  [10, 11].forEach((rowNumber) => {
    const row = sheet.getRow(rowNumber);
    row.height = 24;
    row.getCell(1).font = { name: 'Arial', size: 10, bold: true, color: { argb: COLORS.label } };
    row.getCell(1).fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: COLORS.light } };
    row.getCell(2).alignment = { vertical: 'middle', wrapText: true };
    row.eachCell((cell) => { cell.border = cellBorder(); });
  });

  sheet.views = [{ state: 'normal' }];
  return sheet;
}

function styleMeasurements(workbook, data) {
  const sheet = workbook.addWorksheet('Data Pengukuran', {
    views: [{ state: 'frozen', ySplit: 1 }],
  });

  sheet.columns = COLUMNS.map((field, index) => ({
    header: LABELS[field],
    key: field,
    width: WIDTHS[index],
  }));

  const prepared = data.map((record) => {
    const item = {};
    for (const field of COLUMNS) item[field] = toCellValue(field, record[field]);
    return item;
  });
  if (prepared.length) sheet.addRows(prepared);

  setHeaderStyle(sheet.getRow(1));
  sheet.autoFilter = {
    from: 'A1',
    to: `${columnLetter(COLUMNS.length)}${Math.max(1, data.length + 1)}`,
  };

  for (let rowNumber = 2; rowNumber <= data.length + 1; rowNumber += 1) {
    const row = sheet.getRow(rowNumber);
    row.height = 22;
    row.eachCell({ includeEmpty: true }, (cell, columnNumber) => {
      const field = COLUMNS[columnNumber - 1];
      cell.font = { name: 'Arial', size: 10, color: { argb: COLORS.text } };
      cell.alignment = { vertical: 'middle' };
      cell.border = cellBorder();
      if (rowNumber % 2 === 0) {
        cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: COLORS.light } };
      }
      if (field === 'id') cell.numFmt = '0';
      else if (field !== 'measured_at' && field !== 'source' && cell.value !== null) {
        cell.numFmt = '#,##0.00;[Red]-#,##0.00';
      }
    });
  }
  return sheet;
}

function columnLetter(number) {
  let result = '';
  while (number > 0) {
    const remainder = (number - 1) % 26;
    result = String.fromCharCode(65 + remainder) + result;
    number = Math.floor((number - 1) / 26);
  }
  return result;
}

function styleAnalysis(workbook, data) {
  const sheet = workbook.addWorksheet('Analisis', {
    views: [{ state: 'frozen', ySplit: 1 }],
  });
  sheet.columns = [
    { header: 'Parameter', key: 'name', width: 27 },
    { header: 'Satuan', key: 'unit', width: 15 },
    { header: 'Minimum', key: 'min', width: 18 },
    { header: 'Maksimum', key: 'max', width: 18 },
    { header: 'Rata-rata', key: 'average', width: 18 },
    { header: 'Jumlah Sampel', key: 'count', width: 20 },
  ];

  for (const [field, name, unit] of METRICS) {
    const values = data
      .map((record) => record[field])
      .filter((value) => value !== null && value !== undefined && value !== '' && Number.isFinite(Number(value)))
      .map(Number);
    const count = values.length;
    const stats = values.reduce((acc, value) => ({
      min: Math.min(acc.min, value),
      max: Math.max(acc.max, value),
      sum: acc.sum + value,
    }), { min: Infinity, max: -Infinity, sum: 0 });
    sheet.addRow({
      name,
      unit,
      min: count ? stats.min : null,
      max: count ? stats.max : null,
      average: count ? stats.sum / count : null,
      count,
    });
  }

  setHeaderStyle(sheet.getRow(1));
  sheet.autoFilter = { from: 'A1', to: `F${METRICS.length + 1}` };
  for (let rowNumber = 2; rowNumber <= METRICS.length + 1; rowNumber += 1) {
    const row = sheet.getRow(rowNumber);
    row.height = 22;
    row.eachCell({ includeEmpty: true }, (cell, columnNumber) => {
      cell.font = { name: 'Arial', size: 10, color: { argb: COLORS.text } };
      cell.alignment = { vertical: 'middle' };
      cell.border = cellBorder();
      if (rowNumber % 2 === 0) {
        cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: COLORS.light } };
      }
      if (columnNumber >= 3 && columnNumber <= 5) cell.numFmt = '#,##0.00;[Red]-#,##0.00';
      if (columnNumber === 6) cell.numFmt = '#,##0';
    });
  }
  return sheet;
}

function sendError(res, status, message, detail) {
  if (detail) console.error(`[VoltView export] ${detail}`);
  if (res.headersSent) return res.end();
  res.statusCode = status;
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  res.setHeader('Cache-Control', 'no-store');
  res.end(JSON.stringify({ success: false, message }));
}

export default async function handler(req, res) {
  if (req.method !== 'GET') {
    res.setHeader('Allow', 'GET');
    return sendError(res, 405, 'Metode tidak didukung.');
  }

  const rangeValue = Array.isArray(req.query?.range) ? req.query.range[0] : req.query?.range;
  const range = rangeValue || 'day';
  if (!Object.prototype.hasOwnProperty.call(PERIODS, range)) {
    return sendError(res, 400, 'Periode tidak valid. Gunakan day, week, atau month.');
  }

  let payload;
  try {
    const url = new URL(API_URL);
    url.searchParams.set('export', '1');
    url.searchParams.set('range', range);

    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 25000);
    let response;
    try {
      response = await fetch(url, {
        method: 'GET',
        headers: { Accept: 'application/json', 'User-Agent': 'VoltView-XLSX-Export/1.0' },
        signal: controller.signal,
        cache: 'no-store',
      });
    } finally {
      clearTimeout(timeout);
    }

    if (!response.ok) {
      return sendError(res, 502, 'API VoltView gagal menyediakan data ekspor.', `API HTTP ${response.status} pada ${url.pathname}`);
    }

    try {
      payload = await response.json();
    } catch (error) {
      return sendError(res, 502, 'Respons API VoltView bukan JSON yang valid.', error instanceof Error ? error.message : String(error));
    }
  } catch (error) {
    const timedOut = error?.name === 'AbortError';
    return sendError(
      res,
      502,
      timedOut ? 'API VoltView terlalu lama merespons.' : 'Gagal menghubungi API VoltView.',
      error instanceof Error ? error.message : String(error),
    );
  }

  if (payload?.success !== true || !Array.isArray(payload.rows)) {
    return sendError(res, 502, 'Format data dari API VoltView tidak valid.', 'Respons wajib memiliki success=true dan rows berupa array.');
  }

  if (payload.rows.some((record) => !record || typeof record !== 'object' || Array.isArray(record))) {
    return sendError(res, 502, 'Struktur baris data pengukuran tidak valid.');
  }

  const data = payload.rows;
  if (data.length === 0) {
    return sendError(res, 404, 'Tidak ada data pada periode yang dipilih.');
  }

  try {
    const workbook = new ExcelJS.Workbook();
    workbook.creator = 'VoltView';
    workbook.lastModifiedBy = 'VoltView';
    workbook.created = new Date();
    workbook.modified = new Date();
    workbook.subject = 'Laporan pengukuran energi gedung';
    workbook.title = 'VoltView - Laporan Pengukuran Energi';
    workbook.company = 'VoltView';
    workbook.properties.date1904 = false;

    styleSummary(workbook, range, data.length);
    styleMeasurements(workbook, data);
    styleAnalysis(workbook, data);

    const buffer = await workbook.xlsx.writeBuffer();
    const fileBuffer = Buffer.isBuffer(buffer) ? buffer : Buffer.from(buffer);
    if (fileBuffer.length === 0) {
      return sendError(res, 500, 'File Excel kosong atau gagal dibuat.');
    }

    const filename = `voltview-report-${range}-${reportDate()}.xlsx`;
    res.statusCode = 200;
    res.setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    res.setHeader('Content-Disposition', `attachment; filename="${filename}"`);
    res.setHeader('Content-Length', String(fileBuffer.length));
    res.setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
    res.setHeader('Pragma', 'no-cache');
    res.setHeader('X-Content-Type-Options', 'nosniff');
    return res.end(fileBuffer);
  } catch (error) {
    return sendError(
      res,
      500,
      'Gagal membentuk file Excel VoltView.',
      error instanceof Error ? error.stack || error.message : String(error),
    );
  }
}
