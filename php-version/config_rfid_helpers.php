<?php
/**
 * config_rfid_helpers.php – RFID card check-in helpers.
 *
 * Drop-in file. New RFID endpoints and pages require it explicitly:
 *     require_once __DIR__ . '/../config.php';            // existing helpers
 *     require_once __DIR__ . '/../config_rfid_helpers.php'; // this file
 * Nothing here is added to config.php, by design.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/attendance_modes.php';

const RFID_READER_UA = 'QRAttendance-ESP32/1.0';
const RFID_RATE_LIMIT = 30;            // taps allowed per rolling minute, per reader
const RFID_LATE_GRACE_SECONDS = 600;   // 10 minutes after the session started = late

/** SHA-256 of the card UID – raw UIDs are never stored. */
function rfid_hash(string $uid): string
{
    return hash('sha256', strtoupper(trim($uid)));
}

/** Sends a JSON response and stops. */
function rfid_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/**
 * Reader endpoints are POST-only and must be sent by the ESP32 firmware.
 * CSRF is not used here – these requests authenticate with device_token.
 */
function require_reader_request(): array
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $ua !== RFID_READER_UA) {
        rfid_json(['ok' => false, 'error' => 'Reader endpoint requires a POST from the device.'], 405);
    }
    $raw = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true);          // firmware sends JSON
    if (!is_array($body)) {
        $body = [];
        parse_str((string) $raw, $body);      // fall back to form encoding
    }
    if (!$body) $body = $_POST;
    return is_array($body) ? $body : [];
}

