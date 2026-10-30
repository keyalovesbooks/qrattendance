<?php
require __DIR__ . '/config.php';
require_once __DIR__ . '/attendance_modes.php';
$user = require_role('professor');
close_expired_sessions();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create_subject') {
            if ($user['teacher_level'] === 'primary' && ($user['primary_attendance_mode'] ?? '') !== 'subject') throw new RuntimeException('Your workspace is set to record by section. Change the attendance mode in Workspace settings to create subjects.');
            $term = current_term() ?? throw new RuntimeException('Create a school year and semester first.');
            $code = strtoupper(trim((string) $_POST['code']));
            $name = trim((string) $_POST['name']);
            if ($code === '' || $name === '') throw new RuntimeException('Enter a subject code and name.');
            q('INSERT INTO subjects (term_id, code, name) VALUES (?,?,?)', [$term['id'], $code, $name]);
            flash("Subject $code created. Now add a section.");
        } elseif ($action === 'delete_subject') {
            $subjectId = (int) $_POST['subject_id'];
            if (!owns_subject($subjectId)) throw new RuntimeException('That subject does not belong to your workspace.');
            q('DELETE FROM subjects WHERE id = ?', [$subjectId]);
            flash('Subject deleted.');
        } elseif ($action === 'create_section') {
            if ($user['teacher_level'] === 'primary' && ($user['primary_attendance_mode'] ?? '') !== 'subject') throw new RuntimeException('Your workspace is set to record by section.');
            $name = trim((string) $_POST['name']);
            $subjectId = (int) ($_POST['subject_id'] ?? 0);
            if ($name === '') throw new RuntimeException('Enter a section name.');
            if (!owns_subject($subjectId)) throw new RuntimeException('That subject does not belong to your workspace.');
            $rows = parse_schedules($_POST);
            if ($conflict = schedule_conflict($subjectId, 0, $rows)) throw new RuntimeException($conflict);
            $termId = (int) q('SELECT term_id FROM subjects WHERE id = ?', [$subjectId])->fetchColumn();
            q('INSERT INTO sections (subject_id, term_id, professor_id, section_mode, name, schedule, room, enroll_code)
               VALUES (?,?,?,?,?,?,?,?)', [$subjectId, $termId, professor_id(), 'subject', $name, '', '', strtoupper(bin2hex(random_bytes(8)))]);
            $secId = (int) db()->lastInsertId();
            save_schedules($secId, $_POST);
            flash('Section created with its meeting schedule.');
            redirect('section.php?id=' . $secId);
        } elseif ($action === 'create_direct_section') {
            $term = current_term();
            $name = trim((string) ($_POST['name'] ?? ''));
            if (!$term) throw new RuntimeException('Create an academic term first.');
            if ($user['teacher_level'] !== 'primary' || ($user['primary_attendance_mode'] ?? '') !== 'direct') throw new RuntimeException('Select Record by section in your workspace attendance settings before creating a direct section.');
            if ($name === '') throw new RuntimeException('Enter a section name.');
            db()->beginTransaction();
            try {
                q('INSERT INTO sections (subject_id, term_id, professor_id, section_mode, name, schedule, room, enroll_code)
                   VALUES (NULL,?,?,?,?,?,?,?)', [(int) $term['id'], professor_id(), 'direct', $name, '', '', strtoupper(bin2hex(random_bytes(8)))]);
                $secId = (int) db()->lastInsertId();
                save_direct_times($secId, $_POST);
                db()->commit();
            } catch (Throwable $ex) {
                if (db()->inTransaction()) db()->rollBack();
                throw $ex;
            }
            flash('Direct attendance section created. Add students, then share its enrollment QR.');
            redirect('section.php?id=' . $secId);
        } elseif ($action === 'delete_section') {
            $secId = (int) $_POST['section_id'];
            if (!owns_section($secId)) throw new RuntimeException('That section does not belong to your workspace.');
            q('DELETE FROM sections WHERE id = ?', [$secId]);
            flash('Section deleted.');
        }
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
    } catch (PDOException) {
        flash('Database error. Please try again.', 'error');
    }
    redirect('classes.php');
}

