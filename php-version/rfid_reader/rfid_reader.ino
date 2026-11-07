// ============================================================================
// QRAttendance RFID reader – main firmware (Arduino IDE 2.x)
//
// What this does: sits at the classroom door, reads a student's school ID
// (MIFARE/NTAG UID) over MFRC522, and POSTs it to the PHP server
// /api/rfid_checkin.php using a device_token it received while pairing.
//
// ┌──────────────────────────────────────────────────────────────────────────┐
// │ Required libraries (install via Sketch → Include Library → Manage        │
// │ Libraries; versions tested with this sketch):                           │
// │                                                                          │
// │  Library                          Author / ID        Version             │
// │  ------------------------------------------------------------------     │
// │  MFRC522                          GithubCommunity     v1.4.11            │
// │  Adafruit SSD1306                 Adafruit            v2.5.13            │
// │  Adafruit GFX Library             Adafruit            v1.11.11           │
// │  ArduinoJson                      Benoit Blanchon     v7.3.1             │
// │  WiFiManager                      tzapu               v2.0.17            │
// │                                                                          │
// │ Board: "ESP32 Dev Module" (Boards Manager: esp32 by Espressif 3.x)      │
// └──────────────────────────────────────────────────────────────────────────┘
//
// Wiring (see rfid_reader/README.md for the full ASCII diagram):
//   MFRC522 : SDA→GPIO5  SCK→GPIO18  MOSI→GPIO23  MISO→GPIO19  RST→GPIO22
//   OLED    : SDA→GPIO21 SCL→GPIO4   (SSD1306 128x64, I2C address 0x3C)
//   Buzzer  : GPIO25      LEDs: green GPIO26, red GPIO27      Pair btn: GPIO32
// ============================================================================

#if __has_include("secrets.h")
  #include "secrets.h"
#endif
#include <WiFi.h>
#include <WiFiManager.h>
#include <HTTPClient.h>
#include <SPI.h>
#include <MFRC522.h>
#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>
#include <ArduinoJson.h>
#include <Preferences.h>

/* ----------------------------- configuration ---------------------------- */
#ifndef SERVER_BASE
  #define SERVER_BASE "https://yourdomain.com"   // no trailing slash
#endif
#ifndef SECRET_SSID
  #define SECRET_SSID ""                         // optional: known Wi-Fi network
#endif
#ifndef SECRET_PASS
  #define SECRET_PASS ""
#endif

#define HEARTBEAT_MS   20000UL   // POST /api/rfid_heartbeat.php every 20 s
#define DEBOUNCE_MS     3000UL   // same UID ignored for 3 s
#define PAIR_POLL_MS    2000UL   // pairing code polled every 2 s
#define PAIR_TIMEOUT_MS 180000UL // give up pairing after 3 minutes
#define HTTP_TIMEOUT_MS 8000UL   // give up a server call after 8 s
#define OK_HOLD_MS      3000UL   // green LED / success screen duration

/* -------------------------------- pinout -------------------------------- */
#define PIN_SS        5    // MFRC522 SDA/SS
#define PIN_RST       22   // MFRC522 RST
#define PIN_BUZZER    25
#define PIN_LED_GREEN 26
#define PIN_LED_RED   27
#define PIN_PAIR_BTN  32   // INPUT_PULLUP, active low

const char *USER_AGENT = "QRAttendance-ESP32/1.0";  // must match the server

MFRC522 mfrc522(PIN_SS, PIN_RST);
Adafruit_SSD1306 display(128, 64, &Wire, -1);
Preferences prefs;

String deviceToken;
int    sectionId = 0;
String readerName = "Reader";
String pairCode = "";

bool  pairingMode = false;
bool  serverOnline = false;
bool  sessionOpen = false;
char  lastUid[24] = "";
unsigned long lastUidMs = 0;
unsigned long lastHeartbeatMs = 0;
unsigned long lastPairPollMs = 0;
unsigned long successUntilMs = 0;
String statusLine = "";
String statusSub = "";

/* -------------------------------- helpers -------------------------------- */
void twoLine(const char *first, const char *second = "", int delayMs = 0) {
  display.clearDisplay();
  display.setTextColor(SSD1306_WHITE);
  display.setTextSize(1);
  display.setCursor(0, 0);
  display.println(first);
  display.setCursor(0, 20);
  display.println(second);
  display.display();
  if (delayMs) delay(delayMs);
}

