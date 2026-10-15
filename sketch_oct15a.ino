
#include <WiFi.h>
#include <HTTPClient.h>

// =====================================
// 1. KONFIGURASI WIFI HOTSPOT HP
// =====================================
const char* ssid = "67";
const char* password = "predatorcerr";

// =====================================
// 2. ALAMAT API VOLTVIEW
// =====================================
const char* serverURL =
  "http://10.16.159.79/voltview/api/energy.php";

// =====================================
// 3. INTERVAL PENGIRIMAN DATA
// =====================================
const unsigned long SEND_INTERVAL = 10000;
unsigned long lastSendTime = 0;

bool wifiConnected = false;

// =====================================
// 4. FUNGSI KONEKSI WIFI
// =====================================
bool connectWiFi() {
  Serial.println();
  Serial.println("================================");
  Serial.println("Menghubungkan ESP32 ke Wi-Fi");
  Serial.print("SSID: ");
  Serial.println(ssid);

  WiFi.mode(WIFI_STA);
  WiFi.begin(ssid, password);

  int attempts = 0;
  const int maxAttempts = 30;

  while (WiFi.status() != WL_CONNECTED &&
         attempts < maxAttempts) {
    delay(500);
    Serial.print(".");
    attempts++;
  }

  Serial.println();

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("Wi-Fi BERHASIL terhubung!");
    Serial.print("IP ESP32: ");
    Serial.println(WiFi.localIP());

    Serial.print("IP Server yang dituju: ");
    Serial.println("10.16.159.79");

    return true;
  }

  Serial.println("Wi-Fi GAGAL terhubung.");
  Serial.print("Status Wi-Fi: ");
  Serial.println(WiFi.status());
  Serial.println("Periksa hotspot, password, dan band 2.4 GHz.");

  WiFi.disconnect();
  return false;
}

// =====================================
// 5. FUNGSI KIRIM DATA KE VOLTVIEW
// =====================================
void sendTestData() {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("Wi-Fi terputus. Mencoba menyambung kembali...");
    wifiConnected = connectWiFi();
    return;
  }

  HTTPClient http;

  Serial.println();
  Serial.println("Mengirim data uji ke VoltView...");

  http.setConnectTimeout(5000);
  http.setTimeout(5000);

  if (!http.begin(serverURL)) {
    Serial.println("Gagal memulai koneksi HTTP.");
    return;
  }

  http.addHeader("Content-Type", "application/json");

  // DATA SIMULASI - BUKAN DATA METER PM2100
  String json = R"({
    "voltage": 230.0,
    "current_a": 1.2,
    "power_w": 250.0,
    "energy_kwh": 12.5,
    "frequency_hz": 50.0,
    "power_factor": 0.90,
    "source": "ESP32_TEST"
  })";

  Serial.println("Payload JSON:");
  Serial.println(json);

  int httpCode = http.POST(json);

  Serial.print("HTTP Status: ");
  Serial.println(httpCode);

  if (httpCode > 0) {
    String response = http.getString();

    Serial.println("Respons API:");
    Serial.println(response);

    if (httpCode == HTTP_CODE_CREATED) {
      Serial.println("BERHASIL: data uji tersimpan di database.");
    } else {
      Serial.println("API merespons, tetapi periksa status di atas.");
    }
  } else {
    Serial.print("Pengiriman gagal: ");
    Serial.println(http.errorToString(httpCode));
    Serial.println("Periksa IP laptop, Apache, dan jaringan.");
  }

  http.end();
}

// =====================================
// 6. SETUP
// =====================================
void setup() {
  Serial.begin(115200);
  delay(1500);

  Serial.println();
  Serial.println("================================");
  Serial.println("       VOLTVIEW ESP32 TEST");
  Serial.println("================================");

  wifiConnected = connectWiFi();

  if (wifiConnected) {
    sendTestData();
    lastSendTime = millis();
  } else {
    Serial.println("Pengiriman ditunda karena Wi-Fi gagal.");
  }
}

// =====================================
// 7. LOOP
// =====================================
void loop() {
  // Coba sambungkan ulang jika Wi-Fi terputus.
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("Wi-Fi tidak terhubung.");
    wifiConnected = connectWiFi();

    if (wifiConnected) {
      sendTestData();
      lastSendTime = millis();
    } else {
      delay(3000);
    }

    return;
  }

  // Kirim data uji setiap 10 detik.
  if (millis() - lastSendTime >= SEND_INTERVAL) {
    sendTestData();
    lastSendTime = millis();
  }
}
