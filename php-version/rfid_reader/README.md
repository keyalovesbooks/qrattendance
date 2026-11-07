# QRAttendance ESP32 reader

A door-side RFID reader: a student taps their school ID, the reader posts the
card UID to the PHP server, and the student is marked present in the currently
open class session.

Files in this folder:

| File | Purpose |
| --- | --- |
| `rfid_reader.ino` | main firmware (Arduino IDE 2.x project) |
| `secrets.example.h` | copy to `secrets.h` and fill in Wi-Fi + server URL |

---

## 1. Parts

- ESP32-WROOM-32 dev board
- MFRC522 (13.56 MHz, SPI)
- SSD1306 128×64 OLED (I2C)
- Active buzzer (3.3 V)
- Green LED (GPIO 26), red LED (GPIO 27), plus 2 × 220 Ω resistors
- Tactile button for pair mode (GPIO 32)
- Optional: 10 kΩ pull-down not needed (internal `INPUT_PULLUP` is used)

## 2. Wiring (ASCII)

```
                     ┌──────────── ESP32-WROOM-32 ────────────┐
  MFRC522                          3V3/EN ─┐     ┌─ GPIO32 ──── Pair button
  ──────                          SDA/SS  ─│─ 5  │  GPIO27 ──── LED red
   SDA ────────────────────────────────────┤     │  GPIO26 ──── LED green
   SCK ────────────────────────────────────┤ 18  │  GPIO25 ──── Buzzer (+)
   MOSI ───────────────────────────────────┤ 23  │  GPIO21 ──── OLED SDA
   MISO ───────────────────────────────────┤ 19  │  GPIO4  ──── OLED SCL
   RST ────────────────────────────────────┤ 22  │  3V3   ──── MFRC522 VCC,
                                          │     │              OLED VCC
   VCC ── 3V3 (not 5V!)                   │     │  GND   ──── all grounds
   GND ── GND ─────────────────────────────┘     └──────────────────────────

  Pair button:  GPIO32 ──┤ ├──── GND   (internal INPUT_PULLUP, active LOW)
  LEDs:         each GPIO ── 220Ω ── LED anode; LED cathode ── GND
  Buzzer:       GPIO25 ── (+); buzzer (−) ── GND
```

Shared grounds are required. Power the ESP32 over USB (or 5 V on VIN); the
board’s 3.3 V rail supplies the MFRC522 and the OLED.

## 3. Libraries (Arduino IDE → Sketch → Include Library → Manage Libraries)

| Library | Author / ID | Version tested |
| --- | --- | --- |
| MFRC522 | GithubCommunity | 1.4.11 |
| Adafruit SSD1306 | Adafruit | 2.5.13 |
| Adafruit GFX Library | Adafruit | 1.11.11 |
| ArduinoJson | Benoit Blanchon | 7.3.1 |
| WiFiManager | tzapu | 2.0.17 |

Board support: **esp32 by Espressif** in the Boards Manager — select
`ESP32 Dev Module`. USB-to-serial drivers (CP210x / CH340) as needed.

## 4. Configure & flash

1. Copy `secrets.example.h` → `secrets.h` and set `SECRET_SSID`, `SECRET_PASS`
   and `SERVER_BASE` (scheme + domain, no trailing slash).
2. Open `rfid_reader.ino` in Arduino IDE 2.x.
3. Select the board and port, click **Verify**, then **Upload** (115200 baud
   serial monitor is enabled for debugging).

## 5. First-time setup

1. **Pair the reader** — `reader.php` → *Add reader* → choose a section → you
   get a 6-character code valid for 5 minutes.
2. Press the **pair button** on the ESP32 (GPIO 32). The OLED shows
   `Pair mode`; the reader starts the Wi-Fi portal `QRAttendance-Attend`
   (password `qrattendance`) — join it with a phone and the captive portal
   appears. Enter your Wi-Fi and the pairing code, then save.
3. The reader posts the code every 2 s to `api/rfid_pair.php`. On success the
   OLED shows `Paired!` and the reader stores `device_token` + `section_id` in
   NVS (`Preferences`), so it never needs pairing again.
4. **Check the reader page** — it appears in `reader.php` with an online dot
   (the reader heartbeats every 20 s to `api/rfid_heartbeat.php`).
5. **Open attendance** for that section (`live.php`, `scan.php` or the
   dashboard button). The OLED shows `session: OPEN`.

## 6. Student card enrollment

`student/rfid.php` → *Link my school ID* → 6-character pairing code → the
reader (paired to any section of your school) shows *Tap your ID* and posts
the UID to `api/rfid_enroll.php`. The server stores only `SHA-256(uid)`.

## 7. Daily use

| Screen | Meaning |
| --- | --- |
| `QRAttendance / Tap your ID` | idle, ready |
| `Reading…` | card detected, server call in progress |
| `✓ Name  9:41 AM` | present (green LED solid 3 s, 1 short beep) |
| `Already recorded` | duplicate tap (amber blink, 2 short beeps) |
| `Not enrolled` / `Session closed` / `Server offline` | error (red LED 3 s, 1 long beep) |

Same UID within 3 seconds is ignored. Taps are rate-limited server-side to
30/minute per reader.

## 8. HTTP contract (for debugging)

Every reader endpoint requires **POST** and the header
`User-Agent: QRAttendance-ESP32/1.0`; others are rejected with `405`.

- `POST /api/rfid_pair.php` → `{pairing_code, device_uid}` → `{ok, device_token, section_id, reader_name}`
- `POST /api/rfid_checkin.php` → `{device_token, uid}` → `{ok, status, student_name, student_number, session_title, section_name, time}`
- `POST /api/rfid_heartbeat.php` → `{device_token}` → `{ok, server_time, session}`

If the server answers `401`, the reader was revoked — pair it again.
