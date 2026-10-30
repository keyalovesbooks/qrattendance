<?php
require dirname(__DIR__) . '/config.php';
header('Content-Type: application/json');

function out(int $code, array $data): never { http_response_code($code); echo json_encode($data); exit; }

$u = current_user();
if (!$u || $u['role'] !== 'professor') out(401, ['ok' => false, 'message' => 'Professor login required.']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, ['ok' => false, 'message' => 'POST only.']);
csrf_check();

$sessionId = (int) ($_POST['session'] ?? 0);
$raw = trim((string) ($_POST['code'] ?? ''));

/*
 * Two QR formats are accepted:
 *   QRA:<64 hex>  time-limited, single-use pass (highschool / college)
 *   QRS:<CODE>    permanent semester-long code (elementary)
 */
$permanent = null;
if (preg_match('/^QRS:([A-Z0-9-]{4,24})$/i', $raw)) {
    $permanent = strtoupper(trim(explode(':', $raw, 2)[1], '-'));
    $student = q('SELECT st.id, st.user_id, st.name, st.student_number FROM students st WHERE st.qr_code = ?', [$permanent])->fetch();
    if (!$student) out(422, ['ok' => false, 'message' => 'This QR code is not linked to a class list.']);

    // The permanent code may be printed from any one of a student's roster
    // rows. Match the linked account's roster row for whichever section has an
    // open session, so the same QR works across all their sections.
    // The scanner always supplies its current session ID. Do not fall back to
    // a different open class: the permanent code is reusable, the session is not.
    $open = $sessionId > 0 ? q('SELECT cs.id, st.id AS roster_id FROM class_sessions cs
        JOIN sections s ON s.id = cs.section_id
        JOIN students st ON st.section_id = s.id AND st.user_id = ?
        WHERE cs.id = ? AND cs.status = ? AND cs.expires_at > ? AND s.professor_id = ? LIMIT 1',
        [$student['user_id'], $sessionId, 'open', now(), professor_id()])->fetch() : null;

    $target = $open ? (int) $open['id'] : 0;
    $rosterId = $open ? (int) $open['roster_id'] : 0;
    if ($target <= 0 || $rosterId <= 0) {
        out(409, ['ok' => false, 'message' => $student['name'] . ' has no open class session in this section right now.']);
    }

    $ins = q('INSERT IGNORE INTO attendance (session_id, student_id, scanned_at, method) VALUES (?,?,?,?)',
             [$target, $rosterId, now(), 'qr']);
    if ($ins->rowCount() === 0) {
        out(409, ['ok' => false, 'message' => $student['name'] . ' is already marked present.']);
    }
    out(200, ['ok' => true, 'message' => $student['name'] . ' marked present.', 'student' => [
        'name' => $student['name'], 'student_number' => $student['student_number'],
        'initials' => initials($student['name']), 'time' => date('g:i A'),
    ]]);
}

$token = preg_replace('/^QRA:/', '', $raw);
if (!preg_match('/^[a-f0-9]{64}$/', $token)) out(422, ['ok' => false, 'message' => 'Not a valid attendance QR code.']);

$pdo = db();
$pdo->beginTransaction();
try {
    $t = q('SELECT qt.*, st.name, st.student_number, cs.status, cs.expires_at AS session_expires
            FROM qr_tokens qt JOIN students st ON st.id = qt.student_id JOIN class_sessions cs ON cs.id = qt.session_id
            WHERE qt.token_hash = ? FOR UPDATE', [hash('sha256', $token)])->fetch();

    if (!$t || !owns_session((string) $t['session_id'])) throw new DomainException('QR code not recognised.');
    if ((int) $t['session_id'] !== $sessionId) throw new DomainException('This QR belongs to a different class session.');
    if ($t['status'] !== 'open' || strtotime($t['session_expires']) <= time()) throw new DomainException('This session is closed.');
    if ($t['used_at']) throw new DomainException('This QR code was already used.');
    if (strtotime($t['expires_at']) <= time()) throw new DomainException('QR code expired. Ask the student to generate a new one.');

    q('UPDATE qr_tokens SET used_at = ? WHERE id = ?', [now(), $t['id']]);
    $ins = q('INSERT IGNORE INTO attendance (session_id, student_id, scanned_at) VALUES (?,?,?)', [$sessionId, $t['student_id'], now()]);
    $pdo->commit();

    if ($ins->rowCount() === 0) out(409, ['ok' => false, 'message' => $t['name'] . ' is already marked present.']);
    out(200, ['ok' => true, 'message' => $t['name'] . ' marked present.', 'student' => [
        'name' => $t['name'], 'student_number' => $t['student_number'], 'initials' => initials($t['name']), 'time' => date('g:i A'),
    ]]);
} catch (DomainException $ex) {
    $pdo->rollBack();
    out(422, ['ok' => false, 'message' => $ex->getMessage()]);
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    out(500, ['ok' => false, 'message' => 'Server error. Try again.']);
}
