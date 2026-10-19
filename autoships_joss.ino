
#include <WiFi.h>
#include <HTTPClient.h>
#include <ModbusMaster.h>
#include <math.h>

// =====================================================
// 1. KONFIGURASI HOTSPOT HP
// =====================================================
const char* ssid = "67";
const char* password = "predatorcerr";

// 2. ALAMAT API VOLTVIEW
const char* serverURL =
  "https://voltview.vercel.app/api/voltview";

// =====================================================
// 3. KONFIGURASI MODBUS PM2100
// Asumsi awal: verifikasi dengan pengaturan meter.
// =====================================================
#define RS485_RX 16
#define RS485_TX 17
#define RS485_DE_RE 4
#define SERIAL_CONFIG SERIAL_8E1

const uint32_t MODBUS_BAUD = 19200;
const uint8_t MODBUS_SLAVE_ID = 1;

const unsigned long SEND_INTERVAL = 5000;
unsigned long lastSendTime = 0;

const bool WORD_SWAP = false;

ModbusMaster node;

// =====================================================
// 4. STRUKTUR DATA
// NAN = data belum tersedia / pembacaan gagal.
// =====================================================
struct EnergyData {
  float voltage;
  float voltage_ln_r;
  float voltage_ln_s;
  float voltage_ln_t;

  float voltage_ll_rs;
  float voltage_ll_st;
  float voltage_ll_tr;

  float current_a;
  float current_r;
  float current_s;
  float current_t;
  float current_n;

  float power_w;
  float reactive_power_kvar;
  float apparent_power_kva;

  float energy_kwh;
  float reactive_energy_kvarh;

  float frequency_hz;
  float power_factor;

  float run_hours;
  float avg_load_percent;
};

// =====================================================
// 5. KONTROL ARAH RS-485
// =====================================================
void preTransmission() {
  digitalWrite(RS485_DE_RE, HIGH);
}

void postTransmission() {
  digitalWrite(RS485_DE_RE, LOW);
}

// =====================================================
// 6. BACA FLOAT 32-BIT DARI DUA REGISTER
//
// Asumsi:
// - Input Registers, function code 0x04
// - Data IEEE-754 Float32
// - Dua register per nilai
// =====================================================
float readFloat32(uint16_t address, const char* label) {
  uint8_t result = node.readInputRegisters(address, 2);

  if (result != node.ku8MBSuccess) {
    Serial.print("[MODBUS ERROR] ");
    Serial.print(label);
    Serial.print(" | offset=");
    Serial.print(address);
    Serial.print(" | kode=0x");
    Serial.println(result, HEX);

    return NAN;
  }

  uint16_t reg0 = node.getResponseBuffer(0);
  uint16_t reg1 = node.getResponseBuffer(1);

  uint32_t bits;

  if (WORD_SWAP) {
    bits = ((uint32_t)reg1 << 16) | reg0;
  } else {
    bits = ((uint32_t)reg0 << 16) | reg1;
  }

  union {
    uint32_t bits;
    float value;
  } decoded;

  decoded.bits = bits;

  if (!isfinite(decoded.value)) {
    Serial.print("[DATA ERROR] Float tidak valid: ");
    Serial.println(label);
    return NAN;
  }

  Serial.print(label);
  Serial.print(" = ");
  Serial.println(decoded.value, 4);

  return decoded.value;
}

// =====================================================
// 7. HITUNG RATA-RATA DARI NILAI YANG VALID
// =====================================================
float average3(float a, float b, float c) {
  float total = 0.0;
  int count = 0;

  if (isfinite(a)) {
    total += a;
    count++;
  }

  if (isfinite(b)) {
    total += b;
    count++;
  }

  if (isfinite(c)) {
    total += c;
    count++;
  }

  if (count == 0) {
    return NAN;
  }

  return total / count;
}

