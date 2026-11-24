const { neon } = require("@neondatabase/serverless");

const sql = neon(process.env.DATABASE_URL);

// ============================================================
// 1. CORS
// ============================================================

const allowedOrigins = [
  "https://voltview.vercel.app",
  "https://autoships.joss.site.je",
  "http://localhost",
  "http://127.0.0.1"
];

function setCors(req, res) {
  const origin = req.headers.origin || "";

  const isLocalhost =
    /^http:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/.test(origin);

  if (allowedOrigins.includes(origin) || isLocalhost) {
    res.setHeader("Access-Control-Allow-Origin", origin);
  }

  res.setHeader("Vary", "Origin");
  res.setHeader("Access-Control-Allow-Methods", "GET, POST, OPTIONS");
  res.setHeader("Access-Control-Allow-Headers", "Content-Type, Authorization");
  res.setHeader("Access-Control-Max-Age", "86400");
}

// ============================================================
// 2. HELPER
// ============================================================

function numberOrNull(value) {
  if (value === null || value === undefined || value === "") {
    return null;
  }

  const number = Number(value);

  return Number.isFinite(number) ? number : null;
}

function average(values) {
  const valid = values.filter(
    value => value !== null && value !== undefined &&
      Number.isFinite(Number(value))
  );

  if (!valid.length) return null;

  return valid.reduce((sum, value) => sum + Number(value), 0) /
    valid.length;
}

// ============================================================
// 3. FIELD DATABASE
// ============================================================

const fields = [
  "voltage",
  "current_a",
  "power_w",
  "energy_kwh",
  "frequency_hz",
  "power_factor",

  "voltage_ln_r",
  "voltage_ln_s",
  "voltage_ln_t",

  "voltage_ll_rs",
  "voltage_ll_st",
  "voltage_ll_tr",

  "current_r",
  "current_s",
  "current_t",
  "current_n",

  "reactive_power_kvar",
  "apparent_power_kva",
  "reactive_energy_kvarh",

  "run_hours",
  "avg_load_percent"
];

// ============================================================
// 4. FORMAT ROW UNTUK DASHBOARD
// ============================================================

function readRow(row) {
  if (!row) return null;

  const voltageLn = {
    r: numberOrNull(row.voltage_ln_r),
    s: numberOrNull(row.voltage_ln_s),
    t: numberOrNull(row.voltage_ln_t)
  };

  const voltageLl = {
    rs: numberOrNull(row.voltage_ll_rs),
    st: numberOrNull(row.voltage_ll_st),
    tr: numberOrNull(row.voltage_ll_tr)
  };

  voltageLn.avg = average([
    voltageLn.r,
    voltageLn.s,
    voltageLn.t
  ]);

  voltageLl.avg = average([
    voltageLl.rs,
    voltageLl.st,
    voltageLl.tr
  ]);

  const current = {
    r: numberOrNull(row.current_r),
    s: numberOrNull(row.current_s),
    t: numberOrNull(row.current_t),
    n: numberOrNull(row.current_n)
  };

  current.avg = average([
    current.r,
    current.s,
    current.t
  ]);

  const power = {
    active_kw: row.power_w !== null
      ? numberOrNull(row.power_w) / 1000
      : null,

    reactive_kvar: numberOrNull(row.reactive_power_kvar),

    apparent_kva: numberOrNull(row.apparent_power_kva),

    power_factor: numberOrNull(row.power_factor),

    avg_load_percent: numberOrNull(row.avg_load_percent)
  };

  const energy = {
    active_kwh: numberOrNull(row.energy_kwh),
    reactive_kvarh: numberOrNull(row.reactive_energy_kvarh)
  };

  return {
    id: row.id,

    voltage: {
      ln: voltageLn,
      ll: voltageLl
    },

    current,

    power,

    energy,

    run_hours: numberOrNull(row.run_hours),

    source: row.source || "UNKNOWN",

    // Timestamp data pengukuran terakhir yang diperbarui.
    timestamp: row.updated_at || row.measured_at
  };
}

// ============================================================
// 5. FORMAT HISTORY UNTUK CHART
// ============================================================

