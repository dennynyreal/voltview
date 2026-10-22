#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <ModbusMaster.h>
#include <math.h>
#include <string.h>

const char* WIFI_SSID     = "";
const char* WIFI_PASSWORD = "";

const char* API_URL = "https://voltview.vercel.app/api/voltview";
const char* DATA_SOURCE = "PM2100_MODBUS";

static const int MODBUS_RX_PIN = 18;
static const int MODBUS_TX_PIN = 19;
static const uint32_t MODBUS_BAUD = 19200;
static const uint8_t MODBUS_SLAVE_ID = 1;
static const uint32_t SEND_INTERVAL_MS = 5000;


static const float NOMINAL_APPARENT_POWER_KVA = 0.0f;

HardwareSerial ModbusSerial(2);
ModbusMaster node;
unsigned long lastSendMs = 0;


bool readRegisters(uint16_t address, uint8_t count) {
  uint8_t result = node.readHoldingRegisters(address, count); // FC03
  if (result != node.ku8MBSuccess) {
    Serial.printf("Modbus gagal: addr=%u, code=0x%02X\n", address, result);
    return false;
  }
  return true;
}

float readFloat32(uint16_t address) {
  if (!readRegisters(address, 2)) return NAN;

  uint32_t raw = ((uint32_t)node.getResponseBuffer(0) << 16) |
                  (uint32_t)node.getResponseBuffer(1);
  float value;
  memcpy(&value, &raw, sizeof(value));

  if (!isfinite(value) || fabsf(value) > 1.0e9f) return NAN;
  return value;
}

uint32_t readUInt32(uint16_t address) {
  if (!readRegisters(address, 2)) return UINT32_MAX;

  return ((uint32_t)node.getResponseBuffer(0) << 16) |
         (uint32_t)node.getResponseBuffer(1);
}

float average3(float a, float b, float c) {
  int count = 0;
  float total = 0.0f;
  if (isfinite(a)) { total += a; count++; }
  if (isfinite(b)) { total += b; count++; }
  if (isfinite(c)) { total += c; count++; }
  return count ? total / count : NAN;
}

String jsonNumber(float value, uint8_t decimals = 3) {
  if (!isfinite(value)) return "null";
  return String(value, decimals);
}

void connectWiFi() {
  if (WiFi.status() == WL_CONNECTED) return;

  Serial.printf("Menghubungkan Wi-Fi: %s\n", WIFI_SSID);
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  uint32_t start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < 20000) {
    delay(500);
    Serial.print('.');
  }
  Serial.println();

  if (WiFi.status() == WL_CONNECTED) {
    Serial.print("Wi-Fi terhubung. IP: ");
    Serial.println(WiFi.localIP());
  } else {
    Serial.println("Wi-Fi belum terhubung; akan dicoba lagi pada siklus berikutnya.");
  }
}

void sendToVoltView(const String& payload) {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("Data tidak dikirim: Wi-Fi terputus.");
    return;
  }

  WiFiClientSecure client;
  // Untuk pengujian awal. Untuk deployment permanen, sebaiknya verifikasi sertifikat TLS.
  client.setInsecure();

  HTTPClient http;
  http.setTimeout(10000);

  if (!http.begin(client, API_URL)) {
    Serial.println("Gagal memulai koneksi ke API VoltView.");
    return;
  }

  http.addHeader("Content-Type", "application/json");
  int httpCode = http.POST(payload);
  String response = http.getString();

  Serial.printf("HTTP status: %d\n", httpCode);
  if (response.length()) Serial.println("API: " + response);

  http.end();
}

