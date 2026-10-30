<?php
/**
 * api/rfid_checkin.php – reader posts a card UID, the server records attendance.
 * POST + JSON, authenticated by device_token (no CSRF on reader endpoints).
 * Part of the RFID card check-in feature; see schema_rfid.sql.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config_rfid_helpers.php';

$body = require_reader_request();                                  // enforces POST + reader User-Agent
$reader = reader_authenticate(is_string($body['device_token'] ?? null) ? $body['device_token'] : null);
if (!$reader) rfid_json(['ok' => false, 'status' => 'error', 'error' => 'Device token rejected.'], 401);

$uid = strtoupper(trim((string) ($body['uid'] ?? '')));
if (!preg_match('/^[A-F0-9]{4,24}$/', $uid)) {
    rfid_json(['ok' => false, 'status' => 'error', 'error' => 'Invalid card UID.'], 422);
}

$result = record_rfid_attendance((int) $reader['id'], $uid);

if ($result['ok']) {
    rfid_json($result, 200);
}
$status = $result['status'] ?? 'error';
http_response_code($status === 'not_enrolled' || $status === 'session_closed' || $status === 'wrong_section' ? 404 : 409);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($result);
