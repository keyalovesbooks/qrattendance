<?php
require __DIR__ . '/config.php';
require_once __DIR__ . '/attendance_modes.php';
$user = require_role('professor');
close_expired_sessions();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'start_session') {
        $secId = (int) ($_POST['section_id'] ?? 0);
        $section = q('SELECT id, section_mode FROM sections WHERE id = ? AND term_id = ? AND professor_id = ?', [$secId, current_term()['id'] ?? 0, professor_id()])->fetch();
        if (!$section) { flash('Choose one of your sections first.', 'error'); redirect('live.php'); }
        if ($section['section_mode'] === 'direct') { flash('Direct-section attendance opens automatically during its 30-minute windows.', 'error'); redirect('live.php'); }
        q("UPDATE class_sessions SET status = 'closed' WHERE section_id = ? AND status = 'open'", [$secId]);
        $title = trim((string) ($_POST['title'] ?? '')) ?: 'Class on ' . date('M j, Y');
        q('INSERT INTO class_sessions (section_id, title, started_at, expires_at) VALUES (?,?,?,?)',
          [$secId, $title, now(), date('Y-m-d H:i:s', time() + SESSION_MINUTES * 60)]);
        redirect('scan.php?session=' . db()->lastInsertId());
    }
    if ($action === 'close_session') {
        if (!owns_session((string) (int) $_POST['session_id'])) throw new RuntimeException('That session does not belong to your workspace.');
        q("UPDATE class_sessions SET status = 'closed' WHERE id = ?", [(int) $_POST['session_id']]);
        flash('Session closed.');
        redirect('live.php');
    }
}

