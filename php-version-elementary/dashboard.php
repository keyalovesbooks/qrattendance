<?php
require __DIR__ . '/config.php';
require_once __DIR__ . '/attendance_modes.php';
$user = require_role('professor');
close_expired_sessions();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create_term') {
            $year = trim((string) $_POST['school_year']);
            $sem = trim((string) $_POST['semester']);
            if ($year === '' || $sem === '') throw new RuntimeException('Enter a school year and semester.');
            q('UPDATE terms SET is_current = 0 WHERE professor_id = ?', [professor_id()]);
            q('INSERT INTO terms (school_year, semester, is_current, professor_id) VALUES (?,?,1,?)
               ON DUPLICATE KEY UPDATE is_current = 1', [$year, $sem, professor_id()]);
            flash("Current term set to $year · $sem.");
        } elseif ($action === 'set_term') {
            $pid = professor_id();
            if (!q('SELECT 1 FROM terms WHERE id = ? AND professor_id = ?', [(int) $_POST['term_id'], $pid])->fetchColumn()) {
                throw new RuntimeException('That academic term does not belong to your workspace.');
            }
            q('UPDATE terms SET is_current = 0 WHERE professor_id = ?', [$pid]);
            q('UPDATE terms SET is_current = 1 WHERE id = ? AND professor_id = ?', [(int) $_POST['term_id'], $pid]);
            flash('Current term changed.');
        } elseif ($action === 'set_primary_mode') {
            $mode = (string) ($_POST['primary_attendance_mode'] ?? '');
            if (($user['teacher_level'] ?? '') !== 'primary' || !in_array($mode, ['subject', 'direct'], true)) {
                throw new RuntimeException('Choose a valid primary-teacher attendance mode.');
            }
            q('UPDATE users SET primary_attendance_mode = ? WHERE id = ? AND role = ?', [$mode, $user['id'], 'professor']);
            flash($mode === 'subject' ? 'Attendance mode set to class (subject).' : 'Attendance mode set to section-only.');
            redirect('classes.php?flow=' . $mode);
        } elseif ($action === 'start_session') {
            $secId = (int) ($_POST['section_id'] ?? 0);
            $active = current_term();
            $mine = (bool) q('SELECT 1 FROM sections s JOIN subjects sub ON sub.id = s.subject_id WHERE s.id = ? AND sub.term_id = ?', [$secId, $active['id'] ?? 0])->fetchColumn();
            if (!$mine) throw new RuntimeException('Choose one of your sections first.');
            // reuse a live session when this section already has one, otherwise open a new one
            $open = q("SELECT id FROM class_sessions WHERE section_id = ? AND status = 'open' AND expires_at > ? ORDER BY id DESC LIMIT 1", [$secId, now()])->fetch();
            if (!$open) {
                q("UPDATE class_sessions SET status = 'closed' WHERE section_id = ? AND status = 'open'", [$secId]);
                q('INSERT INTO class_sessions (section_id, title, started_at, expires_at) VALUES (?,?,?,?)',
                  [$secId, 'Class on ' . date('M j, Y'), now(), date('Y-m-d H:i:s', time() + SESSION_MINUTES * 60)]);
                $open = ['id' => db()->lastInsertId()];
            }
            redirect('scan.php?session=' . (int) $open['id']);
        }
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
    } catch (PDOException) {
        flash('Database error. Please try again.', 'error');
    }
    redirect('dashboard.php');
}

$term = current_term();
$terms = q('SELECT * FROM terms WHERE professor_id = ? ORDER BY id DESC', [professor_id()])->fetchAll();
$today = date('D');

