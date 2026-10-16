
#include <WiFi.h>
#include <HTTPClient.h>
#include <math.h>

// 1. KONFIGURASI WIFI
const char* ssid = "67";
const char* password = "predatorcerr";

// 2. ALAMAT API VOLTVIEW
const char* serverURL =
  "http://10.16.159.79/voltview/api/energy.php";

// 3. INTERVAL PENGIRIMAN
const unsigned long SEND_INTERVAL = 1000;
unsigned long lastSendTime = 0;

// 4. STRUKTUR DATA PENGUKURAN
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

  const char* source;
};

// 5. SUMBER DATA SEMENTARA
// SELURUH NILAI DI SINI ADALAH SIMULASI.
// Belum membaca register Modbus Schneider PM2100.
bool readEnergyData(EnergyData &data) {
  data.voltage_ln_r = 250.0;
  data.voltage_ln_s = 291.0;
  data.voltage_ln_t = 259.0;

  data.voltage_ll_rs = 400.0;
  data.voltage_ll_st = 109.0;
  data.voltage_ll_tr = 201.0;

  data.voltage = (
    data.voltage_ln_r +
    data.voltage_ln_s +
    data.voltage_ln_t
  ) / 3.0;

  data.current_r = 1.2;
  data.current_s = 1.1;
  data.current_t = 1.3;
  data.current_n = 0.2;

  data.current_a = (
    data.current_r +
    data.current_s +
    data.current_t
  ) / 3.0;

  data.power_w = 750.0;
  data.apparent_power_kva = 0.83;
  data.reactive_power_kvar = 0.35;

  data.energy_kwh = 22.5;
  data.reactive_energy_kvarh = 10.5;

  data.frequency_hz = 50.0;
  data.power_factor = 0.90;

  data.run_hours = 120.0;
  data.avg_load_percent = 35.0;

  data.source = "ESP32_TEST";

  return true;
}

// 6. KONEKSI WIFI
bool connectWiFi() {
  Serial.println();
  Serial.println("Menghubungkan ke Wi-Fi...");

  WiFi.mode(WIFI_STA);
  WiFi.begin(ssid, password);

  const int maxAttempts = 30;
  int attempts = 0;

  while (WiFi.status() != WL_CONNECTED &&
         attempts < maxAttempts) {
    delay(500);
    Serial.print(".");
    attempts++;
  }

  Serial.println();

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("Wi-Fi terhubung.");
    Serial.print("IP ESP32: ");
    Serial.println(WiFi.localIP());

    return true;
  }

  Serial.println("Wi-Fi gagal terhubung.");
  WiFi.disconnect();

  return false;
}

// 7. VALIDASI DATA
bool validEnergyData(const EnergyData &d) {
  const float values[] = {
    d.voltage,
    d.voltage_ln_r,
    d.voltage_ln_s,
    d.voltage_ln_t,
    d.voltage_ll_rs,
    d.voltage_ll_st,
    d.voltage_ll_tr,

    d.current_a,
    d.current_r,
    d.current_s,
    d.current_t,
    d.current_n,

    d.power_w,
    d.reactive_power_kvar,
    d.apparent_power_kva,

    d.energy_kwh,
    d.reactive_energy_kvarh,

    d.frequency_hz,
    d.power_factor,
    d.run_hours,
    d.avg_load_percent
  };

  for (float value : values) {
    if (!isfinite(value)) {
      return false;
    }
  }

  // Parameter yang tidak boleh negatif.
  const float nonNegative[] = {
    d.voltage,
    d.voltage_ln_r,
    d.voltage_ln_s,
    d.voltage_ln_t,
    d.voltage_ll_rs,
    d.voltage_ll_st,
    d.voltage_ll_tr,

    d.current_a,
    d.current_r,
    d.current_s,
    d.current_t,
    d.current_n,

    d.power_w,
    d.apparent_power_kva,
    d.energy_kwh,
    d.reactive_energy_kvarh,
    d.frequency_hz,
    d.run_hours,
    d.avg_load_percent
  };

  for (float value : nonNegative) {
    if (value < 0) {
      return false;
    }
  }

  if (d.power_factor < 0 ||
      d.power_factor > 1 ||
      d.avg_load_percent > 100) {
    return false;
  }

  return true;
}

