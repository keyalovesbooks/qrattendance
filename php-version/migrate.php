<?php
/**
 * migrate.php – one-click database migration for QRAttendance.
 *
 * WHY THIS EXISTS: InfinityFree (MariaDB) refuses ALTER ROUTINE, so guarded
 * "stored procedure" SQL files fail with error #1370. This page applies the
 * same changes through PHP: it checks information_schema first, runs only what
 * is still missing, and treats "already exists" as success. Works on MariaDB
 * AND MySQL 5.7 / 8.0.
 *
 * SECURITY: only a signed-in professor can open it; changes need the CSRF
 * token. *** DELETE THIS FILE once everything shows "Ready". ***
 */
require __DIR__ . '/config.php';
$user = require_role('professor');

function column_exists(string $table, string $column): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $column]);
    return (int) $st->fetchColumn() > 0;
}

function index_exists(string $table, string $index): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $st->execute([$table, $index]);
    return (int) $st->fetchColumn() > 0;
}

function table_exists(string $table): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$table]);
    return (int) $st->fetchColumn() > 0;
}

function column_is_nullable(string $table, string $column): bool
{
    $st = db()->prepare('SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $column]);
    return $st->fetchColumn() === 'YES';
}

/** 0 = a term is still unowned (backfill pending) */
function unowned_terms(): int
{
    if (!table_exists('terms') || !column_exists('terms', 'professor_id')) return 1;
    return (int) db()->query('SELECT COUNT(*) FROM terms WHERE professor_id IS NULL')->fetchColumn();
}

/** label, group, is-it-done, and the SQL that puts it right */
function migration_steps(): array
{
    return [
        ['group' => 'Academic terms', 'label' => 'terms.professor_id — storage owner',
         'done' => table_exists('terms') && column_exists('terms', 'professor_id'),
         'sql' => 'ALTER TABLE terms ADD COLUMN professor_id INT UNSIGNED NULL AFTER is_current'],

        ['group' => 'Academic terms', 'label' => 'Existing terms assigned to your professor account',
         'done' => unowned_terms() === 0,
         'sql' => "UPDATE terms SET professor_id = (SELECT u.id FROM users u WHERE u.role = 'professor' ORDER BY u.id ASC LIMIT 1) WHERE professor_id IS NULL"],

        ['group' => 'Academic terms', 'label' => 'Index idx_terms_professor',
         'done' => index_exists('terms', 'idx_terms_professor'),
         'sql' => 'CREATE INDEX idx_terms_professor ON terms (professor_id)'],

        ['group' => 'Academic terms', 'label' => 'Unique key per professor (year + semester)',
         'done' => index_exists('terms', 'uq_term_owner'),
         'sql' => 'CREATE UNIQUE INDEX uq_term_owner ON terms (professor_id, school_year, semester)'],

        ['group' => 'Academic terms', 'label' => 'Old global key uq_term removed',
         'done' => !index_exists('terms', 'uq_term'),
         'sql' => 'ALTER TABLE terms DROP INDEX uq_term'],

        ['group' => 'Sign-up', 'label' => 'users.teacher_level (primary / secondary / tertiary)',
         'done' => column_exists('users', 'teacher_level'),
         'sql' => 'ALTER TABLE users ADD COLUMN teacher_level VARCHAR(20) NULL AFTER student_number'],

        ['group' => 'Primary attendance mode', 'label' => 'Sections may be created without a subject',
         'done' => column_exists('sections', 'subject_id') && column_is_nullable('sections', 'subject_id'),
         'sql' => 'ALTER TABLE sections MODIFY subject_id INT UNSIGNED NULL'],

        ['group' => 'Primary attendance mode', 'label' => 'sections.term_id',
         'done' => column_exists('sections', 'term_id'),
         'sql' => 'ALTER TABLE sections ADD COLUMN term_id INT UNSIGNED NULL'],

        ['group' => 'Primary attendance mode', 'label' => 'sections.professor_id',
         'done' => column_exists('sections', 'professor_id'),
         'sql' => 'ALTER TABLE sections ADD COLUMN professor_id INT UNSIGNED NULL'],

        ['group' => 'Primary attendance mode', 'label' => 'sections.section_mode (subject / direct)',
         'done' => column_exists('sections', 'section_mode'),
         'sql' => "ALTER TABLE sections ADD COLUMN section_mode ENUM('subject','direct') NOT NULL DEFAULT 'subject'"],

        ['group' => 'Primary attendance mode', 'label' => 'Backfill ownership of existing subject sections',
         'done' => column_exists('sections','term_id') && column_exists('sections','professor_id')
            && (int) db()->query('SELECT COUNT(*) FROM sections WHERE term_id IS NULL OR professor_id IS NULL')->fetchColumn() === 0,
         'sql' => 'UPDATE sections s JOIN subjects sub ON sub.id = s.subject_id JOIN terms t ON t.id = sub.term_id
                   SET s.term_id=t.id, s.professor_id=t.professor_id WHERE s.term_id IS NULL OR s.professor_id IS NULL'],

        ['group' => 'Primary attendance mode', 'label' => 'class_sessions.session_date',
         'done' => column_exists('class_sessions', 'session_date'),
         'sql' => 'ALTER TABLE class_sessions ADD COLUMN session_date DATE NULL'],

        ['group' => 'Primary attendance mode', 'label' => 'class_sessions.attendance_slot',
         'done' => column_exists('class_sessions', 'attendance_slot'),
         'sql' => "ALTER TABLE class_sessions ADD COLUMN attendance_slot ENUM('am_in','am_out','pm_in','pm_out') NULL"],

        ['group' => 'Primary attendance mode', 'label' => 'Unique section/date/slot session',
         'done' => index_exists('class_sessions', 'uq_section_attendance_slot'),
         'sql' => 'CREATE UNIQUE INDEX uq_section_attendance_slot ON class_sessions (section_id, session_date, attendance_slot)'],

        ['group' => 'RFID', 'label' => 'Table pairing_tokens',
         'done' => table_exists('pairing_tokens'),
         'sql' => 'CREATE TABLE IF NOT EXISTS pairing_tokens (
             id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
             token_hash CHAR(64) NOT NULL,
             user_id INT UNSIGNED NOT NULL,
             purpose ENUM(\'enroll_card\',\'register_reader\') NOT NULL DEFAULT \'enroll_card\',
             section_id INT UNSIGNED NULL,
             reader_name VARCHAR(60) NULL,
             pairing_code CHAR(6) NOT NULL,
             expires_at DATETIME NOT NULL,
             used_at DATETIME NULL,
             created_at DATETIME NOT NULL,
             UNIQUE KEY uq_pairing_hash (token_hash),
             KEY idx_pairing_code (pairing_code),
             FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
             FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'],

        ['group' => 'RFID', 'label' => 'Table rfid_readers',
         'done' => table_exists('rfid_readers'),
         'sql' => 'CREATE TABLE IF NOT EXISTS rfid_readers (
             id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
             name VARCHAR(60) NOT NULL,
             section_id INT UNSIGNED NULL,
             device_uid VARCHAR(64) NULL,
             pairing_code CHAR(6) NULL,
             token_hash CHAR(64) NOT NULL,
             revoked_at DATETIME NULL,
             last_seen DATETIME NULL,
             last_tap_student VARCHAR(120) NULL,
             last_tap_at DATETIME NULL,
             rate_count INT UNSIGNED NOT NULL DEFAULT 0,
             rate_window_start DATETIME NULL,
             created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
             UNIQUE KEY uq_reader_token (token_hash),
             KEY idx_reader_section (section_id),
             FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'],

        ['group' => 'RFID', 'label' => 'students.uid_hash (hashed card UID)',
         'done' => column_exists('students', 'uid_hash'),
         'sql' => 'ALTER TABLE students ADD COLUMN uid_hash CHAR(64) NULL'],

        ['group' => 'RFID', 'label' => 'students.card_linked_at',
         'done' => column_exists('students', 'card_linked_at'),
         'sql' => 'ALTER TABLE students ADD COLUMN card_linked_at DATETIME NULL'],

        ['group' => 'RFID', 'label' => 'Unique card per student (uid_hash)',
         'done' => index_exists('students', 'uq_students_uid_hash'),
         'sql' => 'CREATE UNIQUE INDEX uq_students_uid_hash ON students (uid_hash)'],

        ['group' => 'RFID', 'label' => 'attendance.method (qr / rfid)',
         'done' => column_exists('attendance', 'method'),
         'sql' => 'ALTER TABLE attendance ADD COLUMN method ENUM(\'qr\',\'rfid\') DEFAULT \'qr\''],

        ['group' => 'Sign-up', 'label' => 'users.student_level (elementary / highschool / college)',
         'done' => column_exists('users', 'student_level'),
         'sql' => 'ALTER TABLE users ADD COLUMN student_level VARCHAR(20) NULL AFTER teacher_level'],

        ['group' => 'Login security', 'label' => 'Table login_attempts (brute-force lockout per email + IP)',
         'done' => table_exists('login_attempts'),
         'sql' => 'CREATE TABLE IF NOT EXISTS login_attempts (
             id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
             email VARCHAR(190) NOT NULL,
             ip VARCHAR(45) NOT NULL,
             attempts INT UNSIGNED NOT NULL DEFAULT 0,
             locked_until DATETIME NULL,
             updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
             UNIQUE KEY uq_login_attempt (email, ip)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'],

        ['group' => 'Attendance calendar', 'label' => 'Table holidays (yellow dates on the attendance calendar)',
         'done' => table_exists('holidays'),
         'sql' => 'CREATE TABLE IF NOT EXISTS holidays (
             id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
             professor_id INT UNSIGNED NOT NULL,
             holiday_date DATE NOT NULL,
             name VARCHAR(120) NOT NULL DEFAULT \'\',
             created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
             UNIQUE KEY uq_holiday_owner_date (professor_id, holiday_date),
             FOREIGN KEY (professor_id) REFERENCES users(id) ON DELETE CASCADE
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'],

        ['group' => 'Password reset', 'label' => 'Table password_resets (forgot-password flow)',
         'done' => table_exists('password_resets'),
         'sql' => 'CREATE TABLE IF NOT EXISTS password_resets (
             id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
             user_id INT UNSIGNED NOT NULL,
             token_hash CHAR(64) NOT NULL UNIQUE,
             expires_at DATETIME NOT NULL,
             used_at DATETIME NULL,
             KEY idx_password_resets_user (user_id),
             FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'],

        ['group' => 'Sign-up', 'label' => 'users.first_name / middle_name / last_name (split name fields)',
         'done' => column_exists('users', 'first_name') && column_exists('users', 'middle_name') && column_exists('users', 'last_name'),
         'sql' => 'ALTER TABLE users ADD COLUMN first_name VARCHAR(60) NULL'],

        ['group' => 'Primary teacher workflow', 'label' => 'Save attendance mode choice per professor',
         'done' => column_exists('users', 'primary_attendance_mode'),
         'sql' => "ALTER TABLE users ADD COLUMN primary_attendance_mode ENUM('subject','direct') NULL AFTER teacher_level"],

        ['group' => 'Attendance times', 'label' => 'Table section_attendance_times (AM/PM time in & out)',
         'done' => table_exists('section_attendance_times'),
         'sql' => 'CREATE TABLE IF NOT EXISTS section_attendance_times (
             id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
             section_id INT UNSIGNED NOT NULL,
             attendance_mode ENUM(\'time_in\',\'time_out\',\'both\') NOT NULL DEFAULT \'both\',
             am_in TIME NULL,
             am_out TIME NULL,
             pm_in TIME NULL,
             pm_out TIME NULL,
             UNIQUE KEY uq_section_attendance_times (section_id),
             FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'],

        ['group' => 'Attendance times', 'label' => 'Select time-in / time-out / both per direct section',
         'done' => column_exists('section_attendance_times', 'attendance_mode'),
         'sql' => "ALTER TABLE section_attendance_times ADD COLUMN attendance_mode ENUM('time_in','time_out','both') NOT NULL DEFAULT 'both' AFTER section_id"],

        ['group' => 'Student QR', 'label' => 'students.qr_code (permanent semester-long QR)',
         'done' => column_exists('students', 'qr_code'),
         'sql' => 'ALTER TABLE students ADD COLUMN qr_code CHAR(24) NULL'],

        ['group' => 'Student QR', 'label' => 'Unique index on students.qr_code',
         'done' => index_exists('students', 'uq_students_qr_code'),
         'sql' => 'CREATE UNIQUE INDEX uq_students_qr_code ON students (qr_code)'],
    ];
}

/** "Already there" answers mean the step is satisfied, not failed. */
function already_satisfied(PDOException $ex): bool
{
    $driver = isset($ex->errorInfo[1]) ? (int) $ex->errorInfo[1] : 0;
    return in_array($driver, [1050, 1060, 1061, 1091], true)
        || (bool) preg_match('/already exists|duplicate|can\'t drop|unknown key/i', (string) $ex->getMessage());
}

$serverVersion = (string) db()->query('SELECT VERSION()')->fetchColumn();
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach (migration_steps() as $step) {
        if ($step['done']) continue;
        try {
            db()->exec($step['sql']);
            $results[] = ['ok', $step['label'], 'Applied.'];
        } catch (PDOException $ex) {
            if (already_satisfied($ex)) {
                $results[] = ['ok', $step['label'], 'Already present.'];
            } else {
                $results[] = ['err', $step['label'], $ex->getMessage()];
            }
        }
    }
    $errors = count(array_filter($results, fn($r) => $r[0] === 'err'));
    flash($errors ? "Finished with $errors problem(s) — see below."
                  : 'Database updated successfully. Remember to delete this file.', $errors ? 'error' : 'success');
    redirect('migrate.php');
}

$steps = migration_steps();
$pending = count(array_filter($steps, fn($s) => !$s['done']));
$grouped = [];
foreach ($steps as $step) $grouped[$step['group']][] = $step;

layout_start('Database migration', 'settings');
?>
<div class="page-head">
  <div><span class="eyebrow"><?= icon('settings', 14) ?> One-time setup</span><h2>Database migration</h2>
    <p class="muted">Applies every missing table, column and index through PHP — no stored procedures, so it works on InfinityFree.</p></div>
  <div class="actions">
    <span class="badge <?= $pending ? 'badge-amber' : 'badge-green' ?>"><?= $pending ? $pending . ' change(s) pending' : 'All changes applied' ?></span>
    <?php if ($pending): ?><form method="post" class="inline"><?= csrf_field() ?>
      <button class="btn btn-primary"><?= icon('save', 16) ?> Apply all changes</button></form><?php endif; ?>
  </div>
</div>

<section class="card">
  <div class="card-head"><h3><?= icon('database', 17) ?> Database</h3><span class="muted small">server: <?= e($serverVersion) ?></span></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Area</th><th>Change</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($grouped as $group => $rows): ?>
      <?php foreach ($rows as $step): ?>
      <tr>
        <td data-label="Area"><span class="badge badge-blue"><?= e($group) ?></span></td>
        <td data-label="Change"><?= e($step['label']) ?></td>
        <td data-label="Status"><?= $step['done']
            ? '<span class="badge badge-green">' . icon('check', 13) . ' Ready</span>'
            : '<span class="badge badge-amber">Pending</span>' ?></td>
      </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<?php foreach ($results as [$tone, $label, $text]): ?>
  <div class="alert alert-<?= $tone === 'ok' ? 'success' : 'error' ?>"><b><?= e($label) ?></b> — <?= e($text) ?></div>
<?php endforeach; ?>

<section class="card manual-card">
  <div class="card-head"><h3><?= icon('shield-check', 17) ?> Once everything says Ready</h3></div>
  <ul class="rfid-steps">
    <li><b>Delete <code>migrate.php</code> from your server</b> — it should not stay online.</li>
    <li>Open <b>Workspace settings</b>: academic terms are now private to your account.</li>
    <li>Open <b>RFID readers</b> and <b>My profile</b> to confirm the new fields work.</li>
  </ul>
  <p class="note"><?= icon('info', 14) ?> Existing terms are assigned to the <b>oldest professor account</b>. To give one to a different professor, set its <b>professor_id</b> in the terms table.</p>
</section>
<?php layout_end(); ?>