void idleScreen() {
  String wifi = serverOnline ? "server: online" : "server: offline";
  String line3 = "";
  if (sessionOpen && sectionId > 0) line3 = "session: OPEN";
  else if (sectionId > 0) line3 = "session: closed";

  display.clearDisplay();
  display.setTextColor(SSD1306_WHITE);
  display.setTextSize(1);
  display.setCursor(0, 0);
  display.println("QRAttendance");
  display.drawLine(0, 11, 127, 11, SSD1306_WHITE);
  display.setTextSize(2);
  display.setCursor(0, 24);
  display.println("Tap your ID");
  display.setTextSize(1);
  display.setCursor(0, 48);
  display.print(readerName);
  display.print(" | ");
  display.println(wifi);
  display.setCursor(0, 57);
  display.print(line3);
  display.display();
}

void showResultScreen(const char *title, const char *detail) {
  display.clearDisplay();
  display.setTextColor(SSD1306_WHITE);
  display.cp437(true);                 // so 251 renders as a check mark
  display.setTextSize(2);
  display.setCursor(0, 4);
  display.println(title);
  display.setTextSize(1);
  display.setCursor(0, 30);
  display.println(detail);
  display.display();
}

/* Beep patterns: 1 short = OK, 2 short = duplicate, 1 long = error */
void beep(int ok) {
  if (ok == 0) {                                 // error: one long
    digitalWrite(PIN_BUZZER, HIGH); delay(450);  digitalWrite(PIN_BUZZER, LOW);
  } else if (ok == 1) {                          // duplicate: two short
    digitalWrite(PIN_BUZZER, HIGH); delay(90); digitalWrite(PIN_BUZZER, LOW); delay(90);
    digitalWrite(PIN_BUZZER, HIGH); delay(90); digitalWrite(PIN_BUZZER, LOW);
  } else {                                       // success: one short
    digitalWrite(PIN_BUZZER, HIGH); delay(90);  digitalWrite(PIN_BUZZER, LOW);
  }
}

/* LED: green solid = OK, amber blink (both) = duplicate, red = error */
void led(int which) {
  digitalWrite(PIN_LED_GREEN, LOW);
  digitalWrite(PIN_LED_RED, LOW);
  if (which == 2) { digitalWrite(PIN_LED_GREEN, HIGH); digitalWrite(PIN_LED_RED, HIGH); return; }
  if (which == 1) { digitalWrite(PIN_LED_GREEN, HIGH); return; }
  if (which == 0) { digitalWrite(PIN_LED_RED, HIGH); return; }
}
void ledBlinkAmber(int times) {
  for (int i = 0; i < times; i++) {
    digitalWrite(PIN_LED_GREEN, HIGH); digitalWrite(PIN_LED_RED, HIGH); delay(140);
    digitalWrite(PIN_LED_GREEN, LOW);  digitalWrite(PIN_LED_RED, LOW);  delay(140);
  }
}

/* POST JSON to the server; returns the HTTP code, -1 = no connection */
int postJson(const String &path, JsonDocument &doc, String &out) {
  if (WiFi.status() != WL_CONNECTED) return -1;
  WiFiClient client;
  HTTPClient http;
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.setUserAgent(USER_AGENT);              // the server rejects any other UA
  String url = String(SERVER_BASE) + path;
  if (!http.begin(client, url)) { http.end(); return -1; }

  String payload;
  serializeJson(doc, payload);
  http.addHeader("Content-Type", "application/json");
  int code = http.POST(payload);
  out = http.getString();
  http.end();
  return code;
}

/* Reads one card and returns a space-free hex UID, or "" */
String readCardUid() {
  if (!mfrc522.PICC_IsNewCardPresent()) return "";
  if (!mfrc522.PICC_ReadCardSerial()) return "";
  String uid = "";
  for (byte i = 0; i < mfrc522.uid.size; i++) {
    if (mfrc522.uid.uidByte[i] < 0x10) uid += "0";
    uid += String(mfrc522.uid.uidByte[i], HEX);
  }
  mfrc522.PICC_HaltA();
  return uid;
}

