<?php
/**
 * api/rfid_pair.php – the reader polls this every 2 seconds while it is in
 * pair mode with a 6-character code typed/shown on the screen.
 *
 * purpose=register_reader → creates the reader and returns its device_token
 * purpose=enroll_card     → confirms a card-linking session, so the reader
 *                           shows "Tap your ID" and posts to rfid_enroll.php
 * POST + JSON, reader User-Agent required.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config_rfid_helpers.php';

$body = require_reader_request();
$code = strtoupper(trim((string) ($body['pairing_code'] ?? '')));
$deviceUid = trim((string) ($body['device_uid'] ?? ''));

$token = rfid_pairing_row($code, 'register_reader');
if ($token) {
    $secret = bin2hex(random_bytes(32));                 // 32 bytes → 64 hex chars
    $sectionId = $token['section_id'] ? (int) $token['section_id'] : null;
    $name = $token['reader_name'] ?: 'Reader ' . (((int) q('SELECT COUNT(*) FROM rfid_readers')->fetchColumn()) + 1);

    q('INSERT INTO rfid_readers (name, section_id, device_uid, token_hash, pairing_code, last_seen, created_at)
       VALUES (?,?,?,?,?,?,?)',
      [$name, $sectionId, $deviceUid ?: null, hash('sha256', $secret), $code, now(), now()]);
    q('UPDATE pairing_tokens SET used_at = ? WHERE id = ?', [now(), $token['id']]);

    rfid_json([
        'ok' => true, 'reader_id' => (int) db()->lastInsertId(),
        'reader_name' => $name, 'device_token' => $secret,
        'section_id' => $sectionId,
    ]);
}

$enroll = rfid_pairing_row($code, 'enroll_card');
if ($enroll) {
    // read the linked student's name so the OLED can ask for the tap
    $student = q('SELECT name, student_number FROM students WHERE user_id = ? ORDER BY id DESC LIMIT 1', [(int) $enroll['user_id']])->fetch();
    rfid_json([
        'ok' => true, 'kind' => 'enroll_card', 'purpose' => 'enroll_card',
        'student_name' => $student['name'] ?? 'Student',
        'student_number' => $student['student_number'] ?? '',
        'expires_at' => $enroll['expires_at'],
    ]);
}

rfid_json(['ok' => false, 'error' => 'No active pairing code. Codes last 5 minutes and work once.'], 410);
