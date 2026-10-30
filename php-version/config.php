<?php
declare(strict_types=1);

/* ====== EDIT THESE FOR YOUR HOSTING ====== */
const DB_DSN  = 'mysql:host=localhost;dbname=qr_attendance;charset=utf8mb4';
const DB_USER = 'your_db_user';
const DB_PASS = 'your_db_password';
const BASE_URL = '';            // '' if installed at domain root, or '/attendance' for a subfolder
const QR_TTL_SECONDS = 60;      // lifetime of a student attendance QR
const SESSION_MINUTES = 90;     // how long a class session stays open
date_default_timezone_set('Asia/Manila');
/* ========================================= */

const REMEMBER_SECONDS = 60 * 60 * 24 * 30;
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
define('IS_HTTPS', $https);

ini_set('session.use_strict_mode', '1');
ini_set('session.gc_maxlifetime', (string) REMEMBER_SECONDS);
session_name('qr_session');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => IS_HTTPS, 'httponly' => true, 'samesite' => 'Lax']);
session_start();

// Composer autoloader (chillerlan/php-qrcode). Guarded so a missing vendor/
// folder doesn't blank the whole site — QR pages show a clear message instead.
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function e(mixed $v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function url(string $path): string { return BASE_URL . '/' . ltrim($path, '/'); }
function redirect(string $path): never { header('Location: ' . url($path)); exit; }
function now(): string { return date('Y-m-d H:i:s'); }

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Your session expired. Please go back and try again.');
    }
}

function flash(string $msg, string $type = 'success'): void { $_SESSION['flash'][] = [$type, $msg]; }

/* ============================================================================
 * LOGIN BRUTE-FORCE THROTTLING
 * Tracks failures per (email + IP). After MAX_LOGIN_ATTEMPTS failures the pair
 * is locked for LOCKOUT_MINUTES. Fails OPEN (allows the attempt) if the table
 * is missing, so a not-yet-run migration can never lock anyone out.
 * ==========================================================================*/
const MAX_LOGIN_ATTEMPTS = 6;
const LOCKOUT_MINUTES = 30;

/** Client IP. REMOTE_ADDR is used because it cannot be spoofed by the client. */
function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/** Seconds remaining on an active lockout, or 0 when not locked. */
function login_lock_remaining(string $email): int
{
    try {
        $row = q('SELECT locked_until FROM login_attempts WHERE email = ? AND ip = ?', [$email, client_ip()])->fetch();
        if (!$row || empty($row['locked_until'])) return 0;
        $remaining = (int) strtotime($row['locked_until']) - time();
        return $remaining > 0 ? $remaining : 0;
    } catch (Throwable $error) {
        return 0; // fail open
    }
}

/** Records a failed attempt; returns seconds of lockout just applied (0 if none). */
function login_record_failure(string $email): int
{
    try {
        $row = q('SELECT id, attempts, locked_until FROM login_attempts WHERE email = ? AND ip = ?', [$email, client_ip()])->fetch();
        if ($row) {
            // A failure after the previous cooldown starts a fresh six-attempt
            // cycle; otherwise stale counters would re-lock on the very next try.
            $cooldownExpired = !empty($row['locked_until']) && strtotime($row['locked_until']) <= time();
            $attempts = $cooldownExpired ? 1 : ((int) $row['attempts'] + 1);
            $lock = $attempts >= MAX_LOGIN_ATTEMPTS ? date('Y-m-d H:i:s', time() + LOCKOUT_MINUTES * 60) : null;
            q('UPDATE login_attempts SET attempts = ?, locked_until = ? WHERE id = ?', [$attempts, $lock, (int) $row['id']]);
        } else {
            $attempts = 1;
            $lock = null;
            q('INSERT INTO login_attempts (email, ip, attempts, locked_until) VALUES (?,?,?,?)', [$email, client_ip(), $attempts, $lock]);
        }
        return $attempts >= MAX_LOGIN_ATTEMPTS ? LOCKOUT_MINUTES * 60 : 0;
    } catch (Throwable $error) {
        return 0; // fail open
    }
}