// =====================================================
// 8. BACA DATA AKTUAL PM2100
// Alamat mengikuti offset dari tabel yang kamu berikan.
// =====================================================
bool readEnergyData(EnergyData &d) {
  // Jangan mengisi parameter yang belum diketahui
  // dengan nilai simulasi.
  d.voltage = NAN;
  d.voltage_ln_r = NAN;
  d.voltage_ln_s = NAN;
  d.voltage_ln_t = NAN;

  d.voltage_ll_rs = NAN;
  d.voltage_ll_st = NAN;
  d.voltage_ll_tr = NAN;

  d.current_a = NAN;
  d.current_r = NAN;
  d.current_s = NAN;
  d.current_t = NAN;
  d.current_n = NAN;

  d.power_w = NAN;
  d.reactive_power_kvar = NAN;
  d.apparent_power_kva = NAN;

  d.energy_kwh = NAN;
  d.reactive_energy_kvarh = NAN;

  d.frequency_hz = NAN;
  d.power_factor = NAN;

  d.run_hours = NAN;
  d.avg_load_percent = NAN;

  Serial.println();
  Serial.println("===== MEMBACA PM2100 =====");

  // Arus fase A, B, C dan rata-rata.
  d.current_r = readFloat32(3000, "Arus A (A)");
  d.current_s = readFloat32(3002, "Arus B (A)");
  d.current_t = readFloat32(3004, "Arus C (A)");
  d.current_a = readFloat32(3010, "Arus rata-rata (A)");

  if (!isfinite(d.current_a)) {
    d.current_a = average3(
      d.current_r, d.current_s, d.current_t
    );
  }

  // Tegangan antarfase.
  d.voltage_ll_rs = readFloat32(3020, "Tegangan A-B (V)");
  d.voltage_ll_st = readFloat32(3022, "Tegangan B-C (V)");
  d.voltage_ll_tr = readFloat32(3024, "Tegangan C-A (V)");

  // Tegangan fase-netral.
  d.voltage_ln_r = readFloat32(3028, "Tegangan A-N (V)");
  d.voltage_ln_s = readFloat32(3030, "Tegangan B-N (V)");
  d.voltage_ln_t = readFloat32(3032, "Tegangan C-N (V)");

  // Tegangan L-N rata-rata.
  d.voltage = readFloat32(3036, "Tegangan L-N rata-rata (V)");

  if (!isfinite(d.voltage)) {
    d.voltage = average3(
      d.voltage_ln_r,
      d.voltage_ln_s,
      d.voltage_ln_t
    );
  }

  // Daya reaktif total.
  d.reactive_power_kvar =
    readFloat32(3068, "Daya reaktif total (kvar)");

  // Register parameter berikut belum terverifikasi:
  // daya aktif, daya semu, PF, frekuensi,
  // energi aktif, energi reaktif, arus netral,
  // run hours, dan average load.
  // Biarkan NAN hingga alamat serta format diketahui.

  bool hasData =
    isfinite(d.current_a) ||
    isfinite(d.current_r) ||
    isfinite(d.current_s) ||
    isfinite(d.current_t) ||
    isfinite(d.voltage) ||
    isfinite(d.voltage_ll_rs) ||
    isfinite(d.voltage_ln_r) ||
    isfinite(d.reactive_power_kvar);

  if (!hasData) {
    Serial.println(
      "Tidak ada pembacaan valid. Periksa Modbus."
    );
    return false;
  }

  return true;
}

// =====================================================
// 9. BANTUAN PEMBUAT JSON
// NAN dikirim sebagai null JSON yang valid.
// =====================================================
void addNumber(
  String &json,
  const char* key,
  float value,
  int decimals,
  bool &first
) {
  if (!first) {
    json += ",";
  }

  json += "\"";
  json += key;
  json += "\":";

  if (isfinite(value)) {
    json += String(value, decimals);
  } else {
    json += "null";
  }

  first = false;
}