$term = current_term();
$sections = $term ? q("SELECT s.id, s.name, COALESCE(sub.code,'SECTION') AS code,
        COALESCE(sub.name,'Direct section attendance') AS subject_name, s.section_mode,
        (SELECT COUNT(*) FROM students st WHERE st.section_id = s.id) AS total
    FROM sections s LEFT JOIN subjects sub ON sub.id = s.subject_id
    WHERE s.term_id = ? AND s.professor_id = ? AND s.section_mode = 'subject' ORDER BY COALESCE(sub.code,'SECTION'), s.name", [$term['id'], professor_id()])->fetchAll() : [];
$today = date('D');
$todayClasses = q('SELECT ss.*, s.id AS section_id, s.name AS section_name, sub.code, sub.name AS subject_name,
        (SELECT COUNT(*) FROM students st WHERE st.section_id = s.id) AS total
    FROM section_schedules ss JOIN sections s ON s.id = ss.section_id JOIN subjects sub ON sub.id = s.subject_id
    WHERE s.term_id = ? AND s.section_mode = ? AND ss.day = ? ORDER BY ss.start_time', [$term['id'] ?? 0, 'subject', $today])->fetchAll();

$teacherLevel = $user['teacher_level'] ?? '';
$directActive = $teacherLevel === 'primary' ? sync_professor_direct_sessions((int) $user['id']) : [];
$directSections = ($term && $teacherLevel === 'primary') ? direct_sections_for_professor((int) $term['id']) : [];
$directTimes = [];
foreach ($directSections as $directSection) {
    $directTimes[$directSection['id']] = q('SELECT * FROM section_attendance_times WHERE section_id = ?', [(int) $directSection['id']])->fetch() ?: [];
}
$live = q("SELECT cs.*, s.name AS section_name, COALESCE(sub.code,'SECTION') AS code,
        COALESCE(sub.name,'Section attendance') AS subject_name,
        (SELECT COUNT(*) FROM attendance a WHERE a.session_id = cs.id) AS present,
        (SELECT COUNT(*) FROM students st WHERE st.section_id = cs.section_id) AS total
    FROM class_sessions cs JOIN sections s ON s.id = cs.section_id LEFT JOIN subjects sub ON sub.id = s.subject_id
    WHERE cs.status = 'open' AND s.section_mode = 'subject' AND s.term_id IN (SELECT id FROM terms WHERE professor_id = ?)
    ORDER BY cs.started_at DESC", [professor_id()])->fetchAll();
$closed = q("SELECT cs.*, s.name AS section_name, COALESCE(sub.code,'SECTION') AS code,
        (SELECT COUNT(*) FROM attendance a WHERE a.session_id = cs.id) AS present,
        (SELECT COUNT(*) FROM students st WHERE st.section_id = cs.section_id) AS total
    FROM class_sessions cs JOIN sections s ON s.id = cs.section_id LEFT JOIN subjects sub ON sub.id = s.subject_id
    WHERE cs.status = 'closed' AND s.term_id IN (SELECT id FROM terms WHERE professor_id = ?)
    ORDER BY cs.started_at DESC LIMIT 10", [professor_id()])->fetchAll();

layout_start('Live attendance', 'live');
?>
<div class="page-head">
  <div><span class="eyebrow"><span class="dot-live"></span> Real-time check-in</span><h2>Live attendance</h2>
    <p class="muted">Open a session, scan the students’ QR passes, and watch the roster update instantly.</p></div>
</div>

<section class="card live-card">
  <div class="card-head"><h3><?= icon('qr-code', 17) ?> Open sessions</h3><span class="badge badge-green"><?= count($live) ?> live</span></div>
  <?php if (!$live): ?>
    <div class="empty"><div class="empty-icon"><?= icon('camera', 20) ?></div><h3>No session is running</h3>
      <p class="muted">Start one below. Students generate a personal QR code that expires after <?= QR_TTL_SECONDS ?> seconds.</p></div>
  <?php else: ?>
    <?php foreach ($live as $l): ?>
      <div class="list-row">
        <div><b><?= e($l['code'] . ' · ' . $l['section_name']) ?> — <?= e($l['title']) ?></b>
          <small class="muted"><?= (int) $l['present'] ?> of <?= (int) $l['total'] ?> checked in · closes <?= e(date('g:i A', strtotime($l['expires_at']))) ?></small></div>
        <div class="actions">
          <a class="btn btn-primary btn-sm" href="<?= e(url('scan.php?session=' . $l['id'])) ?>"><?= icon('camera', 15) ?> Open scanner</a>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="close_session">
            <input type="hidden" name="session_id" value="<?= (int) $l['id'] ?>">
            <button class="btn btn-secondary btn-sm"><?= icon('circle-stop', 15) ?> End</button></form>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<?php if ($teacherLevel === 'primary' && $directSections): ?>
<section class="card direct-live-card">
  <div class="card-head"><div><h3><?= icon('clock', 17) ?> Direct section windows</h3><p class="muted small">Each configured time opens its own scanner for 30 minutes.</p></div></div>
  <?php foreach ($directSections as $directSection): $windows = $directTimes[$directSection['id']] ?? []; ?>
    <div class="direct-live-section">
      <div class="direct-live-heading"><b><?= e($directSection['name']) ?></b><small class="muted"><?= (int) $directSection['student_count'] ?> students</small></div>
      <?php $liveMode = in_array($windows['attendance_mode'] ?? 'both', ['time_in','time_out','both'], true) ? ($windows['attendance_mode'] ?? 'both') : 'both'; ?>
      <?php foreach (direct_slot_labels($liveMode) as $slot => $label): if (!empty($windows[$slot])):
        $slotStart = strtotime(date('Y-m-d') . ' ' . $windows[$slot]);
        $slotEnd = $slotStart + DIRECT_ATTENDANCE_WINDOW_MINUTES * 60;
        $activeWindow = time() >= $slotStart && time() < $slotEnd;
        $slotSession = null;
        foreach ($directActive as $directSession) {
            if ((int) $directSession['section_id'] === (int) $directSection['id'] && $directSession['attendance_slot'] === $slot) { $slotSession = $directSession; break; }
        }
      ?>
        <div class="direct-window-row">
          <span class="direct-window-icon <?= $activeWindow ? 'active' : '' ?>"><?= icon(str_contains($slot, 'am_') ? 'sun' : 'moon', 16) ?></span>
          <span class="direct-window-copy"><b><?= e($label) ?></b><small><?= e(time_label($windows[$slot])) ?> – <?= e(date('g:i A', $slotEnd)) ?></small></span>
          <?php if ($activeWindow && $slotSession): ?>
            <span class="badge badge-green"><span class="dot-live"></span> Open now</span>
            <a class="btn btn-primary btn-sm" href="<?= e(url('scan.php?session=' . $slotSession['id'])) ?>"><?= icon('camera', 14) ?> Open scanner</a>
          <?php elseif (time() < $slotStart): ?>
            <span class="badge">Opens <?= e(date('g:i A', $slotStart)) ?></span>
          <?php else: ?>
            <span class="badge">Window closed</span>
          <?php endif; ?>
        </div>
      <?php endif; endforeach; ?>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<section class="card">
  <div class="card-head"><h3><?= icon('calendar-days', 17) ?> Schedule for today</h3><span class="badge"><?= e(day_label($today)) ?></span></div>
  <?php if (!$todayClasses): ?>
    <div class="empty"><div class="empty-icon"><?= icon('clock', 20) ?></div><h3>Nothing scheduled today</h3>
      <p class="muted">Meeting days come from each section’s schedule. You can still start a session for any section below.</p></div>
    <form method="post" class="form-row"><?= csrf_field() ?><input type="hidden" name="action" value="start_session">
      <select class="input" name="section_id" required>
        <option value="">Choose a section…</option>
        <?php foreach ($sections as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['code'] . ' · ' . $s['name'] . ' (' . (int) $s['total'] . ' students)') ?></option><?php endforeach; ?>
      </select>
      <input class="input" name="title" placeholder="Session title (optional)">
      <button class="btn btn-primary"><?= icon('qr-code', 16) ?> Start &amp; scan</button>
    </form>
  <?php else: ?>
    <?php foreach ($todayClasses as $c): ?>
      <div class="list-row">
        <div>
          <b><?= e($c['code'] . ' · ' . $c['section_name']) ?></b>
          <small class="muted"><?= e(time_label($c['start_time']) . ' – ' . time_label($c['end_time'])) ?>
            <?= $c['room'] ? ' · ' . e($c['room']) : '' ?> · <?= (int) $c['total'] ?> students</small>
        </div>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="start_session">
          <input type="hidden" name="section_id" value="<?= (int) $c['section_id'] ?>">
          <input type="hidden" name="title" value="<?= e(day_label($c['day']) . ' class') ?>">
          <button class="btn btn-primary btn-sm"><?= icon('qr-code', 15) ?> Start session</button>
        </form>
      </div>
    <?php endforeach; ?>
    <p class="muted small pad"><?= icon('clock', 13) ?> Times come from each section’s schedule.</p>
  <?php endif; ?>
</section>

<section class="card">
  <div class="card-head"><h3><?= icon('history', 17) ?> Recently closed</h3>
    <a class="text-link" href="<?= e(url('history.php')) ?>">Full history →</a></div>
  <?php if (!$closed): ?><p class="muted small pad">Closed sessions will appear here.</p><?php endif; ?>
  <?php foreach ($closed as $c): ?>
    <div class="list-row">
      <div><b><?= e($c['code'] . ' · ' . $c['section_name']) ?></b>
        <small class="muted"><?= e(date('M j, Y g:i A', strtotime($c['started_at']))) ?> · <?= (int) $c['present'] ?> / <?= (int) $c['total'] ?> present</small></div>
      <div class="actions"><a class="btn btn-soft btn-sm" href="<?= e(url('export.php?type=session&id=' . $c['id'])) ?>"><?= icon('download', 15) ?> CSV</a></div>
    </div>
  <?php endforeach; ?>
</section>
<?php layout_end(); ?>