/* -------------------------------- pairing -------------------------------- */
void enterPairingMode() {
  pairingMode = true;
  twoLine("Pair mode", "WiFi: QRAttendance-Attend", 600);

  WiFiManager wm;
  WiFiManagerParameter field("code", "Pairing code (from the site)", pairCode.c_str(), 7);
  wm.addParameter(&field);
  wm.setConfigPortalTimeout(180);

  // Opens the captive portal (Wi-Fi credentials + pairing code); blocking.
  bool connected = wm.startConfigPortal("QRAttendance-Attend", "qrattendance");
  if (!connected) {
    twoLine("Portal closed", "Press pair to retry", 1500);
    pairingMode = false;
    idleScreen();
    return;
  }
  pairCode = String(field.getValue());
  pairCode.trim();
  pairCode.toUpperCase();
  if (pairCode.length() != 6) {
    twoLine("Pair code missing", "Press pair again", 1500);
    pairingMode = false;
    idleScreen();
    return;
  }
  twoLine("Pairing...", pairCode.c_str());
  pollPairing();
}

/* Polls /api/rfid_pair.php every 2 s until the code matches or it expires. */
void pollPairing() {
  unsigned long start = millis();
  unsigned long lastPost = 0;
  while (millis() - start < PAIR_TIMEOUT_MS) {
    if (millis() - lastPost >= PAIR_POLL_MS || lastPost == 0) {
      lastPost = millis();
      JsonDocument doc;
      doc["pairing_code"] = pairCode;
      doc["device_uid"] = WiFi.macAddress();
      String out;
      int code = postJson("/api/rfid_pair.php", doc, out);

      JsonDocument res;
      DeserializationError err = deserializeJson(res, out);
      if (code > 0 && !err && res["ok"].as<bool>()) {
        deviceToken = res["device_token"].as<String>();
        sectionId = res["section_id"].as<int>();
        readerName = res["reader_name"].as<String>();
        prefs.putString("device_token", deviceToken);
        prefs.putInt("section_id", sectionId);
        prefs.putString("reader_name", readerName);
        prefs.putString("pair_code", "");
        twoLine("Paired!", (readerName + " is ready").c_str(), 1800);
        pairingMode = false;
        lastUid[0] = '\0';
        serverOnline = true;
        idleScreen();
        return;
      }
      twoLine("Pairing...", ("code " + pairCode).c_str());
      Serial.printf("pairing HTTP %d: %s\n", code, out.c_str());
    }
    // holding the pair button during the wait cancels
    if (digitalRead(PIN_PAIR_BTN) == LOW) delay(50);
    if (digitalRead(PIN_PAIR_BTN) == LOW) { delay(400); break; }
    delay(50);
  }
  pairingMode = false;
  twoLine("Pairing timed out", "Press pair to retry", 1500);
  idleScreen();
}

/* -------------------------------- heartbeat ------------------------------ */
void sendHeartbeat() {
  if (deviceToken.length() == 0) return;
  JsonDocument doc;
  doc["device_token"] = deviceToken;
  String out;
  int code = postJson("/api/rfid_heartbeat.php", doc, out);

  JsonDocument res;
  DeserializationError err = deserializeJson(res, out);
  if (code <= 0) {
    serverOnline = false;
    return;                                     // keep last state, retry next cycle
  }
  serverOnline = (code == 200 && !err && res["ok"].as<bool>());
  sessionOpen = serverOnline && !res["session"].isNull();
  if (serverOnline) {
    JsonVariant session = res["session"];
    const char *title = session["title"] | "";
    if (title[0]) statusLine = title;
  }
}

