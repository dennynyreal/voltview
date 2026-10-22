
const { neon } = require("@neondatabase/serverless");
const { Redis } = require("@upstash/redis");

const sql = neon(process.env.DATABASE_URL);

const redis = new Redis({
  url: process.env.UPSTASH_REDIS_REST_URL,
  token: process.env.UPSTASH_REDIS_REST_TOKEN
});

const LIVE_KEY = "voltview:latest";
const HISTORY_KEY = "voltview:live_history";

const LIVE_TTL_SECONDS = 120;
const HISTORY_TTL_SECONDS = 3600;
const MAX_LIVE_HISTORY = 300;

// =============================================
// CORS
// =============================================

const allowedOrigins = [
  "https://voltview.vercel.app",
  "https://autoshipsmrc.site.je"
];

function isAllowedOrigin(origin) {
  if (!origin) return false;

  if (allowedOrigins.includes(origin)) return true;

  return /^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/.test(
    origin
  );
}

// =============================================
// HELPER
// =============================================

const numberOrNull = (value) => {
  if (
    value === null ||
    value === undefined ||
    value === ""
  ) {
    return null;
  }

  const number = Number(value);

  return Number.isFinite(number) ? number : null;
};

const average = (values) => {
  const valid = values.filter((value) => value !== null);

  if (valid.length === 0) return null;

  return valid.reduce((sum, value) => sum + value, 0)
    / valid.length;
};

function parseStored(value) {
  if (typeof value !== "string") return value;

  try {
    return JSON.parse(value);
  } catch {
    return value;
  }
}

// =============================================
// NORMALISASI PAYLOAD ESP32
// Mendukung format nested dari file .ino
// dan format flat dari pengirim lama.
// =============================================

function normalizePayload(body) {
  const voltage = body.voltage || {};
  const current = body.current || {};
  const power = body.power || {};
  const energy = body.energy || {};

  const pick = (flatValue, nestedValue) =>
    numberOrNull(flatValue ?? nestedValue);

  const voltage_ln_r = pick(
    body.voltage_ln_r, voltage.r
  );

  const voltage_ln_s = pick(
    body.voltage_ln_s, voltage.s
  );

  const voltage_ln_t = pick(
    body.voltage_ln_t, voltage.t
  );

  const voltage_ll_rs = pick(
    body.voltage_ll_rs, voltage.rs
  );

  const voltage_ll_st = pick(
    body.voltage_ll_st, voltage.st
  );

  const voltage_ll_tr = pick(
    body.voltage_ll_tr, voltage.tr
  );

  const current_r = pick(body.current_r, current.r);
  const current_s = pick(body.current_s, current.s);
  const current_t = pick(body.current_t, current.t);
  const current_n = pick(body.current_n, current.n);

  const activeKw = numberOrNull(power.active_kw);

  const power_w =
    numberOrNull(body.power_w) ??
    (activeKw === null ? null : activeKw * 1000);

  const reactive_power_kvar = pick(
    body.reactive_power_kvar, power.reactive_kvar
  );

  const apparent_power_kva = pick(
    body.apparent_power_kva, power.apparent_kva
  );

  const energy_kwh = pick(body.energy_kwh, energy.kwh);

  const reactive_energy_kvarh = pick(
    body.reactive_energy_kvarh, energy.kvarh
  );

  const voltageValues = [
    voltage_ln_r,
    voltage_ln_s,
    voltage_ln_t
  ];

  const currentValues = [
    current_r,
    current_s,
    current_t
  ];

  return {
    voltage: numberOrNull(body.voltage) ??
      average(voltageValues),

    voltage_ln_r,
    voltage_ln_s,
    voltage_ln_t,

    voltage_ll_rs,
    voltage_ll_st,
    voltage_ll_tr,

    current_a: numberOrNull(body.current_a) ??
      average(currentValues),

    current_r,
    current_s,
    current_t,
    current_n,

    power_w,
    reactive_power_kvar,
    apparent_power_kva,

    energy_kwh,
    reactive_energy_kvarh,

    frequency_hz: numberOrNull(body.frequency_hz),

    power_factor: pick(
      body.power_factor, power.power_factor
    ),

    run_hours: numberOrNull(body.run_hours),

    avg_load_percent: pick(
      body.avg_load_percent, power.avg_load_percent
    ),

    source:
      typeof body.source === "string"
        ? body.source.slice(0, 80)
        : "UNKNOWN",

    measured_at: new Date().toISOString()
  };
}