function readHistory(row) {
  return {
    label: row.measured_at,

    timestamp: row.measured_at,

    voltage: numberOrNull(row.voltage),

    current_a: numberOrNull(row.current_a),

    active_kw: row.power_w !== null
      ? numberOrNull(row.power_w) / 1000
      : null,

    reactive_kvar: numberOrNull(row.reactive_power_kvar),

    apparent_kva: numberOrNull(row.apparent_power_kva)
  };
}

// ============================================================
// 6. PERIODE HISTORY
// ============================================================

const rangeIntervals = {
  day: "24 hours",
  week: "7 days",
  month: "30 days"
};

function getRange(req) {
  const range = req.query.range;

  return Object.prototype.hasOwnProperty.call(
    rangeIntervals,
    range
  )
    ? range
    : "day";
}

// ============================================================
// 7. MAIN HANDLER
// ============================================================

module.exports = async function handler(req, res) {
  setCors(req, res);

  // ----------------------------------------------------------
  // OPTIONS
  // ----------------------------------------------------------

  if (req.method === "OPTIONS") {
    return res.status(204).end();
  }

  // ----------------------------------------------------------
  // METHOD CHECK
  // ----------------------------------------------------------

  if (!["GET", "POST"].includes(req.method)) {
    res.setHeader("Allow", "GET, POST, OPTIONS");

    return res.status(405).json({
      success: false,
      message: "Method not allowed."
    });
  }

  try {
    // ========================================================
    // 8. POST — RECEIVE DATA ESP32
    // ========================================================

    if (req.method === "POST") {
      const body = req.body;

      if (!body || typeof body !== "object" || Array.isArray(body)) {
        return res.status(400).json({
          success: false,
          message: "Payload JSON tidak valid."
        });
      }

      const values = fields.map(field =>
        numberOrNull(body[field])
      );

      // ------------------------------------------------------
      // VALIDATION
      // ------------------------------------------------------

      const requiredFields = [
        "voltage",
        "current_a",
        "power_w",
        "energy_kwh",
        "frequency_hz",
        "power_factor"
      ];

      for (const field of requiredFields) {
        if (numberOrNull(body[field]) === null) {
          return res.status(400).json({
            success: false,
            message: `Field ${field} tidak valid atau tidak tersedia.`
          });
        }
      }

      if (
        numberOrNull(body.power_factor) < 0 ||
        numberOrNull(body.power_factor) > 1
      ) {
        return res.status(400).json({
          success: false,
          message: "Power factor harus berada pada rentang 0 sampai 1."
        });
      }

      if (
        numberOrNull(body.avg_load_percent) !== null &&
        (
          numberOrNull(body.avg_load_percent) < 0 ||
          numberOrNull(body.avg_load_percent) > 100
        )
      ) {
        return res.status(400).json({
          success: false,
          message: "Persentase beban harus 0 sampai 100."
        });
      }

      const source = String(body.source || "UNKNOWN").slice(0, 80);

      // ------------------------------------------------------
      // INSERT / UPDATE BERDASARKAN INTERVAL 30 MENIT
      //
      // INSERT:
      //   Jika belum ada data, atau data historis terakhir
      //   sudah mencapai 30 menit.
      //
      // UPDATE:
      //   Jika data terakhir masih dalam interval 30 menit.
      //
      // measured_at dipertahankan pada saat interval dimulai.
      // updated_at diubah setiap POST.
      //
      // Advisory lock mencegah dua request simultan membuat
      // baris baru pada interval yang sama.
      // ------------------------------------------------------

      const result = await sql`
        WITH lock_cte AS MATERIALIZED (
          SELECT pg_advisory_xact_lock(731202630)
        ),

        latest AS (
          SELECT
            id,
            measured_at
          FROM energy_readings
          CROSS JOIN lock_cte
          ORDER BY measured_at DESC, id DESC
          LIMIT 1
        ),

        updated AS (
          UPDATE energy_readings AS e
          SET
            voltage = ${values[0]},
            current_a = ${values[1]},
            power_w = ${values[2]},
            energy_kwh = ${values[3]},
            frequency_hz = ${values[4]},
            power_factor = ${values[5]},

            voltage_ln_r = ${values[6]},
            voltage_ln_s = ${values[7]},
            voltage_ln_t = ${values[8]},

            voltage_ll_rs = ${values[9]},
            voltage_ll_st = ${values[10]},
            voltage_ll_tr = ${values[11]},

            current_r = ${values[12]},
            current_s = ${values[13]},
            current_t = ${values[14]},
            current_n = ${values[15]},

            reactive_power_kvar = ${values[16]},
            apparent_power_kva = ${values[17]},
            reactive_energy_kvarh = ${values[18]},

            run_hours = ${values[19]},
            avg_load_percent = ${values[20]},

            source = ${source},
            updated_at = NOW()

          FROM latest

          WHERE e.id = latest.id
            AND latest.measured_at > NOW() - INTERVAL '30 minutes'

          RETURNING e.id
        ),

        inserted AS (
          INSERT INTO energy_readings (
            voltage,
            current_a,
            power_w,
            energy_kwh,
            frequency_hz,
            power_factor,

            voltage_ln_r,
            voltage_ln_s,
            voltage_ln_t,

            voltage_ll_rs,
            voltage_ll_st,
            voltage_ll_tr,

            current_r,
            current_s,
            current_t,
            current_n,

            reactive_power_kvar,
            apparent_power_kva,
            reactive_energy_kvarh,

            run_hours,
            avg_load_percent,

            source,
            measured_at,
            updated_at
          )

          SELECT
            ${values[0]},
            ${values[1]},
            ${values[2]},
            ${values[3]},
            ${values[4]},
            ${values[5]},

            ${values[6]},
            ${values[7]},
            ${values[8]},

            ${values[9]},
            ${values[10]},
            ${values[11]},

            ${values[12]},
            ${values[13]},
            ${values[14]},
            ${values[15]},

            ${values[16]},
            ${values[17]},
            ${values[18]},

            ${values[19]},
            ${values[20]},

            ${source},
            NOW(),
            NOW()

          FROM lock_cte

          WHERE NOT EXISTS (
            SELECT 1
            FROM latest
            WHERE latest.measured_at > NOW() - INTERVAL '30 minutes'
          )

          RETURNING id
        )

        SELECT
          'updated' AS action,
          id
        FROM updated

        UNION ALL

        SELECT
          'inserted' AS action,
          id
        FROM inserted
      `;

      const action = result[0]?.action || "unknown";

      return res.status(action === "inserted" ? 201 : 200).json({
        success: true,

        message: action === "inserted"
          ? "Data historis baru berhasil disimpan."
          : "Data pengukuran terbaru berhasil diperbarui.",

        saved_to_database: true,

        action,

        interval_minutes: 30
      });
    }

    // ========================================================
    // 9. GET — EXPORT EXCEL
    // ========================================================

    if (req.method === "GET" && req.query.export === "1") {
      const range = getRange(req);
      const interval = rangeIntervals[range];

      const rows = await sql`
        SELECT *
        FROM energy_readings
        WHERE measured_at >= NOW() - ${interval}::interval
        ORDER BY measured_at ASC
        LIMIT 50000
      `;

      return res.status(200).json({
        success: true,
        range,
        count: rows.length,
        rows
      });
    }

    // ========================================================
    // 10. GET — DASHBOARD
    // ========================================================

    const range = getRange(req);
    const interval = rangeIntervals[range];

    // Ambil record terbaru untuk KPI.
    const latestRows = await sql`
      SELECT *
      FROM energy_readings
      ORDER BY measured_at DESC, id DESC
      LIMIT 1
    `;

    // Ambil data historis sesuai periode grafik.
    const historyRows = await sql`
      SELECT *
      FROM energy_readings
      WHERE measured_at >= NOW() - ${interval}::interval
      ORDER BY measured_at ASC, id ASC
      LIMIT 50000
    `;

    // --------------------------------------------------------
    // EMPTY DATABASE
    // --------------------------------------------------------

    if (!latestRows.length) {
      return res.status(200).json({
        success: true,
        data_available: false,
        message: "Belum ada data pengukuran."
      });
    }

    // --------------------------------------------------------
    // FORMAT RESPONSE
    // --------------------------------------------------------

    const latest = readRow(latestRows[0]);

    const history = historyRows.map(readHistory);

    return res.status(200).json({
      success: true,
      data_available: true,

      ...latest,

      history
    });

  } catch (error) {
    console.error("[VoltView API Error]", error);

    return res.status(500).json({
      success: false,
      message: "Terjadi kesalahan pada server API.",
      error: process.env.NODE_ENV === "development"
        ? error.message
        : undefined
    });
  }
};
