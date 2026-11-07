<?php
/**
 * api/rfid_heartbeat.php – the reader phones home every 20 seconds so the
 * professor can see online/offline status in reader.php and on scan.php.
 * POST + JSON, authenticated by device_token.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config_rfid_helpers.php';

$body = require_reader_request();
$reader = reader_authenticate(is_string($body['device_token'] ?? null) ? $body['device_token'] : null);
if (!$reader) rfid_json(['ok' => false, 'error' => 'Device token rejected.'], 401);

q('UPDATE rfid_readers SET last_seen = ? WHERE id = ?', [now(), $reader['id']]);

$session = empty($reader['section_id']) ? null : open_session_for_section((int) $reader['section_id']);
$section = empty($reader['section_id']) ? null : q('SELECT name FROM sections WHERE id = ?', [(int) $reader['section_id']])->fetch();

rfid_json([
    'ok' => true,
    'server_time' => date('c'),
    'reader_name' => $reader['name'],
    'section_id' => $reader['section_id'] ? (int) $reader['section_id'] : null,
    'section_name' => $section['name'] ?? null,
    'session' => $session ? ['id' => $session['id'], 'title' => $session['title']] : null,
]);
