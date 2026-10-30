<?php
require __DIR__ . '/config.php';
$user = require_role('professor');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'set_primary_attendance_mode') {
            $mode = (string) ($_POST['primary_attendance_mode'] ?? '');
            if (($user['teacher_level'] ?? '') !== 'primary' || !in_array($mode, ['subject', 'direct'], true)) {
                throw new RuntimeException('Choose a valid primary attendance mode.');
            }
            q('UPDATE users SET primary_attendance_mode = ? WHERE id = ? AND role = ?', [$mode, $user['id'], 'professor']);
            flash($mode === 'subject' ? 'Attendance mode changed to class (subject).' : 'Attendance mode changed to section-only.');
        } elseif ($action === 'create_term') {
            $year = trim((string) $_POST['school_year']);
            $sem = trim((string) $_POST['semester']);
            if ($year === '' || $sem === '') throw new RuntimeException('Enter a school year and semester.');
            $pid = professor_id();
            q('INSERT INTO terms (school_year, semester, is_current, professor_id) VALUES (?,?,0,?)
               ON DUPLICATE KEY UPDATE id = id', [$year, $sem, $pid]);
            $row = q('SELECT id FROM terms WHERE school_year = ? AND semester = ? AND professor_id = ?', [$year, $sem, $pid])->fetch();
            q('UPDATE terms SET is_current = 0 WHERE professor_id = ?', [$pid]);
            q('UPDATE terms SET is_current = 1 WHERE id = ? AND professor_id = ?', [(int) $row['id'], $pid]);
            flash("Academic term saved and set as current.");
        } elseif ($action === 'set_term') {
            $pid = professor_id();
            if (!q('SELECT 1 FROM terms WHERE id = ? AND professor_id = ?', [(int) $_POST['term_id'], $pid])->fetchColumn()) {
                throw new RuntimeException('That academic term does not belong to your workspace.');
            }
            q('UPDATE terms SET is_current = 0 WHERE professor_id = ?', [$pid]);
            q('UPDATE terms SET is_current = 1 WHERE id = ? AND professor_id = ?', [(int) $_POST['term_id'], $pid]);
            flash('Academic term set as current.');
        } elseif ($action === 'delete_term') {
            $id = (int) $_POST['term_id'];
            if (!owns_term($id)) throw new RuntimeException('That academic term does not belong to your workspace.');
            if ((bool) q('SELECT is_current FROM terms WHERE id = ?', [$id])->fetchColumn()) {
                throw new RuntimeException('Switch to another term before deleting the current one.');
            }
            q('DELETE FROM terms WHERE id = ? AND professor_id = ?', [$id, professor_id()]);
            flash('Academic term deleted, with its classes and records.');
        }
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
    } catch (PDOException) {
        flash('Database error. Please try again.', 'error');
    }
    redirect('settings.php');
}

$terms = q('SELECT * FROM terms WHERE professor_id = ? ORDER BY is_current DESC, id DESC', [professor_id()])->fetchAll();
$counts = [];
foreach ($terms as $t) {
    $counts[$t['id']] = [
        'sections' => (int) q('SELECT COUNT(*) FROM sections WHERE term_id = ?', [$t['id']])->fetchColumn(),
        'students' => (int) q('SELECT COUNT(*) FROM students st JOIN sections s ON s.id = st.section_id WHERE s.term_id = ?', [$t['id']])->fetchColumn(),
    ];
}
// Reuse the column-aware current_user() row instead of a second query that
// could reference columns a not-yet-migrated database doesn't have.
$account = $user;

layout_start('Workspace settings', 'settings');
?>
<div class="page-head">
  <div><span class="eyebrow"><?= icon('settings', 14) ?> Your workspace, your way</span><h2>Workspace settings</h2>
    <p class="muted">Manage academic terms and account preferences.</p></div>
  <div class="actions">
    <button class="btn btn-primary" data-open="termModal"><?= icon('plus', 17) ?> Add academic term</button>
  </div>
</div>