/** Clears the counter after a successful sign-in. */
function login_reset_failures(string $email): void
{
    try {
        q('DELETE FROM login_attempts WHERE email = ? AND ip = ?', [$email, client_ip()]);
    } catch (Throwable $error) {
        // nothing to clear
    }
}

/** Optional `users` columns the app reads. Older databases may not have them. */
function user_optional_columns(): array
{
    return ['student_number', 'avatar', 'teacher_level', 'student_level', 'primary_attendance_mode', 'first_name', 'middle_name', 'last_name'];
}

/** Columns actually present on `users` (cached once per request). */
function user_columns(): array
{
    static $columns = null;
    if ($columns !== null) return $columns;
    try {
        $rows = q("SELECT COLUMN_NAME AS column_name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'")->fetchAll();
        $columns = array_map(static fn(array $row): string => (string) $row['column_name'], $rows);
    } catch (Throwable $error) {
        $columns = ['id', 'name', 'email', 'role', 'student_number'];
    }
    if (!$columns) $columns = ['id', 'name', 'email', 'role', 'student_number'];
    return $columns;
}

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) return $user;
    $user = null;
    if (!empty($_SESSION['uid'])) {
        $available = user_columns();
        $select = ['id', 'name', 'email', 'role'];
        foreach (user_optional_columns() as $column) {
            if (in_array($column, $available, true)) $select[] = $column;
        }
        $user = q('SELECT ' . implode(', ', $select) . ' FROM users WHERE id = ?', [$_SESSION['uid']])->fetch() ?: null;
    }
    return $user;
}