// =============================================
// FORMAT DATA UNTUK DASHBOARD
// =============================================

function readRow(row) {
  const n = (key) => numberOrNull(row[key]);

  const ln = [
    n("voltage_ln_r"),
    n("voltage_ln_s"),
    n("voltage_ln_t")
  ];

  const ll = [
    n("voltage_ll_rs"),
    n("voltage_ll_st"),
    n("voltage_ll_tr")
  ];

  const currents = [
    n("current_r"),
    n("current_s"),
    n("current_t")
  ];

  const powerW = n("power_w");

  return {
    success: true,
    data_available: true,

    voltage: {
      ln: {
        r: n("voltage_ln_r"),
        s: n("voltage_ln_s"),
        t: n("voltage_ln_t"),
        avg: average(ln)
      },
      ll: {
        rs: n("voltage_ll_rs"),
        st: n("voltage_ll_st"),
        tr: n("voltage_ll_tr"),
        avg: average(ll)
      }
    },

    current: {
      r: n("current_r"),
      s: n("current_s"),
      t: n("current_t"),
      n: n("current_n"),
      avg: average(currents)
    },

    power: {
      active_kw: powerW === null ? null : powerW / 1000,
      reactive_kvar: n("reactive_power_kvar"),
      apparent_kva: n("apparent_power_kva"),
      power_factor: n("power_factor"),
      avg_load_percent: n("avg_load_percent")
    },

    energy: {
      active_kwh: n("energy_kwh"),
      reactive_kvarh: n("reactive_energy_kvarh")
    },

    frequency_hz: n("frequency_hz"),
    source: row.source ?? null,
    run_hours: n("run_hours"),
    timestamp: row.measured_at,
    history: []
  };
}

function chartPoint(row) {
  const powerW = numberOrNull(row.power_w);

  return {
    timestamp: row.measured_at,
    voltage: numberOrNull(row.voltage),
    current_a: numberOrNull(row.current_a),
    active_kw: powerW === null ? null : powerW / 1000,
    reactive_kvar: numberOrNull(row.reactive_power_kvar),
    apparent_kva: numberOrNull(row.apparent_power_kva)
  };
}

// =============================================
// SIMPAN DATA PERMANEN KE NEON
// Hanya dipanggil jika persist === true.
// =============================================

async function saveToDatabase(row) {
  await sql`
    INSERT INTO energy_readings (
      voltage,
      voltage_ln_r,
      voltage_ln_s,
      voltage_ln_t,
      voltage_ll_rs,
      voltage_ll_st,
      voltage_ll_tr,
      current_a,
      current_r,
      current_s,
      current_t,
      current_n,
      power_w,
      reactive_power_kvar,
      apparent_power_kva,
      energy_kwh,
      reactive_energy_kvarh,
      frequency_hz,
      power_factor,
      run_hours,
      avg_load_percent,
      source
    )
    VALUES (
      ${row.voltage},
      ${row.voltage_ln_r},
      ${row.voltage_ln_s},
      ${row.voltage_ln_t},
      ${row.voltage_ll_rs},
      ${row.voltage_ll_st},
      ${row.voltage_ll_tr},
      ${row.current_a},
      ${row.current_r},
      ${row.current_s},
      ${row.current_t},
      ${row.current_n},
      ${row.power_w},
      ${row.reactive_power_kvar},
      ${row.apparent_power_kva},
      ${row.energy_kwh},
      ${row.reactive_energy_kvarh},
      ${row.frequency_hz},
      ${row.power_factor},
      ${row.run_hours},
      ${row.avg_load_percent},
      ${row.source}
    )
  `;
}

// =============================================
// API UTAMA
// =============================================

