<?php
require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/attendance_modes.php';
$user = require_role('student');
close_expired_sessions();
sync_student_direct_sessions((int) $user['id']);

$classes = q("SELECT st.id AS roster_id, s.id AS section_id, s.name AS section_name, s.schedule, s.room,
        s.section_mode, COALESCE(sub.code,'SECTION') AS code,
        COALESCE(sub.name,'Direct section attendance') AS subject_name, t.school_year, t.semester,
        (SELECT COUNT(*) FROM class_sessions cs WHERE cs.section_id = s.id) AS sessions,
        (SELECT COUNT(*) FROM attendance a WHERE a.student_id = st.id) AS attended,
        (SELECT cs.id FROM class_sessions cs WHERE cs.section_id = s.id AND cs.status = 'open' AND cs.expires_at > ? ORDER BY cs.started_at DESC LIMIT 1) AS open_session
    FROM students st JOIN sections s ON s.id = st.section_id JOIN terms t ON t.id = s.term_id
    LEFT JOIN subjects sub ON sub.id = s.subject_id
    WHERE st.user_id = ? ORDER BY t.id DESC, COALESCE(sub.code,'SECTION')", [now(), $user['id']])->fetchAll();

$history = q("SELECT a.scanned_at, cs.title, COALESCE(sub.code,'SECTION') AS code, s.name AS section_name FROM attendance a
    JOIN students st ON st.id = a.student_id JOIN class_sessions cs ON cs.id = a.session_id
    JOIN sections s ON s.id = cs.section_id LEFT JOIN subjects sub ON sub.id = s.subject_id
    WHERE st.user_id = ? ORDER BY a.scanned_at DESC LIMIT 50", [$user['id']])->fetchAll();

$sched = schedules_by_section(array_column($classes, 'section_id'));
$directTimes = [];
foreach ($classes as $class) {
    if ($class['section_mode'] === 'direct') {
        $directTimes[$class['section_id']] = q('SELECT * FROM section_attendance_times WHERE section_id = ?', [(int) $class['section_id']])->fetch() ?: [];
    }
}
$totalSessions = array_sum(array_column($classes, 'sessions'));
$totalAttended = array_sum(array_column($classes, 'attended'));
$rate = $totalSessions ? (int) round($totalAttended / $totalSessions * 100) : 0;
$openNow = array_filter($classes, fn($c) => $c['open_session']);
$level = $user['student_level'] ?? 'college';          // elementary uses the permanent QR
$isElementary = $level === 'elementary';
$permanentCode = q('SELECT qr_code FROM students WHERE user_id = ? AND qr_code IS NOT NULL ORDER BY id LIMIT 1',
    [(int) $user['id']])->fetchColumn() ?: null;

layout_start('My classes', 'home');
?>
<div class="page-head">
  <div><span class="eyebrow">Student dashboard</span><h2>Hi, <?= e(first_name($user)) ?>.</h2>
    <p class="muted">Student ID <?= e($user['student_number']) ?>
      <span class="badge badge-blue"><?= e(ucfirst($level)) ?></span></p></div>
  <div class="actions"><button class="btn btn-primary" data-open="joinModal"><?= icon('plus', 17) ?> Join a class</button></div>
</div>

<?php if ($isElementary): ?>
  <section class="hero-banner">
    <div><span class="eyebrow light"><?= icon('sparkles', 14) ?> Your semester pass</span>
      <h3>One QR code for the whole semester</h3><p>Open your QR code, show it to your teacher, and you’re marked present.</p></div>
    <a class="btn btn-white" href="<?= e(url('student/permanent-qr.php')) ?>"><?= icon('qr-code', 17) ?> Open my QR code</a>
  </section>
<?php else: ?>
  <?php foreach ($openNow as $c): ?>
    <section class="hero-banner">
      <div><span class="eyebrow light"><?= icon('sparkles', 14) ?> Attendance is open</span>
        <h3><?= e($c['code'] . ' · ' . $c['section_name']) ?></h3><p>Generate your QR code and show it to your professor.</p></div>
      <a class="btn btn-white" href="<?= e(url('student/qr.php?session=' . $c['open_session'])) ?>"><?= icon('qr-code', 17) ?> Generate my QR</a>
    </section>
  <?php endforeach; ?>
<?php endif; ?>

<div class="stats stats-3">
  <div class="stat card"><span class="stat-icon blue"><?= icon('book-open', 19) ?></span><span class="stat-label">Classes</span><strong><?= count($classes) ?></strong><small>enrolled</small></div>
  <div class="stat card"><span class="stat-icon green"><?= icon('circle-check', 19) ?></span><span class="stat-label">Attended</span><strong><?= $totalAttended ?></strong><small>of <?= $totalSessions ?> sessions</small></div>
  <div class="stat card"><span class="stat-icon amber"><?= icon('activity', 19) ?></span><span class="stat-label">Attendance rate</span><strong><?= $rate ?>%</strong><small>overall</small></div>
</div>

<?php if (!$classes): ?>
  <section class="card empty"><div class="empty-icon"><?= icon('qr-code', 23) ?></div><h3>You’re not enrolled in any class yet</h3>
    <p class="muted">Scan the enrollment QR code your professor shows in class, or enter the code below.</p>
    <button class="btn btn-primary" data-open="joinModal"><?= icon('qr-code', 16) ?> Enter class code</button></section>
<?php else: ?>
<div class="subject-grid" id="classes">
  <?php foreach ($classes as $c): $r = $c['sessions'] ? round($c['attended'] / $c['sessions'] * 100) : 0; ?>
  <section class="card subject-card">
    <div class="card-head"><div><span class="badge badge-blue"><?= e($c['code']) ?></span><h3><?= e($c['subject_name']) ?></h3></div>
      <?php if ($c['open_session']): ?><span class="badge badge-green">Live</span><?php endif; ?></div>
    <div class="schedule-list">
      <?php if ($c['section_mode'] === 'direct'): ?>
        <?php $windows = $directTimes[$c['section_id']] ?? []; ?>
        <?php $studentWindowMode = in_array($windows['attendance_mode'] ?? 'both', ['time_in','time_out','both'], true) ? ($windows['attendance_mode'] ?? 'both') : 'both'; ?>
        <?php foreach (direct_slot_labels($studentWindowMode) as $slot => $label): if (!empty($windows[$slot])): ?>
          <span class="schedule-chip"><b><?= e($label) ?></b><?= icon('clock', 13) ?> <?= e(time_label($windows[$slot])) ?> · 30 min</span>
        <?php endif; endforeach; ?>
        <?php if (!$windows || !array_filter(array_intersect_key($windows, direct_slot_labels($studentWindowMode))): ?><span class="schedule-chip muted"><?= icon('clock', 13) ?> Attendance times not set</span><?php endif; ?>
      <?php else: ?>
        <?php foreach (($sched[$c['section_id']] ?? []) as $sr): ?>
          <span class="schedule-chip"><b><?= e(day_label($sr['day'])) ?></b><?= icon('clock', 13) ?> <?= e(time_label($sr['start_time']) . ' – ' . time_label($sr['end_time'])) ?><?= $sr['room'] ? ' ' . icon('map-pin', 13) . ' ' . e($sr['room']) : '' ?></span>
        <?php endforeach; ?>
        <?php if (empty($sched[$c['section_id']])): ?><span class="schedule-chip muted"><?= icon('clock', 13) ?> No schedule set</span><?php endif; ?>
      <?php endif; ?>
    </div>
    <div class="bar-row"><div class="bar"><span style="width:<?= $r ?>%"></span></div><small><?= (int) $c['attended'] ?>/<?= (int) $c['sessions'] ?></small></div>
    <?php if ($isElementary): ?>
      <a class="btn btn-primary btn-block" href="<?= e(url('student/permanent-qr.php')) ?>"><?= icon('qr-code', 17) ?> My QR code</a>
    <?php elseif ($c['open_session']): ?>
      <a class="btn btn-primary btn-block" href="<?= e(url('student/qr.php?session=' . $c['open_session'])) ?>"><?= icon('qr-code', 17) ?> Generate my QR</a>
    <?php else: ?>
      <button class="btn btn-soft btn-block" disabled>No session open</button>
    <?php endif; ?>
    <small class="muted"><?= e($c['school_year'] . ' · ' . $c['semester']) ?></small>
  </section>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<section class="card" id="history">
  <div class="card-head"><h3>My attendance</h3></div>
  <?php if (!$history): ?><p class="muted small pad">Your check-ins will appear here.</p><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Date</th><th>Class</th><th>Session</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($history as $h): ?>
      <tr><td data-label="Date"><?= e(date('M j, Y g:i A', strtotime($h['scanned_at']))) ?></td>
        <td data-label="Class"><b><?= e($h['code']) ?></b> · <?= e($h['section_name']) ?></td>
        <td data-label="Session"><?= e($h['title']) ?></td>
        <td data-label="Status"><span class="badge badge-green">Present</span></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</section>

<dialog class="modal" id="joinModal">
  <form method="get" action="<?= e(url('student/enroll.php')) ?>" class="modal-body">
    <h3>Join a class</h3>
    <p class="muted small">Scan the QR with your phone camera, or type the code shown under it.</p>
    <label class="label">Class code</label><input class="input code-input" name="code" required placeholder="e.g. 4F9A1C2B7D3E5A60">
    <div class="modal-actions"><button type="button" class="btn btn-secondary" data-close>Cancel</button><button class="btn btn-primary">Continue</button></div>
  </form>
</dialog>
<?php layout_end(); ?>