<?php if (($user['teacher_level'] ?? '') === 'primary'): ?>
<section class="card primary-workflow-settings">
  <div class="card-head"><div><h3><?= icon('list-checks', 17) ?> Primary attendance workflow</h3>
    <p class="muted small">Choose whether you organize attendance by subject or directly by section.</p></div>
    <span class="badge <?= !empty($user['primary_attendance_mode']) ? 'badge-green' : 'badge-amber' ?>">
      <?= !empty($user['primary_attendance_mode']) ? ($user['primary_attendance_mode'] === 'direct' ? 'Section mode' : 'Subject mode') : 'Not selected' ?></span>
  </div>
  <form method="post" class="workflow-settings-form"><?= csrf_field() ?><input type="hidden" name="action" value="set_primary_attendance_mode">
    <div class="attendance-mode-options">
      <label class="attendance-mode-option <?= ($user['primary_attendance_mode'] ?? '') === 'subject' ? 'active' : '' ?>">
        <input type="radio" name="primary_attendance_mode" value="subject" <?= ($user['primary_attendance_mode'] ?? '') === 'subject' ? 'checked' : '' ?> required>
        <span class="mode-icon"><?= icon('book-open', 20) ?></span>
        <span class="mode-copy"><b>Record by class (subject)</b><small>Create a subject, then sections under it. Attendance is started manually by session.</small></span>
      </label>
      <label class="attendance-mode-option <?= ($user['primary_attendance_mode'] ?? '') === 'direct' ? 'active' : '' ?>">
        <input type="radio" name="primary_attendance_mode" value="direct" <?= ($user['primary_attendance_mode'] ?? '') === 'direct' ? 'checked' : '' ?> required>
        <span class="mode-icon mode-icon-direct"><?= icon('users', 20) ?></span>
        <span class="mode-copy"><b>Record by section</b><small>Create a section without a subject. Choose time-in/out recording; each enabled window lasts 30 minutes.</small></span>
      </label>
    </div>
    <div class="modal-actions"><button class="btn btn-primary"><?= icon('save', 15) ?> Save attendance mode</button></div>
  </form>
</section>
<?php endif; ?>

<section class="card settings-card">
  <div class="card-head">
    <div><h3>Academic terms</h3><p class="muted small">Choose the active school year and keep old semesters for reference</p></div>
  </div>
  <?php if (!$terms): ?>
    <div class="empty"><div class="empty-icon"><?= icon('calendar-range', 20) ?></div><h3>No academic terms set up</h3>
      <p class="muted">Create your first school year and semester to organize class sections.</p>
      <button class="btn btn-primary btn-sm" data-open="termModal"><?= icon('plus', 15) ?> Add an academic term</button></div>
  <?php else: ?>
  <div class="terms-list">
    <?php foreach ($terms as $t): ?>
      <div class="term-row">
        <span class="term-icon <?= $t['is_current'] ? 'term-current-icon' : '' ?>"><?= icon('calendar-range', 19) ?></span>
        <span class="term-row-copy">
          <b><?= e($t['school_year']) ?> <span>·</span> <?= e($t['semester']) ?></b>
          <small><?= $t['is_current'] ? 'Current academic term' : 'Archived term · history preserved' ?>
            · <?= $counts[$t['id']]['sections'] ?> section<?= $counts[$t['id']]['sections'] === 1 ? '' : 's' ?>, <?= $counts[$t['id']]['students'] ?> student<?= $counts[$t['id']]['students'] === 1 ? '' : 's' ?></small>
        </span>
        <?php if ($t['is_current']): ?>
          <span class="badge badge-green"><?= icon('check', 13) ?> Current</span>
        <?php else: ?>
          <div class="actions">
            <button class="btn btn-secondary btn-sm" form="setTerm<?= (int) $t['id'] ?>">Set as current</button>
            <form method="post" id="setTerm<?= (int) $t['id'] ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="set_term"><input type="hidden" name="term_id" value="<?= (int) $t['id'] ?>"></form>
            <form method="post" class="inline" onsubmit="return confirm('Delete this term together with its subjects, sections and attendance records?')"><?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_term"><input type="hidden" name="term_id" value="<?= (int) $t['id'] ?>">
              <button class="icon-btn danger" title="Delete term"><?= icon('trash-2', 15) ?></button></form>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<section class="card profile-settings-card">
  <div class="card-head">
    <div><h3>Your account</h3><p class="muted small">Account details and session security</p></div>
    <a class="btn btn-soft btn-sm" href="<?= e(url('profile.php')) ?>"><?= icon('pencil', 14) ?> Edit profile</a>
  </div>
  <div class="account-preview">
    <?= avatar($account, 'lg') ?>
    <div><b><?= e($account['name']) ?></b><span><?= e($account['email']) ?></span></div>
    <span class="role-tag"><?= icon('shield-check', 13) ?> Professor<?php if (!empty($account['teacher_level'])): ?> · <?= e(ucfirst($account['teacher_level'])) ?><?php endif; ?></span>
  </div>
  <div class="settings-security">
    <span><?= icon('shield-check', 17) ?></span>
    <div><b>Protected professor workspace</b>
      <p>Your academic terms, class sections and attendance records are private to your account.</p></div>
  </div>