$term = current_term();
if ($user['teacher_level'] === 'primary' && empty($user['primary_attendance_mode'])) redirect('dashboard.php');
$flow = $user['teacher_level'] === 'primary'
    ? (($user['primary_attendance_mode'] ?? '') === 'direct' ? 'direct' : 'subject')
    : 'subject';
$subjects = $term ? q('SELECT * FROM subjects WHERE term_id = ? ORDER BY code', [$term['id']])->fetchAll() : [];
$sections = $term ? q("SELECT s.*, (SELECT COUNT(*) FROM students st WHERE st.section_id = s.id) AS student_count
    FROM sections s JOIN subjects sub ON sub.id = s.subject_id WHERE s.term_id = ? AND s.section_mode = 'subject' ORDER BY sub.code, s.name", [$term['id']])->fetchAll() : [];
$directSections = ($term && $user['teacher_level'] === 'primary') ? direct_sections_for_professor((int) $term['id']) : [];
$directTimes = [];
foreach ($directSections as $direct) {
    sync_direct_attendance_sessions((int) $direct['id']);
    $directTimes[$direct['id']] = q('SELECT * FROM section_attendance_times WHERE section_id = ?', [(int) $direct['id']])->fetch() ?: [];
}
$bySubject = [];
foreach ($sections as $s) $bySubject[$s['subject_id']][] = $s;
$sched = schedules_by_section(array_column($sections, 'id'));

layout_start('Classes & sections', 'classes');
?>
<div class="page-head">
  <div><span class="eyebrow"><?= icon('book-open', 14) ?> Teaching space</span><h2>Classes &amp; sections</h2>
    <p class="muted"><?= $term ? e($term['school_year'] . ' · ' . $term['semester']) : 'Set the school year and semester in Settings first.' ?></p></div>
  <div class="actions">
    <?= term_selector('term-control') ?>
    <?php if ($term && $user['teacher_level'] !== 'primary'): ?><button class="btn btn-primary" data-open="subjectModal"><?= icon('plus', 17) ?> Add subject</button><?php endif; ?>
  </div>
</div>

<?php if ($user['teacher_level'] === 'primary' && $flow === 'direct'): ?>
<section class="card" id="direct-sections">
  <div class="card-head"><div><h3><?= icon('users', 17) ?> Sections</h3><p class="muted small">No subject needed. Each configured time opens attendance for 30 minutes.</p></div>
    <button class="btn btn-primary btn-sm" data-open="directSectionModal"><?= icon('plus', 15) ?> Create section</button></div>
  <?php if (!$directSections): ?><div class="empty"><div class="empty-icon"><?= icon('users', 20) ?></div><h3>No direct sections yet</h3>
    <p class="muted">Create a section, import its class list, then set morning and afternoon attendance windows.</p></div><?php endif; ?>
  <?php foreach ($directSections as $direct): $times = $directTimes[$direct['id']] ?? []; ?>
    <div class="list-row direct-section-row">
      <div><b><?= e($direct['name']) ?></b><small class="muted">Section attendance · <?= (int) $direct['student_count'] ?> students</small>
        <span class="direct-window-chips">
          <?php $directMode = in_array($times['attendance_mode'] ?? 'both', ['time_in','time_out','both'], true) ? ($times['attendance_mode'] ?? 'both') : 'both'; ?>
          <?php foreach (direct_slot_labels($directMode) as $slot => $label): if (!empty($times[$slot])): ?>
            <span class="badge"><?= e($label) ?> <?= e(time_label($times[$slot])) ?>–<?= e(date('g:i A', strtotime($times[$slot]) + DIRECT_ATTENDANCE_WINDOW_MINUTES * 60)) ?></span>
          <?php endif; endforeach; ?>
          <?php if (!$times || !array_filter(array_intersect_key($times, direct_slot_labels($directMode)))): ?><span class="badge badge-amber">No time windows set</span><?php endif; ?>
        </span>
      </div>
      <div class="actions"><a class="btn btn-secondary btn-sm" href="<?= e(url('section.php?id=' . $direct['id'])) ?>">Open section</a>
        <?php foreach (sync_direct_attendance_sessions((int) $direct['id']) as $slotSession): ?>
          <a class="btn btn-primary btn-sm" href="<?= e(url('scan.php?session=' . $slotSession['id'])) ?>"><?= icon('camera', 14) ?> Scan <?= e($slotSession['title']) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</section>

<dialog class="modal modal-wide" id="directSectionModal">
  <form method="post" class="modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="create_direct_section">
    <h3>Create a section</h3><p class="muted small">This section is not grouped under a subject. Each enabled time opens a 30-minute attendance window.</p>
    <label class="label">Section name</label><input class="input" name="name" placeholder="Grade 3 · Section A" required>
    <span class="label">What should be recorded?</span>
    <div class="recording-mode-options" data-recording-mode-group>
      <?php foreach (direct_attendance_modes() as $modeValue => $modeLabel): ?>
        <label class="recording-mode-option <?= $modeValue === 'both' ? 'selected' : '' ?>">
          <input type="radio" name="attendance_mode" value="<?= e($modeValue) ?>" data-recording-mode <?= $modeValue === 'both' ? 'checked' : '' ?> required>
          <span class="recording-mode-check"><?= icon('check', 13) ?></span>
          <span><b><?= e($modeLabel) ?></b><small><?= e($modeValue === 'time_in' ? 'Morning in + afternoon in' : ($modeValue === 'time_out' ? 'Morning out + afternoon out' : 'All four daily windows')) ?></small></span>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="direct-time-grid" data-direct-time-grid>
      <?php foreach (direct_slot_labels() as $slot => $label): ?>
        <div class="time-slot" data-direct-slot="<?= e($slot) ?>"><span class="time-slot-title"><?= icon(str_contains($slot, 'am_') ? 'sun' : 'moon', 15) ?> <?= e($label) ?></span>
          <label class="label" for="direct_<?= e($slot) ?>">Window start</label>
          <input class="input" type="time" id="direct_<?= e($slot) ?>" name="<?= e($slot) ?>">
          <small class="muted">Attendance opens for 30 minutes.</small>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="modal-actions"><button type="button" class="btn btn-secondary" data-close>Cancel</button><button class="btn btn-primary"><?= icon('plus', 15) ?> Create direct section</button></div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($user['teacher_level'] !== 'primary' || $flow === 'subject'): ?>
<?php if (!$subjects): ?>
  <section class="card empty"><div class="empty-icon"><?= icon('book-open', 23) ?></div><h3>Create your first subject</h3>
    <p class="muted">Add a subject, then give it sections with their own meeting days and times.</p>
    <button class="btn btn-primary" data-open="subjectModal"><?= icon('plus', 16) ?> Add subject</button></section>
<?php else: ?>
  <?php if ($user['teacher_level'] === 'primary'): ?><div class="subject-add-row"><button class="btn btn-primary" data-open="subjectModal"><?= icon('plus', 16) ?> Add subject</button></div><?php endif; ?>
<?php endif; ?>

<div class="subject-grid">
<?php foreach ($subjects as $sub): ?>
  <section class="card subject-card">
    <div class="card-head">
      <div><span class="badge badge-blue"><?= e($sub['code']) ?></span><h3><?= e($sub['name']) ?></h3></div>
      <form method="post" onsubmit="return confirm('Delete this subject and all its sections?')"><?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_subject"><input type="hidden" name="subject_id" value="<?= (int) $sub['id'] ?>">
        <button class="icon-btn danger" title="Delete subject"><?= icon('trash-2', 15) ?></button></form>
    </div>
    <?php foreach ($bySubject[$sub['id']] ?? [] as $sec): ?>
      <a class="section-row" href="<?= e(url('section.php?id=' . $sec['id'])) ?>">
        <span class="section-avatar"><?= icon('book-open', 17) ?></span>
        <span class="section-info">
          <b><?= e($sec['name']) ?></b>
          <small><?= e(schedule_text($sched[$sec['id']] ?? [])) ?></small>
        </span>
        <span class="badge"><?= (int) $sec['student_count'] ?> students</span>
      </a>
    <?php endforeach; ?>
    <?php if (empty($bySubject[$sub['id']])): ?><p class="muted small">No sections yet.</p><?php endif; ?>
    <button class="btn btn-soft btn-sm" data-open="sectionModal" data-subject="<?= (int) $sub['id'] ?>" data-subject-name="<?= e($sub['code']) ?>"><?= icon('plus', 14) ?> Add section</button>
  </section>
<?php endforeach; ?>
</div>
<?php endif; ?>

<dialog class="modal" id="subjectModal">
  <form method="post" class="modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="create_subject">
    <h3>New subject</h3>
    <div class="grid-2">
      <div><label class="label">Code</label><input class="input" name="code" placeholder="IT 101" required></div>
      <div><label class="label">Subject name</label><input class="input" name="name" placeholder="Intro to Computing" required></div>
    </div>
    <div class="modal-actions"><button type="button" class="btn btn-secondary" data-close>Cancel</button><button class="btn btn-primary"><?= icon('plus', 15) ?> Create subject</button></div>
  </form>
</dialog>

<dialog class="modal modal-wide" id="sectionModal">
  <form method="post" class="modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="create_section">
    <input type="hidden" name="subject_id" id="sectionSubjectId">
    <h3>New section <span class="muted" id="sectionSubjectName"></span></h3>
    <label class="label">Section name</label><input class="input" name="name" placeholder="BSIT 1-A" required>
    <div class="schedule-head"><label class="label">Meeting days &amp; times</label><span class="muted small">Add one row for every meeting</span></div>
    <div class="schedule-rows" data-schedule-rows>
      <div class="schedule-row">
        <select class="input" name="sched_day[]"><?php foreach (DAYS as $k => $full): ?><option value="<?= $k ?>" <?= $k === 'Mon' ? 'selected' : '' ?>><?= $full ?></option><?php endforeach; ?></select>
        <input class="input" type="time" name="sched_start[]" value="09:00" required>
        <input class="input" type="time" name="sched_end[]" value="10:30" required>
        <input class="input" name="sched_room[]" placeholder="Room">
        <button type="button" class="icon-btn danger" data-schedule-remove title="Remove day"><?= icon('trash-2', 15) ?></button>
      </div>
    </div>
    <template data-schedule-template>
      <div class="schedule-row">
        <select class="input" name="sched_day[]"><?php foreach (DAYS as $k => $full): ?><option value="<?= $k ?>"><?= $full ?></option><?php endforeach; ?></select>
        <input class="input" type="time" name="sched_start[]" value="09:00" required>
        <input class="input" type="time" name="sched_end[]" value="10:30" required>
        <input class="input" name="sched_room[]" placeholder="Room">
        <button type="button" class="icon-btn danger" data-schedule-remove title="Remove day"><?= icon('trash-2', 15) ?></button>
      </div>
    </template>
    <button type="button" class="btn btn-soft btn-sm schedule-add" data-schedule-add><?= icon('calendar-plus', 15) ?><span data-schedule-label>Add another day</span></button>
    <div class="modal-actions"><button type="button" class="btn btn-secondary" data-close>Cancel</button><button class="btn btn-primary"><?= icon('plus', 15) ?> Create section</button></div>
  </form>
</dialog>
<?php layout_end(); ?>