void readAndSendData() {
  // Register list dari file Schneider. Alamat kode = register tabel - 1.
  // Current A/B/C/N/Avg: 3000/3002/3004/3006/3010
  float currentR = readFloat32(2999);
  float currentS = readFloat32(3001);
  float currentT = readFloat32(3003);
  float currentN = readFloat32(3005);
  float currentAvgMeter = readFloat32(3009);
  float currentAvg = isfinite(currentAvgMeter)
                     ? currentAvgMeter
                     : average3(currentR, currentS, currentT);

  // Voltage L-L RS/ST/TR/Avg: 3020/3022/3024/3026
  float voltageLLRS = readFloat32(3019);
  float voltageLLST = readFloat32(3021);
  float voltageLLTR = readFloat32(3023);
  float voltageLLAvgMeter = readFloat32(3025);
  float voltageLLAvg = isfinite(voltageLLAvgMeter)
                       ? voltageLLAvgMeter
                       : average3(voltageLLRS, voltageLLST, voltageLLTR);

  // Voltage L-N R/S/T/Avg: 3028/3030/3032/3036
  float voltageLNR = readFloat32(3027);
  float voltageLNS = readFloat32(3029);
  float voltageLNT = readFloat32(3031);
  float voltageLNAvgMeter = readFloat32(3035);
  float voltageLNAvg = isfinite(voltageLNAvgMeter)
                       ? voltageLNAvgMeter
                       : average3(voltageLNR, voltageLNS, voltageLNT);

  // Total power. Register units in the spreadsheet: kW, kVAr, kVA.
  float activePowerKW = readFloat32(3059);       // Active Power Total, table 3060
  float reactivePowerKVAR = readFloat32(3067);  // Reactive Power Total, table 3068
  float apparentPowerKVA = readFloat32(3075);   // Apparent Power Total, table 3076

  // Use IEEE-standard FLOAT32 PF register (table 3194 -> code 3193).
  // Table register 3084 uses 4Q_FP_PF encoding, not FLOAT32.
  float powerFactor = readFloat32(3193);
  float frequencyHz = readFloat32(3109);        // Frequency, table 3110

  // Permanent accumulated energy FLOAT32 registers.
  // Active energy delivered: table 2676 -> code 2675 (kWh).
  // Reactive energy delivered + received: table 2688 -> code 2687 (kVArh).
  float energyKWh = readFloat32(2675);
  float reactiveEnergyKVARh = readFloat32(2687);

  // Run seconds delivered into load: UINT32 table 43546 -> code 43545.
  uint32_t runSeconds = readUInt32(43545);
  float runHours = (runSeconds == UINT32_MAX)
                   ? NAN
                   : ((float)runSeconds / 3600.0f);

  float avgLoadPercent = NAN;
  if (NOMINAL_APPARENT_POWER_KVA > 0.0f && isfinite(apparentPowerKVA)) {
    avgLoadPercent = (apparentPowerKVA / NOMINAL_APPARENT_POWER_KVA) * 100.0f;
    if (avgLoadPercent < 0.0f) avgLoadPercent = NAN;
  }

  float activePowerW = isfinite(activePowerKW) ? activePowerKW * 1000.0f : NAN;

  String payload = "{";
  payload += "\"source\":\"" + String(DATA_SOURCE) + "\",";
  payload += "\"voltage\":" + jsonNumber(voltageLNAvg, 3) + ",";
  payload += "\"voltage_ln_r\":" + jsonNumber(voltageLNR, 3) + ",";
  payload += "\"voltage_ln_s\":" + jsonNumber(voltageLNS, 3) + ",";
  payload += "\"voltage_ln_t\":" + jsonNumber(voltageLNT, 3) + ",";
  payload += "\"voltage_ll_rs\":" + jsonNumber(voltageLLRS, 3) + ",";
  payload += "\"voltage_ll_st\":" + jsonNumber(voltageLLST, 3) + ",";
  payload += "\"voltage_ll_tr\":" + jsonNumber(voltageLLTR, 3) + ",";
  payload += "\"current_a\":" + jsonNumber(currentAvg, 3) + ",";
  payload += "\"current_r\":" + jsonNumber(currentR, 3) + ",";
  payload += "\"current_s\":" + jsonNumber(currentS, 3) + ",";
  payload += "\"current_t\":" + jsonNumber(currentT, 3) + ",";
  payload += "\"current_n\":" + jsonNumber(currentN, 3) + ",";
  payload += "\"power_w\":" + jsonNumber(activePowerW, 3) + ",";
  payload += "\"reactive_power_kvar\":" + jsonNumber(reactivePowerKVAR, 3) + ",";
  payload += "\"apparent_power_kva\":" + jsonNumber(apparentPowerKVA, 3) + ",";
  payload += "\"energy_kwh\":" + jsonNumber(energyKWh, 3) + ",";
  payload += "\"reactive_energy_kvarh\":" + jsonNumber(reactiveEnergyKVARh, 3) + ",";
  payload += "\"frequency_hz\":" + jsonNumber(frequencyHz, 3) + ",";
  payload += "\"power_factor\":" + jsonNumber(powerFactor, 4) + ",";
  payload += "\"run_hours\":" + jsonNumber(runHours, 3) + ",";
  payload += "\"avg_load_percent\":" + jsonNumber(avgLoadPercent, 2);
  payload += "}";

  Serial.println("\n----- DATA PM METER -----");
  Serial.printf("V L-N avg : %.2f V\n", voltageLNAvg);
  Serial.printf("V L-L avg : %.2f V\n", voltageLLAvg);
  Serial.printf("I avg     : %.2f A | I N: %.2f A\n", currentAvg, currentN);
  Serial.printf("P         : %.3f kW\n", activePowerKW);
  Serial.printf("Q         : %.3f kVAr\n", reactivePowerKVAR);
  Serial.printf("S         : %.3f kVA\n", apparentPowerKVA);
  Serial.printf("PF        : %.4f | F: %.2f Hz\n", powerFactor, frequencyHz);
  Serial.printf("Energy    : %.3f kWh | %.3f kVArh\n", energyKWh, reactiveEnergyKVARh);
  if (isfinite(runHours)) Serial.printf("Run hours : %.2f h\n", runHours);
  else Serial.println("Run hours : read error");
  if (isfinite(avgLoadPercent)) Serial.printf("Load      : %.2f %%\n", avgLoadPercent);
  else Serial.println("Load      : null (set nominal kVA if this metric is required)");
  Serial.println("Payload: " + payload);

  sendToVoltView(payload);
}

void setup() {
  Serial.begin(115200);
  delay(500);

  ModbusSerial.begin(MODBUS_BAUD, SERIAL_8E1, MODBUS_RX_PIN, MODBUS_TX_PIN);
  node.begin(MODBUS_SLAVE_ID, ModbusSerial);

  connectWiFi();
  Serial.println("VoltView PM meter sender siap.");
}

void loop() {
  if (WiFi.status() != WL_CONNECTED) connectWiFi();

  unsigned long now = millis();
  if (now - lastSendMs >= SEND_INTERVAL_MS) {
    lastSendMs = now;
    readAndSendData();
  }
}
