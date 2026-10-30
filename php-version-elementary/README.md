# QRAttendance — Elementary / Primary Build

A **standalone** version of QRAttendance for **primary (elementary) teachers and elementary students only**.

The general build (which supports primary, secondary and tertiary levels) lives in the sibling
`php-version/` folder and is **untouched**. This folder is a separate copy with the level
selection removed, so every account is elementary by design.

---

## What is different from the general build

| | General build (`php-version/`) | This build (`php-version-elementary/`) |
| --- | --- | --- |
| Professor level | Primary / Secondary / Tertiary picker at sign-up | **Always primary (elementary)** — no picker |
| Student level | Elementary / High school / College picker at sign-up | **Always elementary** — no picker |
| Time in / time out | Shown only to primary teachers | **Always available** |
| Student QR | Elementary gets a permanent semester QR; others get a 90-second pass | **Always** the permanent semester QR |
| Everything else | Identical | Identical |

All elementary functions from the general build are preserved:

- **Professor:** school year/semester → subject → section → CSV class list → enrollment QR → attendance
- **Record by class (subject)** with morning/afternoon **time in / time out**
- **Record by section** (direct sections, no subject) with four **30-minute attendance windows**
- **QR scanning** with the device camera
- **RFID** card check-in at the door (ESP32)
- **Student attendance calendar** — green (present), red (absent), yellow (holiday)
- **Holidays** management
- **History / CSV export**, per school year and semester
- **Security:** brute-force lockout (6 failures per email+IP → 30 min), hashed passwords, CSRF, RBAC, IDOR checks

---

## SongSong Elementary School branding assets

The interface is branded for **SongSong Elementary School, Gamu, Isabela**. Place the supplied image files in this folder:

- `logo.jpeg` — used in the sign-in header and the authenticated sidebar.
- `school.jpeg` — used as the sign-in hero background (with the blue theme overlay).

Both JPEGs are included in this build. The styles retain a blue-gradient fallback if `school.jpeg` is removed or unavailable.

Nationwide Philippine holidays are loaded from the bundled `ph_holidays.php` dataset and merged with teacher-entered local/school holidays. Update that dataset when the Official Gazette publishes each year's proclamation. 2026 and 2027 nationwide schedules are included; Eid dates are only included when formally proclaimed.

## Install on shared hosting

1. **Create a MySQL database** and import `schema.sql` with phpMyAdmin.
2. **Edit `config.php`**: `DB_DSN`, `DB_USER`, `DB_PASS`, `BASE_URL` and your timezone.
3. **Install the QR library**, then upload everything **including `vendor/`**:
   ```bash
   cd php-version-elementary
   composer install
   ```
4. Turn on **HTTPS** (required for camera access).
5. Open `https://yourdomain.com/register.php`. The **first account becomes the professor**;
   every later sign-up is a student.

If the database predates this build, run `migrate.php` once (signed in as the professor),
press **Apply all changes**, then **delete** `migrate.php`.

---

## Files

```
php-version-elementary/
├── config.php               DB settings, helpers, layout
├── schema.sql               MySQL tables
├── composer.json            chillerlan/php-qrcode
├── login.php / register.php / logout.php / forgot-password.php / reset-password.php
├── dashboard.php            Overview + attendance-mode choice + setup checklist
├── classes.php              Subjects & sections (incl. direct sections)
├── section.php              Class list, schedules, time in/out, calendar, holidays
├── students.php             Roster (import/edit/export)
├── live.php                 Live attendance + direct windows
├── scan.php                 Camera scanner
├── history.php              History + line/bar charts (day/week/month)
├── settings.php             Workspace settings (terms, attendance mode)
├── profile.php              Profile (name, picture, password)
├── migrate.php              One-click DB migration
├── api/                     checkin, rfid_*, attendance_calendar
├── student/                 index, enroll, permanent QR, rfid, profile
├── docs/                    manual.html, background-web.html, background-mobile.html
├── rfid_reader/             ESP32 firmware
├── assets/                  style.css, app.js, scanner.js, rfid.js
├── uploads/avatars/         profile pictures
├── schema_*.sql             Individual migrations
├── SECURITY.md              Threat model + implementation per feature
└── README.md
```

## Professor flow

Set the school year and semester → choose **record by class (subject)** or **record by section** →
create the subject/section → import the CSV class list → share the **Enrollment QR** → take attendance
(camera scan, or RFID tap at the door).

## Student flow

Scan the enrollment QR → sign in or register → enter the student ID → open **My QR code** and print it
(it works all semester) → show it to the professor, or tap the RFID card at the door.

See `SECURITY.md` for the threat model and the implementation details behind every control.
