# QRAttendance – PHP 8.2 / MySQL version

## Roster, calendar, and national holidays

`students.php` and each `section.php` roster show 20 student cards per page with accessible pagination. CSV roster imports use exactly two columns: `student_id, name` (an optional header row is accepted); email is not displayed or imported. The calendar combines teacher-entered holidays with the bundled Philippine nationwide holiday dataset (`ph_holidays.php`). Refresh that dataset from the Official Gazette each year; annual Eid observances are only listed once officially proclaimed.

## After pulling these changes
1. Run the new SQL (phpMyAdmin → your database → SQL):
   ```sql
   ALTER TABLE users ADD COLUMN avatar VARCHAR(120) NULL;
   CREATE TABLE IF NOT EXISTS section_schedules (
     id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
     section_id INT UNSIGNED NOT NULL,
     day ENUM('Mon','Tue','Wed','Thu','Fri','Sat','Sun') NOT NULL,
     start_time TIME NOT NULL,
     end_time TIME NOT NULL,
     room VARCHAR(60) NOT NULL DEFAULT '',
     FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
   ```
   A fresh install just needs `database-schema-all-in-one.sql`.
2. Make `uploads/` writable (or let PHP create it) so profile pictures can be stored.

The **top bar** matches the Next.js app: breadcrumb, search (⌘K / Ctrl-K focuses it), the academic-term
dropdown, a notifications bell and the account menu.

**Workspace settings** (`settings.php`) is where the school year and semester are managed: add a term,
set the current one, or archive it. Classes, rosters and attendance history all follow the selected term.

## Files
```
php-version/
├── config.php          DB settings, session, helpers, layout (edit this first)
├── database-schema-all-in-one.sql  Complete MySQL schema for fresh installs
├── login.php / register.php / logout.php / index.php
├── dashboard.php       Professor overview: term settings, classes today, live sessions
├── classes.php         Professor: subjects, sections and their meeting days/times
├── students.php        Professor: students roster (search, add/edit/delete, export)
├── live.php            Professor: open sessions, scanner links, start/end sessions
├── section.php         Professor: schedules, CSV import, student CRUD, enrollment QR, start session
├── term.php            Switches the active academic term from the top-bar dropdown
├── settings.php        Workspace settings: academic terms, account, sign out
├── profile.php         Profile: name, email, password and profile picture (both roles)
├── student/profile.php Same page for students
├── scan.php            Professor: camera scanner
├── history.php         Professor: history per school year & semester
├── export.php          CSV export (roster or one session)
├── api/checkin.php     Validates a scanned QR and records attendance
├── student/index.php   Student dashboard + attendance history
├── student/enroll.php  Student enrolls by scanning section QR + entering student ID
├── student/qr.php      Student's one-time QR code (expires after 60 s)
├── uploads/avatars/    profile pictures (created automatically)
└── assets/             style.css, app.js, scanner.js
```

## Elementary vs highschool / college

The level chosen at sign-up decides how a student checks in.

| | Elementary | Highschool / College |
| --- | --- | --- |
| QR lifetime | One code for the **whole semester** — it never expires | Time-limited pass, 90 seconds, single use |
| Page | `student/permanent-qr.php` (printable) | `student/qr.php` (generate on demand) |
| Professor scans | `QRS:<CODE>` — recorded for any open session of that section | `QRA:<token>` — tied to one session |
| Duplicate | Blocked by `UNIQUE(session_id, student_id)` | Same, plus single-use token |

**Professor (primary / elementary teacher) setup, in order:** create the section
→ import the CSV class list → **Time in / out** on the section page (records
morning *and* afternoon) → students enroll by scanning the section QR or typing
the login code. The dashboard shows this as a tickable checklist for primary
teachers.

## Database migrations (InfinityFree / free hosting)

The separate migration `.sql` files have been retired and removed from this package.
For a **new/empty database**, import `database-schema-all-in-one.sql` once. For an existing database, do not re-import it as an upgrade: use the included `migrate.php` utility and back up the database first. The consolidated SQL uses `CREATE TABLE IF NOT EXISTS`; it does not add missing columns to tables that already exist.


## Security deployment configuration