// =====================================================
// 10. KIRIM DATA KE API VOLTVIEW
// =====================================================
void sendEnergyData() {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("Wi-Fi terputus. Data tidak dikirim.");
    return;
  }

  EnergyData d;

  if (!readEnergyData(d)) {
    Serial.println("Pembacaan PM2100 gagal.");
    return;
  }

  String json;
  json.reserve(1000);
  json = "{";

  bool first = true;

  addNumber(json, "voltage", d.voltage, 2, first);

  addNumber(json, "voltage_ln_r", d.voltage_ln_r, 2, first);
  addNumber(json, "voltage_ln_s", d.voltage_ln_s, 2, first);
  addNumber(json, "voltage_ln_t", d.voltage_ln_t, 2, first);

  addNumber(json, "voltage_ll_rs", d.voltage_ll_rs, 2, first);
  addNumber(json, "voltage_ll_st", d.voltage_ll_st, 2, first);
  addNumber(json, "voltage_ll_tr", d.voltage_ll_tr, 2, first);

  addNumber(json, "current_a", d.current_a, 3, first);
  addNumber(json, "current_r", d.current_r, 3, first);
  addNumber(json, "current_s", d.current_s, 3, first);
  addNumber(json, "current_t", d.current_t, 3, first);
  addNumber(json, "current_n", d.current_n, 3, first);

  // Satuan API: watt.
  // Tetap null sampai register daya aktif terverifikasi.
  addNumber(json, "power_w", d.power_w, 2, first);

  addNumber(
    json, "reactive_power_kvar",
    d.reactive_power_kvar, 4, first
  );

  addNumber(
    json, "apparent_power_kva",
    d.apparent_power_kva, 4, first
  );

  addNumber(json, "energy_kwh", d.energy_kwh, 4, first);

  addNumber(
    json, "reactive_energy_kvarh",
    d.reactive_energy_kvarh, 4, first
  );

  addNumber(json, "frequency_hz", d.frequency_hz, 2, first);
  addNumber(json, "power_factor", d.power_factor, 3, first);
  addNumber(json, "run_hours", d.run_hours, 2, first);

  addNumber(
    json, "avg_load_percent",
    d.avg_load_percent, 2, first
  );

  json += ",\"source\":\"PM2100_MODBUS\"}";

  Serial.println();
  Serial.println("===== PAYLOAD VOLTVIEW =====");
  Serial.println(json);

  HTTPClient http;
  http.setConnectTimeout(5000);
  http.setTimeout(5000);

  if (!http.begin(serverURL)) {
    Serial.println("Gagal memulai HTTP.");
    return;
  }

  http.addHeader("Content-Type", "application/json");

  int httpCode = http.POST(json);

  Serial.print("HTTP Status: ");
  Serial.println(httpCode);

  if (httpCode > 0) {
    String response = http.getString();

    Serial.println("Respons API:");
    Serial.println(response);
  } else {
    Serial.print("HTTP gagal: ");
    Serial.println(http.errorToString(httpCode));
  }

  http.end();
}

// =====================================================
// 11. KONEKSI HOTSPOT
// =====================================================
bool connectWiFi() {
  Serial.println();
  Serial.print("Menghubungkan ke hotspot: ");
  Serial.println(ssid);

  WiFi.mode(WIFI_STA);
  WiFi.begin(ssid, password);

  const int maxAttempts = 30;

  for (int i = 0; i < maxAttempts; i++) {
    if (WiFi.status() == WL_CONNECTED) {
      break;
    }

    delay(500);
    Serial.print(".");
  }

  Serial.println();

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("Hotspot terhubung.");

    Serial.print("IP ESP32: ");
    Serial.println(WiFi.localIP());

    Serial.print("Gateway hotspot: ");
    Serial.println(WiFi.gatewayIP());

    Serial.print("IP server API: ");
    Serial.println(serverURL);

    return true;
  }

  Serial.println("Gagal terhubung ke hotspot.");
  WiFi.disconnect();

  return false;
}

// =====================================================
// 12. SETUP
// =====================================================
void setup() {
  Serial.begin(115200);
  delay(1000);

  Serial.println();
  Serial.println("================================");
  Serial.println(" VOLTVIEW - PM2100 ACTUAL DATA ");
  Serial.println("================================");

  pinMode(RS485_DE_RE, OUTPUT);
  digitalWrite(RS485_DE_RE, LOW);

  Serial2.begin(
    MODBUS_BAUD,
    SERIAL_CONFIG,
    RS485_RX,
    RS485_TX
  );

  node.begin(MODBUS_SLAVE_ID, Serial2);
  node.preTransmission(preTransmission);
  node.postTransmission(postTransmission);

  connectWiFi();
}

// =====================================================
// 13. LOOP
// =====================================================
void loop() {
  if (WiFi.status() != WL_CONNECTED) {
    if (!connectWiFi()) {
      delay(3000);
      return;
    }
  }

  unsigned long now = millis();

  if (now - lastSendTime >= SEND_INTERVAL) {
    lastSendTime = now;
    sendEnergyData();
  }
}