</section>


<section class="card manual-card">
  <div class="card-head">
    <div><h3><?= icon('book-open-check', 17) ?> Manual — how to use this website</h3>
      <p class="muted small">Step-by-step guide for professors and students: terms, classes, schedules, rosters, QR check-in, RFID readers and reports.</p></div>
    <span class="badge badge-blue">docs/manual.html</span>
  </div>
  <div class="manual-chapters">
    <span>1 · Set up a term</span><span>2 · Subjects &amp; sections</span><span>3 · Import students</span>
    <span>4 · Take attendance</span><span>5 · History &amp; export</span><span>6 · RFID readers</span>
    <span>7 · Student check-in</span><span>8 · Troubleshooting</span>
  </div>
  <div class="manual-actions">
    <a class="btn btn-primary" href="<?= e(url('docs/manual.html')) ?>" target="_blank" rel="noopener"><?= icon('eye', 16) ?> View manual</a>
    <a class="btn btn-secondary" href="<?= e(url('docs/manual.html')) ?>" download="QRAttendance-Manual.html"><?= icon('download', 16) ?> Download</a>
    <button class="btn btn-secondary" type="button" data-print-doc="docs/manual.html"><?= icon('printer', 16) ?> Print</button>
  </div>
  <p class="muted small">The manual is a single HTML file: it opens in any browser, downloads as <code>QRAttendance-Manual.html</code> and prints cleanly on paper or as a PDF (Print → Save as PDF).</p>
</section>


<div class="settings-signout">
  <div><b>Ready to wrap up?</b><p>Your work is saved securely to your account.</p></div>
  <a class="btn btn-secondary" href="<?= e(url('logout.php')) ?>"><?= icon('log-out', 16) ?> Sign out</a>
</div>

<dialog class="modal" id="termModal">
  <form method="post" class="modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="create_term">
    <h3>Add academic term</h3>
    <p class="muted small">Set the school year and semester you’re teaching right now.</p>
    <div class="grid-2">
      <div><label class="label">School year</label><input class="input" name="school_year" placeholder="2026-2027" required></div>
      <div><label class="label">Semester</label>
        <select class="input" name="semester"><option>1st Semester</option><option>2nd Semester</option><option>Summer</option></select></div>
    </div>
    <p class="note"><?= icon('history', 14) ?> Previous term history stays available when you change semesters.</p>
    <div class="modal-actions"><button type="button" class="btn btn-secondary" data-close>Cancel</button><button class="btn btn-primary"><?= icon('save', 15) ?> Save academic term</button></div>
  </form>
</dialog>
<?php layout_end("<script>(function(){document.querySelectorAll('[data-print-doc]').forEach(function(btn){btn.addEventListener('click',function(){var w=window.open(btn.getAttribute('data-print-doc'),'_blank');var tries=0;function go(){tries++;if(!w)return;try{w.focus();w.print();}catch(e){if(tries<20)setTimeout(go,250);}}setTimeout(go,600);});});})();</script>"); ?>