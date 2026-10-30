<?php
require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/attendance_modes.php';
$user = require_role('student');
close_expired_sessions();
if ($sid = (int) ($_GET['session'] ?? 0)) {
    $sectionId = (int) q('SELECT section_id FROM class_sessions WHERE id = ?', [$sid])->fetchColumn();
    if ($sectionId) sync_direct_attendance_sessions($sectionId);
}

$sid = (int) ($_GET['session'] ?? 0);
$row = q("SELECT cs.*, st.id AS roster_id, s.name AS section_name, COALESCE(sub.code,'SECTION') AS code FROM class_sessions cs
    JOIN sections s ON s.id = cs.section_id LEFT JOIN subjects sub ON sub.id = s.subject_id
    JOIN students st ON st.section_id = s.id AND st.user_id = ?
    WHERE cs.id = ?", [$user['id'], $sid])->fetch();

$already = $row ? (bool) q('SELECT 1 FROM attendance WHERE session_id = ? AND student_id = ?', [$sid, $row['roster_id']])->fetchColumn() : false;
$qr = null; $expiresAt = 0;

if ($row && $row['status'] === 'open' && !$already) {
    // remove this student's old unused tokens for the session, then issue a fresh one-time token
    q('DELETE FROM qr_tokens WHERE session_id = ? AND student_id = ? AND used_at IS NULL', [$sid, $row['roster_id']]);
    $token = bin2hex(random_bytes(32));
    $expiresAt = time() + QR_TTL_SECONDS;
    q('INSERT INTO qr_tokens (token_hash, session_id, student_id, expires_at) VALUES (?,?,?,?)',
      [hash('sha256', $token), $sid, $row['roster_id'], date('Y-m-d H:i:s', $expiresAt)]);
    $qr = qr_data_uri('QRA:' . $token);
}

layout_start('My attendance QR', 'home');
?>
<a class="back" href="<?= e(url('student/index.php')) ?>"><?= icon('arrow-left', 14) ?> Back</a>
<section class="card narrow center pass">
  <?php if (!$row): ?>
    <h3>Session not available</h3><p class="muted">You’re not enrolled in this class or the session doesn’t exist.</p>
  <?php elseif ($already): ?>
    <div class="success-icon"><?= icon('check', 30) ?></div><h3>You’re marked present</h3>
    <p class="muted"><?= e($row['code'] . ' · ' . $row['section_name']) ?></p>
    <a class="btn btn-primary" href="<?= e(url('student/index.php')) ?>">Back to dashboard</a>
  <?php elseif ($row['status'] !== 'open'): ?>
    <h3>Attendance is closed</h3><p class="muted">This session has ended.</p>
  <?php else: ?>
    <span class="eyebrow"><span class="dot-live"></span> One-time pass</span>
    <h3><?= e($row['code'] . ' · ' . $row['section_name']) ?></h3>
    <p class="muted small">Show this to your professor. Turn up your screen brightness.</p>
    <div class="qr-frame big" id="qrFrame"><img src="<?= e($qr) ?>" alt="Your attendance QR code"></div>
    <div class="countdown" data-expires="<?= $expiresAt * 1000 ?>" data-status-url="<?= e(url('student/qr.php?session=' . $sid)) ?>">
      <?= icon('alarm-clock', 15) ?> Expires in <b id="cd"><?= QR_TTL_SECONDS ?></b>s
    </div>
    <a class="btn btn-primary btn-block" href="<?= e(url('student/qr.php?session=' . $sid)) ?>"><?= icon('refresh-cw', 16) ?> Refresh QR now</a>
  <?php endif; ?>
</section>
<?php layout_end(); ?>