$stats = ['students' => 0, 'sections' => 0, 'today' => 0, 'rate' => 0];
$todayClasses = [];
if ($term) {
    $stats['sections'] = (int) q('SELECT COUNT(*) FROM sections WHERE term_id = ?', [$term['id']])->fetchColumn();
    $stats['students'] = (int) q('SELECT COUNT(*) FROM students st JOIN sections s ON s.id = st.section_id WHERE s.term_id = ?', [$term['id']])->fetchColumn();
    $stats['today'] = (int) q('SELECT COUNT(*) FROM attendance a JOIN class_sessions cs ON cs.id = a.session_id JOIN sections s ON s.id = cs.section_id WHERE DATE(a.scanned_at) = CURDATE() AND s.term_id = ?', [$term['id']])->fetchColumn();
    $row = q('SELECT COUNT(a.id) AS present,
        (SELECT COUNT(*) FROM class_sessions cs2 JOIN sections s2 ON s2.id = cs2.section_id JOIN students st ON st.section_id = s2.id WHERE s2.term_id = ?) AS possible
        FROM attendance a JOIN class_sessions cs ON cs.id = a.session_id JOIN sections s ON s.id = cs.section_id WHERE s.term_id = ?', [$term['id'], $term['id']])->fetch();
    $stats['rate'] = $row['possible'] ? (int) round($row['present'] / $row['possible'] * 100) : 0;

    $todayClasses = q('SELECT ss.*, s.id AS section_id, s.name AS section_name, sub.code, sub.name AS subject_name,
            (SELECT COUNT(*) FROM students st WHERE st.section_id = s.id) AS total
        FROM section_schedules ss JOIN sections s ON s.id = ss.section_id JOIN subjects sub ON sub.id = s.subject_id
        WHERE s.term_id = ? AND s.section_mode = ? AND ss.day = ? ORDER BY ss.start_time', [$term['id'], 'subject', $today])->fetchAll();
}
$teacherLevel = $user['teacher_level'] ?? '';
$directActive = ($term && $teacherLevel === 'primary') ? sync_professor_direct_sessions((int) $user['id']) : [];
$directSections = ($term && $teacherLevel === 'primary') ? direct_sections_for_professor((int) $term['id']) : [];
// primary (elementary) teachers follow either the subject or direct-section flow
$checklist = [
  'Create a section' => ($term && (int) q('SELECT COUNT(*) FROM sections WHERE term_id = ?', [$term['id']])->fetchColumn()) > 0,
  'Import the CSV class list' => ($term && (int) q('SELECT COUNT(*) FROM students st JOIN sections s ON s.id = st.section_id WHERE s.term_id = ?', [$term['id']])->fetchColumn()) > 0,
  'Record time in and time out' => ($term && (int) q('SELECT COUNT(*) FROM section_attendance_times t JOIN sections s ON s.id = t.section_id WHERE s.term_id = ?', [$term['id']])->fetchColumn()) > 0,
  'Students enroll by QR or login code' => ($term && (int) q('SELECT COUNT(*) FROM students st JOIN sections s ON s.id = st.section_id WHERE s.term_id = ? AND st.user_id IS NOT NULL', [$term['id']])->fetchColumn()) > 0,
 ];

$live = q("SELECT cs.*, s.name AS section_name, COALESCE(sub.code, 'SECTION') AS code, s.id AS sec_id,
        (SELECT COUNT(*) FROM attendance a WHERE a.session_id = cs.id) AS present
    FROM class_sessions cs JOIN sections s ON s.id = cs.section_id
    LEFT JOIN subjects sub ON sub.id = s.subject_id
    WHERE cs.status = 'open' AND s.term_id IN (SELECT id FROM terms WHERE professor_id = ?)
    ORDER BY cs.started_at DESC", [professor_id()])->fetchAll();

layout_start('Overview', 'dashboard');
?>
<div class="page-head">
  <div><span class="eyebrow"><?= icon('layout-dashboard', 14) ?> Professor dashboard</span><h2>Good day, <?= e(first_name($user)) ?>.</h2>
    <p class="muted"><?= $term ? e($term['school_year'] . ' · ' . $term['semester']) : 'Start by setting the school year and semester.' ?></p></div>
  <div class="actions">
    <a class="btn btn-secondary" href="<?= e(url('settings.php')) ?>"><?= icon('calendar-range', 16) ?> School year / semester</a>
    <a class="btn btn-primary" href="<?= e(url('classes.php')) ?>"><?= icon($teacherLevel === 'primary' ? 'list-checks' : 'plus', 17) ?> <?= $teacherLevel === 'primary' ? 'Set up class' : 'New subject' ?></a>
  </div>
</div>

<?php if ($teacherLevel === 'primary' && $term && empty($user['primary_attendance_mode'])): ?>
  <section class="card primary-mode-card">
    <div class="card-head"><div><h3><?= icon('list-checks', 17) ?> How do you want to record attendance?</h3>
      <p class="muted small">Choose once. You can change this later in Workspace settings.</p></div></div>
    <div class="attendance-mode-options">
      <form method="post" class="attendance-mode-form"><?= csrf_field() ?>
        <input type="hidden" name="action" value="set_primary_mode"><input type="hidden" name="primary_attendance_mode" value="subject">
        <button class="attendance-mode-option" type="submit">
          <span class="mode-icon"><?= icon('book-open', 20) ?></span><span class="mode-copy"><b>Record by class (subject)</b><small>Create the subject first, then create its sections.</small></span><span class="mode-arrow">→</span>
        </button>
      </form>
      <form method="post" class="attendance-mode-form"><?= csrf_field() ?>
        <input type="hidden" name="action" value="set_primary_mode"><input type="hidden" name="primary_attendance_mode" value="direct">
        <button class="attendance-mode-option" type="submit">
          <span class="mode-icon mode-icon-direct"><?= icon('users', 20) ?></span><span class="mode-copy"><b>Record by section</b><small>Create a section directly — no subject required. Set four daily 30-minute windows.</small></span><span class="mode-arrow">→</span>
        </button>
      </form>
    </div>
  </section>
<?php endif; ?>
<?php if ($teacherLevel === 'primary' && $term && !empty($user['primary_attendance_mode']) && count(array_filter($checklist)) < count($checklist)): ?>
  <section class="card setup-checklist">
    <div class="card-head"><div><h3><?= icon('list-checks', 17) ?> Set up your class</h3>
      <p class="muted small">Create a class or section, import the roster, set attendance windows and share the enrolment code.</p></div>
      <span class="badge <?= count(array_filter($checklist)) === count($checklist) ? 'badge-green' : 'badge-amber' ?>">
        <?= count(array_filter($checklist)) ?> of <?= count($checklist) ?> setup steps</span></div>
    <ol class="setup-steps">
      <?php
      $steps = [
        ['label' => 'Create a section', 'hint' => 'Choose class (subject) or section-only attendance', 'href' => 'classes.php'],
        ['label' => 'Import the CSV class list', 'hint' => 'Open the section → Import CSV', 'href' => 'students.php'],
        ['label' => 'Record time in and time out', 'hint' => 'Section → Attendance windows (30 minutes each)', 'href' => 'classes.php?flow=direct'],
        ['label' => 'Students enroll by QR or login code', 'hint' => 'Section → Enrollment QR', 'href' => 'classes.php?flow=direct'],
      ];
      foreach ($steps as $i => $step):
        $label = $step['label']; $hint = $step['hint']; $href = $step['href'];
        $done = $checklist[$label] ?? false;
      ?>
        <li class="<?= $done ? 'done' : '' ?>">
          <span class="setup-step-mark"><?= $done ? icon('check', 15) : ($i + 1) ?></span>
          <span class="setup-step-copy"><b><?= e($label) ?></b><small><?= e($hint) ?></small></span>
          <a class="btn btn-soft btn-sm" href="<?= e(url($href)) ?>"><?= $done ? 'Review' : 'Start' ?></a>
        </li>
      <?php endforeach; ?>
    </ol>
  </section>
<?php endif; ?>

<?php if (!$term): ?>
  <section class="card empty">
    <div class="empty-icon"><?= icon('calendar-range', 23) ?></div><h3>Set the school year and semester</h3>
    <p class="muted">Everything you create — subjects, sections, schedules and attendance — is grouped under a term so you can view history later.</p>
    <form method="post" class="form-row"><?= csrf_field() ?><input type="hidden" name="action" value="create_term">
      <input class="input" name="school_year" placeholder="2025-2026" required>
      <select class="input" name="semester"><option>1st Semester</option><option>2nd Semester</option><option>Summer</option></select>
      <button class="btn btn-primary">Save term</button></form>
  </section>
<?php else: ?>

<div class="stats">
  <div class="stat card"><span class="stat-icon blue"><?= icon('users', 19) ?></span><span class="stat-label">Students</span><strong><?= $stats['students'] ?></strong><small>enrolled this term</small></div>
  <div class="stat card"><span class="stat-icon violet"><?= icon('book-open', 19) ?></span><span class="stat-label">Sections</span><strong><?= $stats['sections'] ?></strong><small>with their schedules</small></div>
  <div class="stat card"><span class="stat-icon green"><?= icon('circle-check', 19) ?></span><span class="stat-label">Scanned today</span><strong><?= $stats['today'] ?></strong><small>attendance records</small></div>
  <div class="stat card"><span class="stat-icon amber"><?= icon('activity', 19) ?></span><span class="stat-label">Attendance rate</span><strong><?= $stats['rate'] ?>%</strong><small>this term</small></div>
</div>

<?php if ($live): ?>
<section class="card live-card">
  <div class="card-head"><h3><span class="dot-live"></span> Live sessions</h3>
    <a class="text-link" href="<?= e(url('live.php')) ?>">Open scanner page →</a></div>
  <?php foreach ($live as $l): ?>
    <div class="list-row">
      <div><b><?= e($l['code'] . ' · ' . $l['section_name']) ?></b><small class="muted"><?= e($l['title']) ?> · <?= (int) $l['present'] ?> checked in · closes <?= e(date('g:i A', strtotime($l['expires_at']))) ?></small></div>
      <a class="btn btn-primary btn-sm" href="<?= e(url('scan.php?session=' . $l['id'])) ?>"><?= icon('camera', 15) ?> Open scanner</a>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<div class="dashboard-two">
  <section class="card">
    <div class="card-head"><h3><?= icon('calendar-days', 17) ?> Classes today</h3><span class="badge"><?= e(day_label($today)) ?></span></div>
    <?php if (!$todayClasses): ?>
      <div class="empty"><div class="empty-icon"><?= icon('clock', 20) ?></div><h3>Nothing scheduled today</h3>
        <p class="muted">Meeting days come from each section’s schedule.</p>
        <a class="btn btn-soft btn-sm" href="<?= e(url('classes.php')) ?>">Manage schedules</a></div>
    <?php else: ?>
      <?php foreach ($todayClasses as $c): ?>
        <div class="list-row">
          <div><b><?= e($c['code'] . ' · ' . $c['section_name']) ?></b>
            <small class="muted"><?= e(time_label($c['start_time']) . ' – ' . time_label($c['end_time'])) ?><?= $c['room'] ? ' · ' . e($c['room']) : '' ?> · <?= (int) $c['total'] ?> students</small></div>
          <div class="actions">
            <a class="btn btn-soft btn-sm" href="<?= e(url('section.php?id=' . $c['section_id'])) ?>">Section</a>
            <form method="post" class="inline"><?= csrf_field() ?>
              <input type="hidden" name="action" value="start_session">
              <input type="hidden" name="section_id" value="<?= (int) $c['section_id'] ?>">
              <button class="btn btn-primary btn-sm" type="submit"><?= icon('qr-code', 15) ?> Take attendance</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head"><h3><?= icon('book-open', 17) ?> Quick actions</h3></div>
    <div class="quick-actions">
      <a class="quick-action" href="<?= e(url('classes.php')) ?>"><?= icon('book-open', 19) ?><span><b>Classes &amp; sections</b><small>Subjects, sections, schedules</small></span></a>
      <a class="quick-action" href="<?= e(url('students.php')) ?>"><?= icon('users', 19) ?><span><b>Students roster</b><small>Import, edit and export</small></span></a>
      <a class="quick-action" href="<?= e(url('live.php')) ?>"><?= icon('qr-code', 19) ?><span><b>Live attendance</b><small>Scan student QR passes</small></span></a>
      <a class="quick-action" href="<?= e(url('history.php')) ?>"><?= icon('history', 19) ?><span><b>Attendance history</b><small>Per school year &amp; semester</small></span></a>
    </div>
  </section>
</div>
<?php endif; ?>

<?php layout_end(); ?>
