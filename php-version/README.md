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
   A fresh install just needs `schema.sql`.
2. Make `uploads/` writable (or let PHP create it) so profile pictures can be stored.

The **top bar** matches the Next.js app: breadcrumb, search (⌘K / Ctrl-K focuses it), the academic-term
dropdown, a notifications bell and the account menu.

**Workspace settings** (`settings.php`) is where the school year and semester are managed: add a term,
set the current one, or archive it. Classes, rosters and attendance history all follow the selected term.

## Files
```
php-version/
├── config.php          DB settings, session, helpers, layout (edit this first)
├── schema.sql          MySQL tables
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

Your hosting denies `ALTER ROUTINE` (error **#1370**), so **do not use the
stored-procedure style SQL** — the three migration files here are rewritten to
be **procedure-free** and idempotent:

| File | Adds |
| --- | --- |
| `schema_professor_scope.sql` | `terms.professor_id`, per-professor unique term key, backfill |
| `schema_teacher_level.sql` | `users.teacher_level` (primary / secondary / tertiary) |
| `schema_rfid.sql` | `pairing_tokens`, `rfid_readers`, `students.uid_hash`, `attendance.method` |
| `schema_primary_direct_sections.sql` | subjectless sections, section ownership/mode, 30-minute slot sessions |

They rely on MariaDB's `ADD COLUMN IF NOT EXISTS` / `CREATE INDEX IF NOT EXISTS`.
If a statement still errors, **use the page instead**: upload `migrate.php`,
open `https://yourdomain.com/migrate.php` while signed in as the professor, then
press **Apply all changes**. It checks `information_schema` first, runs only what
is missing, treats "already exists" as success, and shows a Ready/Pending list.
**Delete `migrate.php` when everything says Ready.**

Notes:
- Existing terms are assigned to the **oldest professor account** — that is the
  person who keeps the classes you already created.
- Leftover `qr_*` procedures from an earlier attempt are harmless (you cannot
  drop them without privileges; nothing needs them anymore).

## Install on shared hosting
1. **Create a MySQL database** in cPanel/hPanel and import `schema.sql` with phpMyAdmin.
2. **Edit `config.php`**: `DB_DSN`, `DB_USER`, `DB_PASS`, `BASE_URL` (`''` at domain root) and your timezone.
3. **Install the QR library** on your computer (or over SSH on the host):
   ```bash
   cd php-version
   composer install
   ```
   Then upload everything, **including the `vendor/` folder**, to `public_html/`.
4. Turn on **HTTPS** (free AutoSSL / Let's Encrypt). Phone cameras only work over HTTPS.
5. Open `https://yourdomain.com/register.php`. **The first account becomes the professor.** Every later sign-up is a student.

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
- `.htaccess` forces HTTPS and blocks `vendor/`, `config.php` and `schema.sql`

> On some shared hosts, PHP's session cleanup ignores `gc_maxlifetime`. If remember-me logs users out early,
> set `session.save_path` to a private folder in `config.php`.

Icons are **Lucide v1.50.0** — the same set and version the Next.js app uses (`lucide-react`), loaded from
unpkg and rendered by `lucide.createIcons()`.