module.exports = async (req, res) => {
  const origin = req.headers.origin;

  if (isAllowedOrigin(origin)) {
    res.setHeader("Access-Control-Allow-Origin", origin);
    res.setHeader("Vary", "Origin");
  }

  res.setHeader(
    "Access-Control-Allow-Methods",
    "GET, POST, OPTIONS"
  );

  res.setHeader(
    "Access-Control-Allow-Headers",
    "Content-Type"
  );

  res.setHeader("Cache-Control", "no-store");

  if (req.method === "OPTIONS") {
    return res.status(204).end();
  }

  try {
    // =========================================
    // POST: TERIMA DATA ESP32
    // =========================================

    if (req.method === "POST") {
      const body = req.body;

      if (
        !body ||
        typeof body !== "object" ||
        Array.isArray(body)
      ) {
        return res.status(400).json({
          success: false,
          message: "Payload JSON tidak valid."
        });
      }

      const row = normalizePayload(body);

      // Data live disimpan sementara di Redis.
      // Tidak membuat INSERT ke Neon pada tahap ini.
      await redis.set(
        LIVE_KEY,
        JSON.stringify(row),
        { ex: LIVE_TTL_SECONDS }
      );

      // Riwayat live sementara untuk grafik.
      await redis.lpush(
        HISTORY_KEY,
        JSON.stringify(row)
      );

      await redis.ltrim(
        HISTORY_KEY,
        0,
        MAX_LIVE_HISTORY - 1
      );

      await redis.expire(
        HISTORY_KEY,
        HISTORY_TTL_SECONDS
      );

      // Simpan permanen hanya jika ESP32 meminta.
      const persist = body.persist === true;

      if (persist) {
        await saveToDatabase(row);
      }

      return res.status(200).json({
        success: true,
        message: persist
          ? "Data berhasil disimpan ke database."
          : "Data live diperbarui tanpa penyimpanan database.",
        data_available: true,
        saved_to_database: persist,
        source: row.source,
        timestamp: row.measured_at
      });
    }

    // =========================================
    // GET: EKSPOR EXCEL
    // =========================================

    if (req.method === "GET") {
      if (req.query.export === "1") {
        const range = req.query.range || "day";

        const rangeDays = {
          day: 1,
          week: 7,
          month: 30
        };

        const days = rangeDays[range];

        if (!days) {
          return res.status(400).json({
            success: false,
            message: "Periode ekspor tidak valid."
          });
        }

        const rows = await sql`
          SELECT *
          FROM energy_readings
          WHERE measured_at >=
            NOW() - (${days} * INTERVAL '1 day')
          ORDER BY measured_at ASC, id ASC
          LIMIT 50000
        `;

        return res.status(200).json({
          success: true,
          export: true,
          range,
          count: rows.length,
          limit: 50000,
          rows
        });
      }

      // =======================================
      // GET: DATA LIVE DARI REDIS
      // =======================================

      const cached = parseStored(
        await redis.get(LIVE_KEY)
      );

      if (cached) {
        const latest = readRow(cached);

        const cachedHistory = await redis.lrange(
          HISTORY_KEY,
          0,
          MAX_LIVE_HISTORY - 1
        );

        latest.history = cachedHistory
          .map(parseStored)
          .filter((row) => row && typeof row === "object")
          .reverse()
          .map(chartPoint);

        return res.status(200).json(latest);
      }

      // =======================================
      // CADANGAN: DATA TERAKHIR DARI DATABASE
      // =======================================

      const rows = await sql`
        SELECT *
        FROM energy_readings
        ORDER BY measured_at DESC, id DESC
        LIMIT 100
      `;

      if (rows.length === 0) {
        return res.status(200).json({
          success: true,
          data_available: false,
          message: "Belum ada data pengukuran.",
          history: []
        });
      }

      const latest = readRow(rows[0]);

      latest.history = rows
        .slice()
        .reverse()
        .map(chartPoint);

      return res.status(200).json(latest);
    }

    res.setHeader("Allow", "GET, POST, OPTIONS");

    return res.status(405).json({
      success: false,
      message: "Metode tidak didukung."
    });

  } catch (error) {
    console.error("VoltView API error:", error);

    return res.status(500).json({
      success: false,
      message: "Terjadi kesalahan pada API."
    });
  }
};