function require_role(string $role): array
{
    $u = current_user();
    if (!$u) redirect('login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
    if ($u['role'] !== $role) redirect($u['role'] === 'professor' ? 'dashboard.php' : 'student/index.php');
    return $u;
}

function current_term(): ?array
{
    // Storage is per professor: a professor only ever sees their own terms.
    if (($u = current_user()) && $u['role'] === 'professor') {
        $term = q('SELECT * FROM terms WHERE professor_id = ? ORDER BY is_current DESC, id DESC LIMIT 1', [$u['id']])->fetch();
    } else {
        $term = q('SELECT * FROM terms ORDER BY is_current DESC, id DESC LIMIT 1')->fetch();
    }
    return $term ?: null;
}

/** id of the signed-in professor (0 for students / visitors) */
function professor_id(): int
{
    $u = current_user();
    return ($u && $u['role'] === 'professor') ? (int) $u['id'] : 0;
}

/** Does this academic term belong to the signed-in professor? */
function owns_term(int $termId): bool
{
    return professor_id() > 0 && (bool) q(
        'SELECT 1 FROM terms WHERE id = ? AND professor_id = ?', [$termId, professor_id()]
    )->fetchColumn();
}

/** Does this subject belong to the signed-in professor? */
function owns_subject(int $subjectId): bool
{
    $pid = professor_id();
    return $pid > 0 && (bool) q(
        'SELECT 1 FROM subjects sub JOIN terms t ON t.id = sub.term_id WHERE sub.id = ? AND t.professor_id = ?',
        [$subjectId, $pid]
    )->fetchColumn();
}

/** Does this section belong to the signed-in professor? */
function owns_section(int $sectionId): bool
{
    $pid = professor_id();
    return $pid > 0 && (bool) q(
        'SELECT 1 FROM sections s JOIN terms t ON t.id = s.term_id
         WHERE s.id = ? AND COALESCE(s.professor_id, t.professor_id) = ?', [$sectionId, $pid]
    )->fetchColumn();
}

/** Does this attendance session belong to the signed-in professor? */
function owns_session(string $sessionId): bool
{
    $pid = professor_id();
    if ($pid === 0 || !preg_match('/^[1-9][0-9]*$/', $sessionId)) return false;
    return (bool) q(
        'SELECT 1 FROM class_sessions cs
         JOIN sections s ON s.id = cs.section_id
         JOIN terms t ON t.id = s.term_id
         WHERE cs.id = ? AND COALESCE(s.professor_id, t.professor_id) = ?', [$sessionId, $pid]
    )->fetchColumn();
}

function close_expired_sessions(): void
{
    q("UPDATE class_sessions SET status='closed' WHERE status='open' AND expires_at <= ?", [now()]);
}

function qr_data_uri(string $data): string
{
    // chillerlan/php-qrcode v6 – default output is an SVG data URI (no GD extension required)
    if (!class_exists('\chillerlan\QRCode\QRCode')) {
        throw new RuntimeException('QR generation library is missing. Run "composer install" in the project folder and upload the vendor/ directory.');
    }
    $options = new \chillerlan\QRCode\QROptions(['scale' => 8, 'outputBase64' => true, 'addQuietzone' => true]);
    return (new \chillerlan\QRCode\QRCode($options))->render($data);
}

/**
 * Lucide icon – the same icon set and version (1.50.0) as the Next.js app's lucide-react.
 * Outputs a placeholder that lucide.createIcons() (assets/app.js) turns into an inline SVG.
 */
function icon(string $name, int $size = 18, string $class = ''): string
{
    return '<i data-lucide="' . e($name) . '" class="icon ' . e($class) . '" style="width:' . $size . 'px;height:' . $size . 'px" aria-hidden="true"></i>';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    return strtoupper(implode('', array_map(fn($p) => mb_substr($p, 0, 1), array_slice($parts, 0, 2))));
}

/** First name for greetings — falls back to the first word of the full name. */
function first_name(array $user): string
{
    $first = trim((string) ($user['first_name'] ?? ''));
    if ($first !== '') return $first;
    $parts = preg_split('/\s+/', trim((string) ($user['name'] ?? ''))) ?: [];
    return $parts[0] !== '' ? (string) $parts[0] : 'there';
}

const DAYS = ['Mon' => 'Monday', 'Tue' => 'Tuesday', 'Wed' => 'Wednesday', 'Thu' => 'Thursday', 'Fri' => 'Friday', 'Sat' => 'Saturday', 'Sun' => 'Sunday'];

function time_label(string $time): string { return date('g:i A', strtotime($time)); }
function day_label(string $day): string { return DAYS[$day] ?? $day; }

/** Meeting times of one section, e.g. Mon 9:00–10:30 AM · Room 204 */
function schedule_rows(int $sectionId): array
{
    return q('SELECT * FROM section_schedules WHERE section_id = ? ORDER BY FIELD(day,"Mon","Tue","Wed","Thu","Fri","Sat","Sun"), start_time', [$sectionId])->fetchAll();
}

/** [section_id => [[day, start, end, room], ...]] for a set of sections */
function schedules_by_section(array $sectionIds): array
{
    if (!$sectionIds) return [];
    $in = implode(',', array_fill(0, count($sectionIds), '?'));
    $rows = q("SELECT * FROM section_schedules WHERE section_id IN ($in) ORDER BY FIELD(day,'Mon','Tue','Wed','Thu','Fri','Sat','Sun'), start_time", $sectionIds)->fetchAll();
    $out = [];
    foreach ($rows as $r) $out[$r['section_id']][] = $r;
    return $out;
}

function schedule_text(array $rows): string
{
    if (!$rows) return 'No schedule set';
    return implode(' · ', array_map(
        fn($r) => day_label($r['day']) . ' ' . time_label($r['start_time']) . '–' . time_label($r['end_time']) . ($r['room'] ? ' · ' . $r['room'] : ''),
        $rows
    ));
}

function avatar_url(?string $file): ?string
{
    return $file && is_file(__DIR__ . '/uploads/avatars/' . $file) ? url('uploads/avatars/' . $file) : null;
}

/** Circular avatar: uploaded picture, or the initials fallback used in the Next.js app */
function avatar(array $u, string $size = 'md'): string
{
    $src = avatar_url($u['avatar'] ?? null);
    if ($src) return '<img class="avatar ' . e($size) . '" src="' . e($src) . '" alt="">';
    return '<span class="avatar ' . e($size) . '">' . e(initials($u['name'] ?? '?')) . '</span>';
}

/** Reads the sched_day[] / sched_start[] / sched_end[] / sched_room[] rows and validates them. */
function parse_schedules(array $post): array
{
    $rows = [];
    foreach (($post['sched_day'] ?? []) as $i => $day) {
        $day = (string) $day;
        $start = trim((string) ($post['sched_start'][$i] ?? ''));
        $end = trim((string) ($post['sched_end'][$i] ?? ''));
        if (!isset(DAYS[$day]) || !$start || !$end) continue;
        if (strtotime($end) <= strtotime($start)) {
            throw new RuntimeException('The end time must be after the start time (' . day_label($day) . ').');
        }
        $rows[] = ['day' => $day, 'start_time' => $start, 'end_time' => $end, 'room' => trim((string) ($post['sched_room'][$i] ?? ''))];
    }
    // a section meets only once per day
    $seen = [];
    foreach ($rows as $r) {
        if (isset($seen[$r['day']])) {
            throw new RuntimeException('This section already has a class on ' . day_label($r['day']) . '. Remove the extra row.');
        }
        $seen[$r['day']] = true;
    }
    return $rows;
}

/**
 * Returns an error message when the rows clash with another section's schedule.
 *
 * Allowed: the same subject may meet on the same day in DIFFERENT sections
 * (Section A 09:00, Section B 14:00) — the professor simply teaches both.
 * Not allowed: a meeting that OVERLAPS in time with any other section of the
 * professor in that term, because one person cannot teach two classes at once.
 *
 * $subjectId is accepted for caller compatibility; it is no longer used to
 * block a same-subject day.
 */
function schedule_conflict(?int $subjectId, int $excludeSectionId, array $rows, ?int $termIdOverride = null): ?string
{
    if (!$rows) return null;

    // Direct sections pass their term explicitly; subject sections inherit it.
    $termId = $termIdOverride;
    if (!$termId && $subjectId) $termId = q('SELECT term_id FROM subjects WHERE id = ?', [$subjectId])->fetchColumn() ?: null;
    if (!$termId && $excludeSectionId) $termId = q('SELECT term_id FROM sections WHERE id = ?', [$excludeSectionId])->fetchColumn() ?: null;
    if (!$termId) return null;

    $days = array_values(array_unique(array_column($rows, 'day')));
    $in = implode(',', array_fill(0, count($days), '?'));
    $taken = q("SELECT s.id AS section_id, s.name AS section_name, s.subject_id,
            COALESCE(sub.code, 'SECTION') AS code, COALESCE(sub.name, 'Section attendance') AS subject_name,
            ss.day, ss.start_time, ss.end_time
        FROM section_schedules ss JOIN sections s ON s.id = ss.section_id
        LEFT JOIN subjects sub ON sub.id = s.subject_id
        WHERE s.term_id = ? AND ss.section_id <> ? AND ss.day IN ($in)
        ORDER BY FIELD(ss.day,'Mon','Tue','Wed','Thu','Fri','Sat','Sun'), ss.start_time",
        array_merge([(int) $termId, $excludeSectionId], $days))->fetchAll();
    if (!$taken) return null;

    $toMinutes = static function (string $t): int {
        [$h, $m] = array_map('intval', explode(':', $t) + [0, 0]);
        return $h * 60 + $m;
    };

    foreach ($rows as $r) {
        $newStart = $toMinutes((string) $r['start_time']);
        $newEnd = $toMinutes((string) $r['end_time']);
        foreach ($taken as $t) {
            if ($t['day'] !== $r['day']) continue;
            // Same subject on the same day in another section is fine here;
            // only a real time overlap is a conflict.
            $oldStart = $toMinutes((string) $t['start_time']);
            $oldEnd = $toMinutes((string) $t['end_time']);
            if ($newStart < $oldEnd && $oldStart < $newEnd) {
                return 'Schedule conflict: ' . $t['code'] . ' · ' . $t['section_name'] . ' meets '
                     . day_label($r['day']) . ' ' . time_label($t['start_time']) . ' – ' . time_label($t['end_time'])
                     . ', which overlaps with your ' . time_label($r['start_time']) . ' – ' . time_label($r['end_time'])
                     . '. Choose a different day or time.';
            }
        }
    }
    return null;
}

/**
 * Saves the time in / time out slots of a section (morning and afternoon).
 * Blank fields are stored as NULL, so a half-day section is simply left empty.
 */
function save_attendance_times(int $sectionId, array $post): void
{
    $slots = [];
    foreach (['am_in', 'am_out', 'pm_in', 'pm_out'] as $slot) {
        $value = trim((string) ($post[$slot] ?? ''));
        $slots[$slot] = ($value !== '' && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) ? $value : null;
    }
    q('INSERT INTO section_attendance_times (section_id, am_in, am_out, pm_in, pm_out) VALUES (?,?,?,?,?)
       ON DUPLICATE KEY UPDATE am_in = VALUES(am_in), am_out = VALUES(am_out), pm_in = VALUES(pm_in), pm_out = VALUES(pm_out)',
       [$sectionId, $slots['am_in'], $slots['am_out'], $slots['pm_in'], $slots['pm_out']]);
}

/** Replaces all meeting times of a section (validates the rows first). */
function save_schedules(int $sectionId, array $post): int
{
    $rows = parse_schedules($post);
    $info = q('SELECT subject_id, term_id, section_mode FROM sections WHERE id = ?', [$sectionId])->fetch();
    if ($info && $info['section_mode'] !== 'direct') {
        if ($message = schedule_conflict((int) $info['subject_id'], $sectionId, $rows, (int) $info['term_id'])) {
            throw new RuntimeException($message);
        }
    }
    q('DELETE FROM section_schedules WHERE section_id = ?', [$sectionId]);
    foreach ($rows as $r) {
        q('INSERT INTO section_schedules (section_id, day, start_time, end_time, room) VALUES (?,?,?,?,?)',
          [$sectionId, $r['day'], $r['start_time'], $r['end_time'], $r['room']]);
    }
    return count($rows);
}

/** Saves an uploaded picture; returns the stored filename (throws RuntimeException on bad files). */
function save_avatar_upload(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('The upload failed. Please try again.');
    if ($file['size'] > 2 * 1024 * 1024) throw new RuntimeException('The picture must be smaller than 2 MB.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($map[$mime])) throw new RuntimeException('Use a JPG, PNG, WebP or GIF picture.');
    $name = 'u' . (int) ($_SESSION['uid'] ?? 0) . '-' . bin2hex(random_bytes(6)) . '.' . $map[$mime];
    if (!is_dir(__DIR__ . '/uploads/avatars')) mkdir(__DIR__ . '/uploads/avatars', 0755, true);
    if (!move_uploaded_file($file['tmp_name'], __DIR__ . '/uploads/avatars/' . $name)) throw new RuntimeException('The picture could not be saved.');
    return $name;
}

/** Academic-term dropdown used in the top bar and on the Classes page (same as the Next.js app). */
function term_selector(string $class = 'term-control'): string
{
    $pid = professor_id();
    if ($pid === 0) return '';                      // only professors switch terms
    $terms = q('SELECT * FROM terms WHERE professor_id = ? ORDER BY is_current DESC, id DESC', [$pid])->fetchAll();
    if (!$terms) return '';
    $current = current_term();
    $return = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $html = '<form class="' . e($class) . '" method="post" action="' . e(url('term.php')) . '">' . csrf_field();
    $html .= '<input type="hidden" name="return" value="' . e($return) . '">';
    $html .= icon('calendar-range', 15);
    $html .= '<select name="term_id" onchange="this.form.submit()" aria-label="Select academic term">';
    foreach ($terms as $t) {
        $selected = $current && (int) $t['id'] === (int) $current['id'];
        $html .= '<option value="' . (int) $t['id'] . '"' . ($selected ? ' selected' : '') . '>' . e($t['school_year'] . ' · ' . $t['semester']) . '</option>';
    }
    $html .= '</select>' . icon('chevron-down', 14) . '</form>';
    return $html;
}

/** Number of live attendance sessions shown on the notification bell */
function open_session_count(array $u): int
{
    if ($u['role'] === 'professor') {
        return (int) q("SELECT COUNT(*) FROM class_sessions WHERE status = 'open'")->fetchColumn();
    }
    return (int) q("SELECT COUNT(*) FROM class_sessions cs JOIN students st ON st.section_id = cs.section_id
        WHERE cs.status = 'open' AND cs.expires_at > ? AND st.user_id = ?", [now(), $u['id']])->fetchColumn();
}

/* ---------- Layout ---------- */
function layout_start(string $title, string $active = ''): void
{
    $u = current_user();
    $nav = $u && $u['role'] === 'professor'
        ? [
            ['dashboard.php', 'Overview', 'dashboard', 'layout-dashboard'],
            ['classes.php', 'Classes & sections', 'classes', 'book-open'],
            ['students.php', 'Students roster', 'students', 'users'],
            ['live.php', 'Live attendance', 'live', 'qr-code'],
            ['reader.php', 'RFID readers', 'reader', 'nfc'],
            ['history.php', 'Attendance history', 'history', 'history'],
          ];
    $manageNav = $u && $u['role'] === 'professor'
        ? [['settings.php', 'Workspace settings', 'settings', 'settings']]
        : [
            ['student/index.php', 'My overview', 'home', 'layout-dashboard'],
            ['student/index.php#classes', 'My classes', 'classes', 'book-open'],
            ['student/index.php#history', 'My attendance', 'att', 'clipboard-check'],
            ['student/rfid.php', 'Link school ID', 'rfid', 'nfc'],
          ];
    $term = current_term();
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · QRAttendance</title>
<link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>?v=<?= (int) (@filemtime(__DIR__ . '/assets/style.css') ?: 1) ?>">
<script src="https://unpkg.com/lucide@1.50.0/dist/umd/lucide.min.js"></script>
</head>
<body>
<div class="app">
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="<?= e(url($u && $u['role'] === 'professor' ? 'dashboard.php' : 'student/index.php')) ?>"><span class="brand-mark"><?= icon('book-open-check', 21) ?></span><span>qr<span class="brand-light">attendance</span></span></a>
    <nav class="nav">
      <span class="nav-label">Menu</span>
      <?php foreach ($nav as [$href, $label, $key, $icon]): ?>
        <a class="nav-link <?= $active === $key ? 'active' : '' ?>" href="<?= e(url($href)) ?>"><?= icon($icon, 18) ?><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <?php if (!empty($manageNav)): ?>
      <div class="sidebar-divider"></div>
      <span class="nav-label">Manage</span>
      <nav class="nav">
        <?php foreach ($manageNav as [$href, $label, $key, $icon]): ?>
          <a class="nav-link <?= $active === $key ? 'active' : '' ?>" href="<?= e(url($href)) ?>"><?= icon($icon, 18) ?><?= e($label) ?></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>
    <?php if ($term): ?>
      <div class="term-card"><small><?= icon('calendar-range', 15) ?> Active term</small><b><?= e($term['school_year']) ?></b><span><?= e($term['semester']) ?></span>
        <?php if ($u['role'] === 'professor'): ?><a class="term-manage" href="<?= e(url('settings.php')) ?>">Manage terms →</a><?php endif; ?></div>
    <?php endif; ?>
    <div class="side-user">
      <?= avatar($u, 'md') ?>
      <span class="side-user-text"><b><?= e($u['name'] ?? '') ?></b><small><?= e(ucfirst($u['role'] ?? '')) ?></small></span>
      <a class="side-logout" href="<?= e(url($u['role'] === 'professor' ? 'profile.php' : 'student/profile.php')) ?>" title="My profile"><?= icon('user-round', 17) ?></a>
      <a class="side-logout" href="<?= e(url('logout.php')) ?>" title="Sign out"><?= icon('log-out', 17) ?></a>
    </div>
  </aside>
  <div class="overlay" id="overlay"></div>
  <div class="main">
    <header class="topbar">
      <div class="topbar-left">
        <button class="menu-btn" id="menuBtn" aria-label="Open menu"><?= icon('menu', 21) ?></button>
        <div class="breadcrumb">
          <span><?= $u['role'] === 'professor' ? 'Professor workspace' : 'Student portal' ?></span>
          <?= icon('chevron-right', 14) ?><b><?= e($title) ?></b>
        </div>
      </div>
      <div class="topbar-right">
        <form class="global-search" method="get" action="<?= e(url($u['role'] === 'professor' ? 'students.php' : 'student/index.php')) ?>">
          <?= icon('search', 16) ?>
          <input name="q" aria-label="Search your workspace" placeholder="Search anything…" value="<?= e($_GET['q'] ?? '') ?>">
          <kbd>⌘ K</kbd>
        </form>
        <span class="topbar-divider"></span>
        <?php $openCount = open_session_count($u); ?>
        <div class="dropdown-wrap">
          <button class="icon-btn notification-button" data-dropdown="notif" aria-label="Notifications"><?= icon('bell', 18) ?><?= $openCount ? '<i></i>' : '' ?></button>
          <div class="dropdown notification-popover" data-dropdown-panel="notif">
            <div class="notification-header"><b>Notifications</b><span class="notification-new"><?= $openCount ? $openCount . ' new' : 'All caught up' ?></span></div>
            <?php if ($openCount): ?>
              <a class="notification-item" href="<?= e(url($u['role'] === 'professor' ? 'live.php' : 'student/index.php')) ?>">
                <span class="notification-icon"><?= icon('qr-code', 16) ?></span>
                <span><b>Attendance is live</b><small><?= $openCount ?> open session<?= $openCount === 1 ? '' : 's' ?> right now</small></span>
                <?= icon('chevron-right', 15) ?>
              </a>
            <?php else: ?>
              <div class="notification-empty"><?= icon('circle-check', 20) ?><span>You’re all caught up. We’ll let you know when something needs your attention.</span></div>
            <?php endif; ?>
          </div>
        </div>
        <div class="dropdown-wrap">
          <button class="topbar-user" data-dropdown="user">
            <?= avatar($u, 'sm') ?>
            <span><b><?= e($u['name'] ?? '') ?></b><small><?= e(ucfirst($u['role'] ?? '')) ?></small></span>
            <?= icon('chevron-down', 14) ?>
          </button>
          <div class="dropdown topbar-profile-menu" data-dropdown-panel="user">
            <b><?= e($u['email'] ?? '') ?></b>
            <a href="<?= e(url($u['role'] === 'professor' ? 'profile.php' : 'student/profile.php')) ?>"><?= icon('user-round', 15) ?> My profile</a>
            <?php if ($u['role'] === 'professor'): ?><a href="<?= e(url('settings.php')) ?>"><?= icon('settings', 15) ?> Workspace settings</a><?php endif; ?>
            <a href="<?= e(url('logout.php')) ?>"><?= icon('log-out', 15) ?> Sign out</a>
          </div>
        </div>
      </div>
    </header>
    <main class="content">
    <?php foreach ($_SESSION['flash'] ?? [] as [$type, $msg]): ?>
      <div class="alert alert-<?= e($type) ?>"><?= e($msg) ?></div>
    <?php endforeach; unset($_SESSION['flash']);
}

function layout_end(string $extraScripts = ''): void
{
    ?>
    </main>
  </div>
</div>
<script src="<?= e(url('assets/app.js')) ?>"></script>
<?= $extraScripts ?>
</body>
</html>
<?php
}
