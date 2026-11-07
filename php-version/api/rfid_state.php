<?php
/**
 * api/rfid_state.php – read-only JSON for the pages (reader.php, scan.php,
 * student/rfid.php). Polled by assets/rfid.js.
 *
 * GET  ?action=readers     professor: online status, last tap, taps today
 * GET  ?action=pair_status professor: has this pairing code paired yet?
 * GET  ?action=my_card     student: is my card linked?
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config_rfid_helpers.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) rfid_json(['ok' => false, 'error' => 'Sign in first.'], 401);

$action = (string) ($_GET['action'] ?? 'readers');

if ($action === 'my_card') {
    if ($user['role'] !== 'student') rfid_json(['ok' => false, 'error' => 'Student account required.'], 403);
    $roster = q('SELECT s.name, s.student_number, s.card_linked_at, sec.name AS section_name
        FROM students s LEFT JOIN sections sec ON sec.id = s.section_id
        WHERE s.user_id = ? ORDER BY s.id DESC LIMIT 1', [(int) $user['id']])->fetch();
    rfid_json([
        'ok' => true,
        'linked' => (bool) ($roster['card_linked_at'] ?? null),
        'linked_at' => $roster['card_linked_at'] ?? null,
        'student_name' => $roster['name'] ?? $user['name'],
        'student_number' => $roster['student_number'] ?? '',
        'section_name' => $roster['section_name'] ?? null,
        'has_roster' => (bool) $roster,
    ]);
}

if ($user['role'] !== 'professor') rfid_json(['ok' => false, 'error' => 'Professor account required.'], 403);

if ($action === 'pair_status') {
    $code = strtoupper(trim((string) ($_GET['code'] ?? '')));
    if (!preg_match('/^[A-Z0-9]{6}$/', $code)) rfid_json(['ok' => false, 'error' => 'Bad code.'], 422);
    $reader = q('SELECT r.id, r.name, r.section_id, r.last_seen, r.revoked_at
        FROM rfid_readers r JOIN sections s ON s.id = r.section_id
        WHERE r.pairing_code = ? AND s.professor_id = ?', [$code, professor_id()])->fetch();
    $token = rfid_pairing_row($code, 'register_reader');
    rfid_json([
        'ok' => true,
        'paired' => (bool) $reader && empty($reader['revoked_at']),
        'token_active' => (bool) $token,
        'reader' => $reader ?: null,
    ]);
}

/* action=readers */
$readers = q('SELECT r.*, s.name AS section_name
    FROM rfid_readers r JOIN sections s ON s.id = r.section_id
    WHERE s.professor_id = ? AND r.revoked_at IS NULL
    ORDER BY r.id', [professor_id()])->fetchAll();

$out = [];
foreach ($readers as $reader) {
    $today = $reader['section_id']
        ? (int) q('SELECT COUNT(*) FROM attendance a JOIN class_sessions cs ON cs.id = a.session_id
            WHERE cs.section_id = ? AND DATE(a.scanned_at) = CURDATE() AND a.method = ?', [(int) $reader['section_id'], 'rfid'])->fetchColumn()
        : 0;
    $out[] = [
        'id' => (int) $reader['id'],
        'name' => $reader['name'],
        'section_id' => $reader['section_id'] ? (int) $reader['section_id'] : null,
        'section_name' => $reader['section_name'] ?? 'Not assigned',
        'status' => rfid_reader_status($reader),
        'last_tap_student' => $reader['last_tap_student'],
        'last_tap_at' => $reader['last_tap_at'],
        'last_seen' => $reader['last_seen'],
        'taps_today' => $today,
    ];
}

rfid_json(['ok' => true, 'readers' => $out, 'server_time' => date('c')]);
