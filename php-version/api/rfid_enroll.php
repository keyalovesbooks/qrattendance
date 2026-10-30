<?php
/**
 * api/rfid_enroll.php – links a school ID card to a student's roster row.
 *
 * Two callers:
 *  a) the reader  : POST {pairing_code, uid} with the ESP32 User-Agent
 *  b) the browser : student/rfid.php buttons, CSRF required (header X-CSRF-Token
 *                   or the hidden csrf field), actions "generate" and "unlink"
 *
 * Only uid_hash (SHA-256) is stored, and a card can belong to one student only.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config_rfid_helpers.php';

$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

/* ---------- (a) called by the reader after the student taps ---------- */
if ($ua === RFID_READER_UA) {
    $body = require_reader_request();
    $code = strtoupper(trim((string) ($body['pairing_code'] ?? '')));
    $uid = strtoupper(trim((string) ($body['uid'] ?? '')));
    if (!preg_match('/^[A-F0-9]{4,24}$/', $uid)) rfid_json(['ok' => false, 'error' => 'Invalid card UID.'], 422);

    $token = rfid_pairing_row($code, 'enroll_card');
    if (!$token) rfid_json(['ok' => false, 'error' => 'Pairing code expired or already used.'], 410);

    $uidHash = rfid_hash($uid);
    $owner = q('SELECT id, name, user_id FROM students WHERE uid_hash = ?', [$uidHash])->fetch();
    if ($owner && (int) $owner['user_id'] !== (int) $token['user_id']) {
        rfid_json(['ok' => false, 'error' => 'This card is already linked to another student.'], 409);
    }

    $roster = q('SELECT * FROM students WHERE user_id = ? ORDER BY id DESC LIMIT 1', [(int) $token['user_id']])->fetch();
    if (!$roster) rfid_json(['ok' => false, 'error' => 'Join a class before linking a card.'], 404);

    q('UPDATE students SET uid_hash = ?, card_linked_at = ? WHERE id = ?', [$uidHash, now(), $roster['id']]);
    q('UPDATE pairing_tokens SET used_at = ? WHERE id = ?', [now(), $token['id']]);

    rfid_json([
        'ok' => true, 'student_name' => $roster['name'],
        'student_number' => $roster['student_number'],
        'message' => 'Card linked. You can now tap to check in.',
    ]);
}

/* ---------- (b) called by student/rfid.php ---------- */
$user = current_user();
if (!$user || $user['role'] !== 'student') rfid_json(['ok' => false, 'error' => 'Sign in as a student first.'], 401);

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) $body = $_POST;
$csrf = (string) ($body['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!hash_equals(csrf_token(), $csrf)) rfid_json(['ok' => false, 'error' => 'Your session expired. Please refresh.'], 419);

$action = (string) ($body['action'] ?? '');

if ($action === 'unlink') {
    q('UPDATE students SET uid_hash = NULL, card_linked_at = NULL WHERE user_id = ?', [$user['id']]);
    rfid_json(['ok' => true, 'linked' => false, 'message' => 'Card unlinked.']);
}

if ($action === 'generate') {
    $roster = q('SELECT * FROM students WHERE user_id = ? ORDER BY id DESC LIMIT 1', [(int) $user['id']])->fetch();
    if (!$roster) rfid_json(['ok' => false, 'error' => 'Join a class first — you need a roster entry before linking a card.'], 404);
    q('UPDATE pairing_tokens SET used_at = ? WHERE user_id = ? AND purpose = ? AND used_at IS NULL',
      [now(), $user['id'], 'enroll_card']);
    $code = rfid_create_pairing_code((int) $user['id'], 'enroll_card');
    $expiresAt = q('SELECT expires_at FROM pairing_tokens WHERE pairing_code = ? AND user_id = ? ORDER BY id DESC LIMIT 1',
                   [$code, (int) $user['id']])->fetchColumn();
    rfid_json(['ok' => true, 'pairing_code' => $code, 'expires_at' => (string) $expiresAt, 'student_name' => $roster['name']]);
}

rfid_json(['ok' => false, 'error' => 'Unknown action.'], 400);