- Set `DB_DSN`, `DB_USER`, and `DB_PASS` privately in `config.php`; never commit live credentials.
- Confirm `APP_ORIGIN` matches the exact HTTPS domain hosting this copy (`qrattendance2.site.je` for the general build; `qrattendance-elem.site.je` for the elementary build). It is used for password-reset and enrollment links to prevent Host-header poisoning.
- For a new database, the consolidated `database-schema-all-in-one.sql` already creates the login-lockout and holiday tables. For an existing database, use the migration utility rather than re-importing the fresh-install schema.
- Ordinary sign-in sessions expire after 30 minutes idle or 12 hours absolute. Remembered sessions are capped at 30 days. Keep both `.htaccess` files, including `uploads/.htaccess`, when deploying.
- Confirm host-side PHP error-log access and scheduled backups/restore separately; the app cannot configure provider backups.

## Install on shared hosting
1. **Create a MySQL database** in cPanel/hPanel and import the consolidated `database-schema-all-in-one.sql` with phpMyAdmin. It contains the complete current schema for this PHP app, including RFID, attendance windows, holidays, lockouts, and password-reset tables; for a new database, import this one file only.
2. **Edit `config.php`**: `DB_DSN`, `DB_USER`, `DB_PASS`, `BASE_URL` (`''` at domain root) and your timezone.
3. **Install the QR and spreadsheet libraries** on your computer (or over SSH on the host):
   ```bash
   cd php-version
   composer install
   ```
   This installs QR rendering and Excel import. Upload everything, **including the `vendor/` folder**, to `public_html/`. The host must provide the PHP extensions required by Composer (including ZIP/XML/GD for spreadsheet reading and OpenSSL for encrypted SMTP).
4. Turn on **HTTPS** (free AutoSSL / Let's Encrypt). Phone cameras only work over HTTPS.
5. Open `https://yourdomain.com/register.php`. **The first account becomes the professor.** Every later sign-up is a student.

## Roster import formats

The section roster accepts `.csv`, `.xls`, and `.xlsx` files (up to 5 MB, maximum 500 students). Use column A for student ID and column B for full name; the first row may be a header. Formula cells are rejected. Excel reading is provided by PhpSpreadsheet installed through `composer install`.

## How it works
**Professor:** set the school year and semester → choose the primary-teacher attendance mode if applicable.

- **Record by class (subject):** create subject → add section → import CSV `student_id,name,email` → show the **Enrollment QR** → start an attendance session → scan student QR passes.
- **Record by section (primary only):** choose the mode once on the dashboard (it disappears after selection; change it later in Workspace settings), then create a section directly without a subject → import CSV → choose **time in only**, **time out only**, or **both** → configure the enabled morning/afternoon windows. Each enabled start opens a 30-minute scanner window automatically. The dashboard setup checklist disappears after all four steps are complete. Windows in the same section must not overlap; a window also cannot overlap another of that professor’s section schedules.

Subject sections can also have weekly schedules: add one row per meeting (day, start time, end time, room), e.g. a Monday lecture and Thursday lab. The dashboard lists today's subject classes.

**Student:** scan the enrollment QR → sign in or register → enter the student ID (it must be on the class list)
→ when attendance is open, tap **Generate my QR** and show it to the professor.

## Security built in
- `password_hash()` / `password_verify()`, `session_regenerate_id(true)` on login
- “Keep me signed in” sets a 30-day session cookie plus a `qr_remember_email` cookie
- Logout clears the session, remember-me and device cookies
- PDO prepared statements everywhere, CSRF tokens on every form, `htmlspecialchars` output escaping
- QR tokens are random 256-bit values, stored only as SHA-256 hashes, can be used once and expire after 60 seconds
- `.htaccess` forces HTTPS and blocks `vendor/`, `config.php` and `database-schema-all-in-one.sql`

> On some shared hosts, PHP's session cleanup ignores `gc_maxlifetime`. If remember-me logs users out early,
> set `session.save_path` to a private folder in `config.php`.

Icons are **Lucide v1.50.0** — the same set and version the Next.js app uses (`lucide-react`), loaded from
unpkg and rendered by `lucide.createIcons()`.


## Password recovery

Password recovery does not send email. When the entered address matches an account, the app creates a random one-time token, stores only its SHA-256 hash, and displays the reset link on screen. The link expires after 60 minutes and can be used once. Anyone who can access the page and knows a registered email address can request and see a reset link, so use this only if that trade-off is acceptable. No Brevo, SMTP, PHP `mail()`, or PHPMailer configuration is required.