/** Constant-time device token check. Returns the reader row, or null. */
function reader_authenticate(?string $token): ?array
{
    if (!is_string($token) || strlen($token) !== 64 || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $tokenHash = hash('sha256', $token);
    $reader = q('SELECT * FROM rfid_readers WHERE token_hash = ?', [$tokenHash])->fetch();
    if (!$reader) return null;
    if (!hash_equals((string) $reader['token_hash'], $tokenHash)) return null;   // constant-time
    if (!empty($reader['revoked_at'])) return null;
    return $reader;
}

/** Newest session that is open right now for the section (expired ones are closed first). */
function open_session_for_section(int $sectionId): ?array
{
    close_expired_sessions();
    sync_direct_attendance_sessions($sectionId);
    $session = q('SELECT * FROM class_sessions
        WHERE section_id = ? AND status = ? AND expires_at > ?
        ORDER BY started_at DESC LIMIT 1', [$sectionId, 'open', now()])->fetch();
    return $session ?: null;
}

/** Rolling 30 taps/minute per reader – the counter lives in the database. */
function rfid_rate_allow(int $readerId): bool
{
    $reader = q('SELECT rate_count, rate_window_start FROM rfid_readers WHERE id = ?', [$readerId])->fetch();
    if (!$reader) return false;
    $start = $reader['rate_window_start'] ? (int) strtotime($reader['rate_window_start']) : 0;
    if (!$start || ($start + 60) <= time()) {
        q('UPDATE rfid_readers SET rate_count = 1, rate_window_start = ? WHERE id = ?', [now(), $readerId]);
        return true;
    }
    if ((int) $reader['rate_count'] >= RFID_RATE_LIMIT) return false;
    q('UPDATE rfid_readers SET rate_count = rate_count + 1 WHERE id = ?', [$readerId]);
    return true;
}

/** "online" when the reader has phoned home in the last 30 seconds. */
function rfid_reader_status(?array $reader): string
{
    if (!$reader || !empty($reader['revoked_at']) || empty($reader['last_seen'])) return 'offline';
    return (time() - (int) strtotime($reader['last_seen'])) <= 30 ? 'online' : 'offline';
}

/** A short-lived 6-character pairing code: 5 minutes, single use. */
function rfid_create_pairing_code(int $userId, string $purpose, ?int $sectionId = null, ?string $readerName = null): string
{
    q('DELETE FROM pairing_tokens WHERE expires_at < ?', [now()]);          // housekeeping
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';                         // no 0/O, 1/I
    $code = '';
    for ($i = 0; $i < 6; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    $token = bin2hex(random_bytes(32));                                     // 32 random bytes
    q('INSERT INTO pairing_tokens (token_hash, user_id, purpose, section_id, reader_name, pairing_code, expires_at, created_at)
       VALUES (?,?,?,?,?,?,?,?)', [
        hash('sha256', $token), $userId, $purpose, $sectionId, $readerName, $code,
        date('Y-m-d H:i:s', time() + 300), now(),
    ]);
    return $code;
}

function rfid_pairing_row(string $code, string $purpose): ?array
{
    $code = strtoupper(trim($code));
    if (!preg_match('/^[A-Z0-9]{6}$/', $code)) return null;
    $row = q('SELECT * FROM pairing_tokens
        WHERE pairing_code = ? AND purpose = ? AND used_at IS NULL AND expires_at > ?
        ORDER BY id DESC LIMIT 1', [$code, $purpose, now()])->fetch();
    return $row ?: null;
}

/**
 * Records a tap for a reader.
 * Returns the JSON payload described in the spec: ok, student_name,
 * student_number, status (present|duplicate|late), session_title, section_name, time.
 */
function record_rfid_attendance(int $readerId, string $uid): array
{
    $reader = reader_authenticate_by_id($readerId);
    if (!$reader) return ['ok' => false, 'status' => 'error', 'error' => 'Reader not authorized.'];

    q('UPDATE rfid_readers SET last_seen = ? WHERE id = ?', [now(), $readerId]);
    if (!rfid_rate_allow($readerId)) {
        return ['ok' => false, 'status' => 'rate_limited', 'error' => 'Too many taps from this reader.'];
    }
    if (empty($reader['section_id'])) {
        return ['ok' => false, 'status' => 'error', 'error' => 'Reader is not assigned to a section.'];
    }

    $student = q('SELECT * FROM students WHERE uid_hash = ?', [rfid_hash($uid)])->fetch();
    if (!$student) return ['ok' => false, 'status' => 'not_enrolled', 'error' => 'Card not linked to a student.'];
    if ((int) $student['section_id'] !== (int) $reader['section_id']) {
        return ['ok' => false, 'status' => 'wrong_section', 'error' => 'Student is not enrolled in this section.'];
    }

    $session = open_session_for_section((int) $reader['section_id']);
    if (!$session) return ['ok' => false, 'status' => 'session_closed', 'error' => 'No session is open for this section.'];

    $stmt = db()->prepare('INSERT IGNORE INTO attendance (session_id, student_id, scanned_at, method) VALUES (?,?,?,?)');
    $stmt->execute([$session['id'], $student['id'], now(), 'rfid']);
    $already = $stmt->rowCount() === 0;

    $time = date('g:i A');
    q('UPDATE rfid_readers SET last_tap_student = ?, last_tap_at = ? WHERE id = ?', [
        $student['name'], now(), $readerId,
    ]);

    $section = q('SELECT name FROM sections WHERE id = ?', [(int) $reader['section_id']])->fetch();

    if ($already) {
        return [
            'ok' => false, 'status' => 'duplicate',
            'student_name' => $student['name'], 'student_number' => $student['student_number'],
            'session_title' => $session['title'], 'section_name' => $section['name'] ?? '',
            'time' => $time, 'error' => 'Already recorded.',
        ];
    }

    $late = ((int) strtotime($session['started_at']) + RFID_LATE_GRACE_SECONDS) < time();
    return [
        'ok' => true, 'status' => $late ? 'late' : 'present',
        'student_name' => $student['name'], 'student_number' => $student['student_number'],
        'session_title' => $session['title'], 'section_name' => $section['name'] ?? '', 'time' => $time,
    ];
}

/** Reader row lookup used by record_rfid_attendance (honours revoked_at). */
function reader_authenticate_by_id(int $readerId): ?array
{
    $reader = q('SELECT * FROM rfid_readers WHERE id = ?', [$readerId])->fetch();
    if (!$reader || !empty($reader['revoked_at'])) return null;
    return $reader;
}