// 8. KIRIM DATA KE API
void sendEnergyData() {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("Wi-Fi terputus.");
    return;
  }

  EnergyData data;

  if (!readEnergyData(data)) {
    Serial.println("Pembacaan data gagal.");
    return;
  }

  if (!validEnergyData(data)) {
    Serial.println("Data tidak valid.");
    return;
  }

  HTTPClient http;

  http.setConnectTimeout(5000);
  http.setTimeout(5000);

  if (!http.begin(serverURL)) {
    Serial.println("Gagal memulai HTTP.");
    return;
  }

  http.addHeader("Content-Type", "application/json");

  String json;
  json.reserve(850);

  json = "{";

  json += "\"voltage\":" + String(data.voltage, 2) + ",";
  json += "\"voltage_ln_r\":" + String(data.voltage_ln_r, 2) + ",";
  json += "\"voltage_ln_s\":" + String(data.voltage_ln_s, 2) + ",";
  json += "\"voltage_ln_t\":" + String(data.voltage_ln_t, 2) + ",";

  json += "\"voltage_ll_rs\":" + String(data.voltage_ll_rs, 2) + ",";
  json += "\"voltage_ll_st\":" + String(data.voltage_ll_st, 2) + ",";
  json += "\"voltage_ll_tr\":" + String(data.voltage_ll_tr, 2) + ",";

  json += "\"current_a\":" + String(data.current_a, 3) + ",";
  json += "\"current_r\":" + String(data.current_r, 3) + ",";
  json += "\"current_s\":" + String(data.current_s, 3) + ",";
  json += "\"current_t\":" + String(data.current_t, 3) + ",";
  json += "\"current_n\":" + String(data.current_n, 3) + ",";

  json += "\"power_w\":" + String(data.power_w, 2) + ",";
  json += "\"reactive_power_kvar\":" + String(data.reactive_power_kvar, 4) + ",";
  json += "\"apparent_power_kva\":" + String(data.apparent_power_kva, 4) + ",";

  json += "\"energy_kwh\":" + String(data.energy_kwh, 4) + ",";
  json += "\"reactive_energy_kvarh\":" + String(data.reactive_energy_kvarh, 4) + ",";

  json += "\"frequency_hz\":" + String(data.frequency_hz, 2) + ",";
  json += "\"power_factor\":" + String(data.power_factor, 3) + ",";

  json += "\"run_hours\":" + String(data.run_hours, 2) + ",";
  json += "\"avg_load_percent\":" + String(data.avg_load_percent, 2) + ",";

  json += "\"source\":\"";
  json += data.source;
  json += "\"}";

  Serial.println();
  Serial.println("=== VOLTVIEW ESP32 ===");
  Serial.print("Sumber data: ");
  Serial.println(data.source);

  Serial.println("Payload JSON:");
  Serial.println(json);

  int httpCode = http.POST(json);

  Serial.print("HTTP Status: ");
  Serial.println(httpCode);

  if (httpCode > 0) {
    Serial.println("Respons API:");
    Serial.println(http.getString());
  } else {
    Serial.print("HTTP gagal: ");
    Serial.println(http.errorToString(httpCode));
  }

  http.end();
}

// 9. SETUP
void setup() {
  Serial.begin(115200);
  delay(1000);

  Serial.println();
  Serial.println("=== VOLTVIEW ESP32 ===");

  connectWiFi();
}

// 10. LOOP
void loop() {
  if (WiFi.status() != WL_CONNECTED) {
    connectWiFi();

    if (WiFi.status() != WL_CONNECTED) {
      delay(3000);
      return;
    }
  }

  if (millis() - lastSendTime >= SEND_INTERVAL) {
    lastSendTime = millis();
    sendEnergyData();
  }
}
