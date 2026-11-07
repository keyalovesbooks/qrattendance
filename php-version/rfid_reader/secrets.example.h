// ============================================================================
// secrets.example.h – template for the ESP32 reader's private settings.
//
// How to use:
//   1. Copy this file next to rfid_reader.ino and rename it to `secrets.h`
//      (never commit secrets.h to version control).
//   2. Fill in your own Wi-Fi network and the base URL of the PHP site.
//
// If secrets.h is missing, rfid_reader.ino still compiles: it falls back to
// the captive portal (SSID "QRAttendance-Attend", password "qrattendance")
// and the placeholder SERVER_BASE below, which you must edit in the .ino.
// ============================================================================
#pragma once

// Wi-Fi network the reader should join (leave empty "" to force the portal)
#define SECRET_SSID "MyHomeWiFi"
#define SECRET_PASS "supersecret"

// Base URL of the QRAttendance site – NO trailing slash, scheme included.
// Examples:  "https://qrattendance.example.com"  or  "https://192.168.1.50"
#define SERVER_BASE "https://yourdomain.com"