/* -------------------------------- check-in -------------------------------- */
void handleUid(const String &uid) {
  if (uid.length() < 4) return;
  if (uid == String(lastUid) && millis() - lastUidMs < DEBOUNCE_MS) return;
  uid.toCharArray(lastUid, sizeof(lastUid));
  lastUidMs = millis();

  if (deviceToken.length() == 0) {
    showResultScreen("Not paired", "Hold the pair button");
    beep(0); led(0); delay(2000); idleScreen();
    return;
  }

  twoLine("Reading...", uid.substring(0, 10).c_str());

  JsonDocument doc;
  doc["device_token"] = deviceToken;
  doc["uid"] = uid;
  String out;
  int code = postJson("/api/rfid_checkin.php", doc, out);

  if (code <= 0) {                              // network / server unreachable
    serverOnline = false;
    showResultScreen("Server offline", "Tap again shortly");
    beep(0); led(0); delay(OK_HOLD_MS); idleScreen();
    return;
  }

  JsonDocument res;
  if (deserializeJson(res, out)) {
    showResultScreen("Bad response", "Try again");
    beep(0); led(0); delay(2000); idleScreen();
    return;
  }

  const char *status = res["status"] | "error";
  const char *name = res["student_name"] | "";
  const char *time = res["time"] | "";
  const char *error = res["error"] | "";

  String line = String(name);
  if (line.length() > 18) line = line.substring(0, 17) + "...";

  if (strcmp(status, "present") == 0 || strcmp(status, "late") == 0) {
    char mark[3] = { (char)251, ' ', '\0' };   // CP437 check mark
    showResultScreen(mark, (line + "  " + time).c_str());
    beep(2); led(1); delay(OK_HOLD_MS);
  } else if (strcmp(status, "duplicate") == 0) {
    showResultScreen("Already", "recorded");
    beep(1); led(2); ledBlinkAmber(4); delay(2000);
  } else {
    String msg = error;
    if (msg.length() > 32) msg = msg.substring(0, 31);
    if (msg.indexOf("session") >= 0) showResultScreen("Session", "closed");
    else if (msg.indexOf("not") >= 0) showResultScreen("Not", "enrolled");
    else showResultScreen("Try again", msg.substring(0, 18).c_str());
    Serial.printf("checkin HTTP %d: %s\n", code, out.c_str());
    beep(0); led(0); delay(2400);
  }

  led(-1);
  idleScreen();
}

/* --------------------------------- setup ---------------------------------- */
void setup() {
  Serial.begin(115200);
  delay(200);
  Serial.println("\nQRAttendance reader boot");

  pinMode(PIN_BUZZER, OUTPUT);
  pinMode(PIN_LED_GREEN, OUTPUT);
  pinMode(PIN_LED_RED, OUTPUT);
  pinMode(PIN_PAIR_BTN, INPUT_PULLUP);
  digitalWrite(PIN_BUZZER, LOW);
  led(-1);

  Wire.begin(21, 4);                             // OLED SDA/SCL
  if (!display.begin(SSD1306_SWITCHCAPVCC, 0x3C)) Serial.println("OLED missing");
  display.clearDisplay(); display.setTextColor(SSD1306_WHITE);

  SPI.begin();                                   // MFRC522 defaults to the pins above
  mfrc522.PCD_Init();

  prefs.begin("qratt", false);
  deviceToken = prefs.getString("device_token", "");
  sectionId = prefs.getInt("section_id", 0);
  readerName = prefs.getString("reader_name", "Reader");
  pairCode = prefs.getString("pair_code", "");

  twoLine("QRAttendance", "Connecting to WiFi...");
  WiFi.mode(WIFI_STA);
  WiFiManager wm;
  if (!wm.autoConnect(SECRET_SSID[0] ? SECRET_SSID : "QRAttendance-Attend",
                      SECRET_PASS[0] ? SECRET_PASS : "qrattendance")) {
    twoLine("No Wi-Fi", "Restarting...", 1800);
    ESP.restart();
  }

  if (deviceToken.length() == 0) {
    twoLine("Reader not paired", "Press the pair button", 1200);
  }
  sendHeartbeat();
  idleScreen();
}

/* ---------------------------------- loop ----------------------------------- */
void loop() {
  // pair button (active low) → captive portal to enter the pairing code
  if (digitalRead(PIN_PAIR_BTN) == LOW) {
    delay(45);
    if (digitalRead(PIN_PAIR_BTN) == LOW) {
      digitalWrite(PIN_BUZZER, HIGH); delay(60); digitalWrite(PIN_BUZZER, LOW);
      while (digitalRead(PIN_PAIR_BTN) == LOW) delay(10);
      enterPairingMode();
    }
  }

  if (!pairingMode) {
    String uid = readCardUid();
    if (uid.length() && !pairingMode) handleUid(uid);
  }

  if (millis() - lastHeartbeatMs >= HEARTBEAT_MS || lastHeartbeatMs == 0) {
    lastHeartbeatMs = millis();
    if (!pairingMode) sendHeartbeat();
  }

  if (!pairingMode) delay(80);
}
