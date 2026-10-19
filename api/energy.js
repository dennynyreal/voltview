
const { neon } = require("@neondatabase/serverless");

const sql = neon(process.env.DATABASE_URL);

const numberOrNull = (value) => {
  if (value === null || value === undefined || value === "") {
    return null;
  }

  const number = Number(value);
  return Number.isFinite(number) ? number : null;
};

const fields = [
  "voltage",
  "voltage_ln_r",
  "voltage_ln_s",
  "voltage_ln_t",
  "voltage_ll_rs",
  "voltage_ll_st",
  "voltage_ll_tr",
  "current_a",
  "current_r",
  "current_s",
  "current_t",
  "current_n",
  "power_w",
  "reactive_power_kvar",
  "apparent_power_kva",
  "energy_kwh",
  "reactive_energy_kvarh",
  "frequency_hz",
  "power_factor",
  "run_hours",
  "avg_load_percent"
];

const readRow = (row) => {
  const n = (key) => numberOrNull(row[key]);

  const voltageLn = [n("voltage_ln_r"), n("voltage_ln_s"), n("voltage_ln_t")];
  const voltageLl = [n("voltage_ll_rs"), n("voltage_ll_st"), n("voltage_ll_tr")];
  const currents = [n("current_r"), n("current_s"), n("current_t")];

  const average = (values) => {
    const valid = values.filter((value) => value !== null);
    return valid.length
      ? valid.reduce((sum, value) => sum + value, 0) / valid.length
      : null;
  };

  return {
    success: true,
    data_available: true,
    voltage: {
      ln: {
        r: n("voltage_ln_r"),
        s: n("voltage_ln_s"),
        t: n("voltage_ln_t"),
        avg: average(voltageLn)
      },
      ll: {
        rs: n("voltage_ll_rs"),
        st: n("voltage_ll_st"),
        tr: n("voltage_ll_tr"),
        avg: average(voltageLl)
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
      active_kw: n("power_w") === null ? null : n("power_w") / 1000,
      reactive_kvar: n("reactive_power_kvar"),
      apparent_kva: n("apparent_power_kva"),
      power_factor: n("power_factor"),
      avg_load_percent: n("avg_load_percent")
    },
    energy: {
      active_kwh: n("energy_kwh"),
      reactive_kvarh: n("reactive_energy_kvarh")
    },
    run_hours: n("run_hours"),
    timestamp: row.measured_at,
    history: []
  };
};

module.exports = async (req, res) => {
  res.setHeader("Cache-Control", "no-store");

  try {
    if (req.method === "POST") {
      const body = req.body;

      if (!body || typeof body !== "object" || Array.isArray(body)) {
        return res.status(400).json({
          success: false,
          message: "Payload JSON tidak valid."
        });
      }

      const values = fields.map((field) => numberOrNull(body[field]));

      await sql`
        INSERT INTO energy_readings (
          voltage, voltage_ln_r, voltage_ln_s, voltage_ln_t,
          voltage_ll_rs, voltage_ll_st, voltage_ll_tr,
          current_a, current_r, current_s, current_t, current_n,
          power_w, reactive_power_kvar, apparent_power_kva,
          energy_kwh, reactive_energy_kvarh, frequency_hz,
          power_factor, run_hours, avg_load_percent, source
        ) VALUES (
          ${values[0]}, ${values[1]}, ${values[2]}, ${values[3]},
          ${values[4]}, ${values[5]}, ${values[6]},
          ${values[7]}, ${values[8]}, ${values[9]}, ${values[10]}, ${values[11]},
          ${values[12]}, ${values[13]}, ${values[14]},
          ${values[15]}, ${values[16]}, ${values[17]},
          ${values[18]}, ${values[19]}, ${values[20]},
          ${typeof body.source === "string" ? body.source.slice(0, 80) : "UNKNOWN"}
        )
      `;

      return res.status(201).json({
        success: true,
        message: "Data pengukuran berhasil disimpan."
      });
    }

    if (req.method !== "GET") {
      res.setHeader("Allow", "GET, POST");
      return res.status(405).json({
        success: false,
        message: "Metode tidak didukung."
      });
    }

    const rows = await sql`
      SELECT *
      FROM energy_readings
      ORDER BY measured_at DESC
      LIMIT 100
    `;

    if (!rows.length) {
      return res.status(200).json({
        success: true,
        data_available: false,
        message: "Belum ada data pengukuran."
      });
    }

    const latest = readRow(rows[0]);

    latest.history = rows
      .slice()
      .reverse()
      .map((row) => ({
        timestamp: row.measured_at,
        voltage: numberOrNull(row.voltage),
        current_a: numberOrNull(row.current_a),
        active_kw: numberOrNull(row.power_w) === null
          ? null
          : numberOrNull(row.power_w) / 1000,
        reactive_kvar: numberOrNull(row.reactive_power_kvar),
        apparent_kva: numberOrNull(row.apparent_power_kva)
      }));

    return res.status(200).json(latest);
  } catch (error) {
    console.error("VoltView API error:", error);

    return res.status(500).json({
      success: false,
      message: "Terjadi kesalahan pada API."
    });
  }
};
