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
| Other included functions | Identical | Identical (RFID omitted) |

The core elementary workflow includes:

- **Professor:** school year/semester → subject → section → CSV class list → enrollment QR → attendance
- **Record by class (subject)** with morning/afternoon **time in / time out**
- **Record by section** (direct sections, no subject) with four **30-minute attendance windows**
- **QR scanning** with the device camera
- **Student attendance calendar** — green (present), red (absent), yellow (holiday)
- **Holidays** management
- **History / CSV export**, per school year and semester
- **Security:** brute-force lockout (6 failures per email+IP → 30 min), hashed passwords, CSRF, RBAC, IDOR checks

---

## SongSong Elementary School branding assets

The interface is branded for **SongSong Elementary School, Gamu, Isabela**. Place the supplied image files in this folder:

- `school.jpeg` — used as the sign-in hero background (with the blue theme overlay); keep it beside `login.php`.
- `logo.jpeg` — original school crest asset. A reduced copy is embedded directly into `login.php` so the sign-in logo still appears if the host blocks the separate image URL.

Both JPEGs are included in this build. The hero also retains a blue-gradient fallback if `school.jpeg` is removed or unavailable.

Nationwide Philippine holidays are loaded from the bundled `ph_holidays.php` dataset and merged with teacher-entered local/school holidays. Update that dataset when the Official Gazette publishes each year's proclamation. 2026 and 2027 nationwide schedules are included; Eid dates are only included when formally proclaimed.

## Security deployment configuration

- Set `DB_DSN`, `DB_USER`, and `DB_PASS` privately in `config.php`; never commit live credentials.
- Confirm `APP_ORIGIN` matches the exact HTTPS domain hosting this copy (`qrattendance2.site.je` for the general build; `qrattendance-elem.site.je` for the elementary build). It is used for password-reset and enrollment links to prevent Host-header poisoning.
- For a new database, the consolidated `schema.sql` already creates the login-lockout and holiday tables. For an existing database, use the migration utility rather than re-importing the fresh-install schema.
- Ordinary sign-in sessions expire after 30 minutes idle or 12 hours absolute. Remembered sessions are capped at 30 days. Keep both `.htaccess` files, including `uploads/.htaccess`, when deploying.
- Confirm host-side PHP error-log access and scheduled backups/restore separately; the app cannot configure provider backups.

## Install on shared hosting

1. **Create a MySQL database** and import the consolidated `schema.sql` with phpMyAdmin. It contains the complete current schema for this PHP app, including attendance windows, holidays, lockouts, and password-reset tables; for a new database, import this one file only.
2. **Edit `config.php`**: `DB_DSN`, `DB_USER`, `DB_PASS`, `BASE_URL` and your timezone.
3. **Install the QR and spreadsheet libraries**, then upload everything **including `vendor/`**:
   ```bash
   cd php-version-elementary
   composer install
   ```
4. Ensure the host provides the Composer-required PHP extensions, including ZIP/XML/GD for Excel import.
5. Turn on **HTTPS** (required for camera access).
6. Open `https://yourdomain.com/register.php`. The **first account becomes the professor**;
   every later sign-up is a student.

If the database predates this build, run `migrate.php` once (signed in as the professor),
press **Apply all changes**, then **delete** `migrate.php`.

---

## Files

```
php-version-elementary/
├── config.php               DB settings, helpers, layout
├── schema.sql               Complete MySQL schema for fresh installs
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
├── api/                     checkin, attendance_calendar
├── student/                 index, enroll, permanent QR, profile
├── docs/                    manual.html, background-web.html, background-mobile.html
├── assets/                  style.css, app.js, scanner.js
├── uploads/avatars/         profile pictures
├── schema_*.sql             Individual migrations for older installations only
├── SECURITY.md              Threat model + implementation per feature
└── README.md
```

## Professor flow

Set the school year and semester → choose **record by class (subject)** or **record by section** →
create the subject/section → import the CSV class list → share the **Enrollment QR** → take attendance
(camera scan).

## Student flow

Scan the enrollment QR → sign in or register → enter the student ID → open **My QR code** and print it
(it works all semester) → show it to the professor.

See `SECURITY.md` for the threat model and the implementation details behind every control.


## Password recovery

Password recovery does not send email. When the entered address matches an account, the app creates a random one-time token, stores only its SHA-256 hash, and displays the reset link on screen. The link expires after 60 minutes and can be used once. Anyone who can access the page and knows a registered email address can request and see a reset link, so use this only if that trade-off is acceptable. No Brevo, SMTP, PHP `mail()`, or PHPMailer configuration is required.

## Elementary clock-style time picker

On section creation and the section attendance-window form, click the clock-face button beside a time field. Select an hour on the circular dial, then minutes, choose AM/PM, and press Set time. The app submits the value as `HH:MM`.

## Roster import formats

The roster import accepts `.csv`, `.xls`, and `.xlsx` files up to 5 MB and 500 students. Put student ID in column A and full name in column B; an optional header row is allowed. Excel reading uses PhpSpreadsheet, installed by `composer install`, and requires the PHP extensions Composer reports (including ZIP/XML/GD).
