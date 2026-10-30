<?php
require __DIR__ . '/config.php';
require_once __DIR__ . '/attendance_modes.php';
require_role('professor');
close_expired_sessions();

$id = (int) ($_GET['id'] ?? 0);
$section = q("SELECT s.*, COALESCE(sub.code, 'SECTION') AS code,
    COALESCE(sub.name, 'Section attendance') AS subject_name, t.school_year, t.semester
    FROM sections s JOIN terms t ON t.id = s.term_id
    LEFT JOIN subjects sub ON sub.id = s.subject_id
    WHERE s.id = ? AND COALESCE(s.professor_id, t.professor_id) = ?", [$id, professor_id()])->fetch();
if (!$section) { http_response_code(404); exit('Section not found.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'update_schedules') {
            if ($section['section_mode'] === 'direct') throw new RuntimeException('Direct sections use the four attendance windows instead of weekly meeting schedules.');
            save_schedules($id, $_POST);
            flash('Meeting days and times updated.');
        } elseif ($action === 'update_times') {
            if ($section['section_mode'] === 'direct') save_direct_times($id, $_POST);
            else save_attendance_times($id, $_POST);
            flash('Time in and time out saved. Each window lasts 30 minutes.');
        } elseif ($action === 'import_csv') {
            if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) throw new RuntimeException('Choose a CSV file.');
            $fh = fopen($_FILES['csv']['tmp_name'], 'r');
            $added = 0; $updated = 0; $line = 0;
            $ins = db()->prepare('INSERT INTO students (section_id, student_number, name) VALUES (?,?,?)
                                  ON DUPLICATE KEY UPDATE name = VALUES(name)');
            while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
                $line++;
                $row = array_map(fn($v) => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $v)), $row);
                if ($line === 1 && preg_match('/id|number/i', $row[0] ?? '') && preg_match('/name/i', $row[1] ?? '')) continue;
                [$num, $name] = [$row[0] ?? '', $row[1] ?? ''];
                if ($num === '' || $name === '') continue;
                $ins->execute([$id, $num, $name]);
                $ins->rowCount() === 1 ? $added++ : $updated++;
            }
            fclose($fh);
            flash("Import complete: $added added, $updated updated.");
        } elseif ($action === 'save_student') {
            $num = trim((string) $_POST['student_number']);
            $name = trim((string) $_POST['name']);
            if ($num === '' || $name === '') throw new RuntimeException('Student ID and name are required.');
            if (!empty($_POST['student_id'])) {
                q('UPDATE students SET student_number = ?, name = ? WHERE id = ? AND section_id = ?', [$num, $name, (int) $_POST['student_id'], $id]);
                flash('Student updated.');
            } else {
                q('INSERT INTO students (section_id, student_number, name) VALUES (?,?,?)', [$id, $num, $name]);
                flash('Student added.');
            }
        } elseif ($action === 'delete_student') {
            q('DELETE FROM students WHERE id = ? AND section_id = ?', [(int) $_POST['student_id'], $id]);
            flash('Student removed.');
        } elseif ($action === 'start_session') {
            if ($section['section_mode'] === 'direct') throw new RuntimeException('Direct-section attendance opens automatically during its configured 30-minute windows.');
            q("UPDATE class_sessions SET status = 'closed' WHERE section_id = ? AND status = 'open'", [$id]);
            $title = trim((string) ($_POST['title'] ?? '')) ?: 'Class on ' . date('M j, Y');
            q('INSERT INTO class_sessions (section_id, title, started_at, expires_at) VALUES (?,?,?,?)',
              [$id, $title, now(), date('Y-m-d H:i:s', time() + SESSION_MINUTES * 60)]);
            redirect('scan.php?session=' . db()->lastInsertId());
        } elseif ($action === 'add_holiday') {
            $date = trim((string) ($_POST['holiday_date'] ?? ''));
            $name = trim((string) ($_POST['holiday_name'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
                throw new RuntimeException('Choose a valid holiday date.');
            }
            if (mb_strlen($name) > 120) $name = mb_substr($name, 0, 120);
            // INSERT IGNORE + unique(professor_id, date) makes this idempotent.
            q('INSERT IGNORE INTO holidays (professor_id, holiday_date, name) VALUES (?,?,?)', [professor_id(), $date, $name]);
            flash('Holiday added to the attendance calendar.');
        } elseif ($action === 'delete_holiday') {
            $holidayId = (int) ($_POST['holiday_id'] ?? 0);
            // Scoped to the professor so one teacher cannot delete another's holiday.
            q('DELETE FROM holidays WHERE id = ? AND professor_id = ?', [$holidayId, professor_id()]);
            flash('Holiday removed.');
        } elseif ($action === 'delete_section') {
            q('DELETE FROM sections WHERE id = ?', [$id]);
            flash('Section deleted.');
            redirect('classes.php');
        }
    } catch (PDOException $ex) {
        flash($ex->getCode() === '23000' ? 'That student ID already exists in this section.' : 'Database error. Please try again.', 'error');
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
    }
    redirect('section.php?id=' . $id);
}

$search = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$sql = 'SELECT st.*, u.avatar AS user_avatar, (SELECT COUNT(*) FROM attendance a WHERE a.student_id = st.id) AS attended
        FROM students st LEFT JOIN users u ON u.id = st.user_id WHERE st.section_id = ?';
$params = [$id];
if ($search !== '') { $sql .= ' AND (st.name LIKE ? OR st.student_number LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
$totalStudents = (int) q('SELECT COUNT(*) FROM students st WHERE st.section_id = ?' . ($search !== '' ? ' AND (st.name LIKE ? OR st.student_number LIKE ?)' : ''), $search !== '' ? [$id, "%$search%", "%$search%"] : [$id])->fetchColumn();
$totalPages = max(1, (int) ceil($totalStudents / 20));
$page = min($page, $totalPages);
$students = q($sql . ' ORDER BY st.name LIMIT 20 OFFSET ' . (($page - 1) * 20), $params)->fetchAll();
$sessions = q('SELECT cs.*, (SELECT COUNT(*) FROM attendance a WHERE a.session_id = cs.id) AS present FROM class_sessions cs WHERE section_id = ? ORDER BY started_at DESC LIMIT 20', [$id])->fetchAll();
$totalSessions = (int) q('SELECT COUNT(*) FROM class_sessions WHERE section_id = ?', [$id])->fetchColumn();
$enrolled = (int) q('SELECT COUNT(*) FROM students WHERE section_id = ? AND user_id IS NOT NULL', [$id])->fetchColumn();
$rosterCount = (int) q('SELECT COUNT(*) FROM students WHERE section_id = ?', [$id])->fetchColumn();
$schedules = schedule_rows($id);
$attTimes = q('SELECT * FROM section_attendance_times WHERE section_id = ?', [$id])->fetch() ?: null;
$recordMode = in_array($attTimes['attendance_mode'] ?? 'both', ['time_in','time_out','both'], true) ? ($attTimes['attendance_mode'] ?? 'both') : 'both';
$enabledDirectSlots = direct_slot_labels($recordMode);
$time = static fn(string $key) => ($attTimes && $attTimes[$key] ? date('H:i', strtotime($attTimes[$key])) : '');
$directSessions = $section['section_mode'] === 'direct' ? sync_direct_attendance_sessions($id) : [];

$holidays = q('SELECT id, holiday_date, name FROM holidays WHERE professor_id = ? ORDER BY holiday_date DESC LIMIT 100', [professor_id()])->fetchAll();
$scheme = IS_HTTPS ? 'https' : 'http';
$enrollUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url('student/enroll.php?code=' . $section['enroll_code']);

layout_start($section['code'] . ' · ' . $section['name'], 'classes');
?>
<a class="back" href="<?= e(url('classes.php')) ?>"><?= icon('arrow-left', 14) ?> Back to classes</a>
<div class="page-head">
  <div><span class="eyebrow"><?= e($section['school_year'] . ' · ' . $section['semester']) ?></span>
    <h2><?= e($section['code']) ?> · <?= e($section['name']) ?></h2>
    <p class="muted"><?= e($section['section_mode'] === 'direct' ? 'Direct section attendance · no subject' : $section['subject_name']) ?></p>
    <?php if ($section['section_mode'] === 'direct'): ?>
      <div class="schedule-list" aria-label="Direct attendance windows">
        <?php foreach ($enabledDirectSlots as $slot => $label): if (!empty($attTimes[$slot])): ?>
          <span class="schedule-chip"><b><?= e($label) ?></b> <?= icon('clock', 13) ?> <?= e(time_label($attTimes[$slot])) ?>
            <small>(30 min)</small></span>
        <?php endif; endforeach; ?>
        <?php if (!$attTimes || !array_filter(array_intersect_key($attTimes, $enabledDirectSlots))): ?>
          <span class="schedule-chip muted"><?= icon('clock', 14) ?> Set attendance windows</span>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="schedule-list" aria-label="Recorded time in and time out">
        <?php if ($attTimes && ($attTimes['am_in'] || $attTimes['pm_in'])): ?>
          <?php if ($attTimes['am_in']): ?><span class="schedule-chip"><b>Morning</b> <?= icon('log-in', 13) ?> <?= e(date('g:i A', strtotime($attTimes['am_in']))) ?> <?= icon('log-out', 13) ?> <?= e($attTimes['am_out'] ? date('g:i A', strtotime($attTimes['am_out'])) : '—') ?></span><?php endif; ?>
          <?php if ($attTimes['pm_in']): ?><span class="schedule-chip"><b>Afternoon</b> <?= icon('log-in', 13) ?> <?= e(date('g:i A', strtotime($attTimes['pm_in']))) ?> <?= icon('log-out', 13) ?> <?= e($attTimes['pm_out'] ? date('g:i A', strtotime($attTimes['pm_out'])) : '—') ?></span><?php endif; ?>
        <?php endif; ?>
      </div>
      <div class="schedule-list">
        <?php if (!$schedules): ?><span class="schedule-chip muted"><?= icon('clock', 14) ?> No meeting time set</span><?php endif; ?>
        <?php foreach ($schedules as $s): ?>
          <span class="schedule-chip"><b><?= e(day_label($s['day'])) ?></b> <?= icon('clock', 13) ?> <?= e(time_label($s['start_time'])) ?> – <?= e(time_label($s['end_time'])) ?><?php if ($s['room']): ?> <?= icon('map-pin', 13) ?> <?= e($s['room']) ?><?php endif; ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <div class="actions">
    <?php if ($section['section_mode'] === 'direct'): ?>
      <button class="btn btn-secondary" data-open="timesModal"><?= icon('clock', 15) ?> Attendance windows</button>
    <?php else: ?>
      <button class="btn btn-secondary" data-open="scheduleModal"><?= icon('calendar-days', 15) ?> Edit schedule</button>
      <button class="btn btn-secondary" data-open="timesModal"><?= icon('clock', 15) ?> Time in / out</button>
      <button class="btn btn-primary" data-open="sessionModal"><?= icon('qr-code', 17) ?> Take attendance</button>
    <?php endif; ?>
    <button class="btn btn-secondary" data-open="holidayModal"><?= icon('calendar-heart', 16) ?> Holidays</button>
    <button class="btn btn-secondary" data-open="enrollModal"><?= icon('qr-code', 16) ?> Enrollment QR</button>
  </div>
</div>

<?php if ($section['section_mode'] === 'direct'): ?>
<section class="card direct-window-card">
  <div class="card-head"><div><h3><?= icon('clock', 17) ?> 30-minute attendance windows</h3><p class="muted small">A scanner session opens automatically during each configured time.</p></div></div>
  <?php if (!$attTimes || !array_filter(array_intersect_key($attTimes, $enabledDirectSlots))): ?>
    <div class="empty"><div class="empty-icon"><?= icon('clock', 20) ?></div><h3>No attendance windows configured</h3>
      <p class="muted">Set morning and afternoon time in/out on this section to enable attendance.</p>
      <button class="btn btn-primary btn-sm" data-open="timesModal"><?= icon('clock', 15) ?> Set windows</button></div>
  <?php else: ?>
    <div class="direct-window-list">
      <?php foreach ($enabledDirectSlots as $slot => $label): if (!empty($attTimes[$slot])):
        $slotStart = strtotime(date('Y-m-d') . ' ' . $attTimes[$slot]);
        $slotEnd = $slotStart + DIRECT_ATTENDANCE_WINDOW_MINUTES * 60;
        $activeWindow = time() >= $slotStart && time() < $slotEnd;
        $slotSession = null;
        foreach ($directSessions as $ds) if ($ds['attendance_slot'] === $slot) { $slotSession = $ds; break; }
      ?>
        <div class="direct-window-row">
          <span class="direct-window-icon <?= $activeWindow ? 'active' : '' ?>"><?= icon(str_contains($slot, 'am_') ? 'sun' : 'moon', 17) ?></span>
          <span class="direct-window-copy"><b><?= e($label) ?></b><small><?= e(time_label($attTimes[$slot])) ?> – <?= e(date('g:i A', $slotEnd)) ?> today</small></span>
          <?php if ($slotSession && $activeWindow): ?>
            <span class="badge badge-green"><span class="dot-live"></span> Open</span>
            <a class="btn btn-primary btn-sm" href="<?= e(url('scan.php?session=' . $slotSession['id'])) ?>"><?= icon('camera', 14) ?> Open scanner</a>
          <?php elseif (time() < $slotStart): ?>
            <span class="badge">Opens <?= e(date('g:i A', $slotStart)) ?></span>
          <?php else: ?>
            <span class="badge">Window closed</span>
          <?php endif; ?>
        </div>
      <?php endif; endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<div class="stats stats-3">
  <div class="stat card"><span class="stat-icon blue"><?= icon('users', 19) ?></span><span class="stat-label">On roster</span><strong><?= $rosterCount ?></strong><small>imported students</small></div>
  <div class="stat card"><span class="stat-icon green"><?= icon('circle-check', 19) ?></span><span class="stat-label">Enrolled via QR</span><strong><?= $enrolled ?></strong><small>accounts linked</small></div>
  <div class="stat card"><span class="stat-icon violet"><?= icon('history', 19) ?></span><span class="stat-label">Sessions</span><strong><?= $totalSessions ?></strong><small>recorded</small></div>
</div>

<section class="card">
  <div class="card-head wrap">
    <h3>Class list</h3>
    <div class="actions">
      <form method="get" class="search"><input type="hidden" name="id" value="<?= $id ?>"><input class="input" type="search" name="q" value="<?= e($search) ?>" placeholder="Search name or ID"></form>
      <button class="btn btn-secondary btn-sm" data-open="importModal"><?= icon('file-up', 15) ?> Import CSV</button>
      <a class="btn btn-secondary btn-sm" href="<?= e(url('export.php?type=roster&id=' . $id)) ?>"><?= icon('download', 15) ?> Export CSV</a>
      <button class="btn btn-primary btn-sm" data-open="studentModal" data-student=""><?= icon('plus', 15) ?> Add student</button>
    </div>
  </div>
  <?php if (!$students): ?>
    <div class="empty"><div class="empty-icon"><?= icon('users', 23) ?></div><h3><?= $search ? 'No matches' : 'No students yet' ?></h3>
      <p class="muted">Import a CSV with columns <code>student_id, name</code>, or add students one by one.</p></div>
  <?php else: ?>
  <div class="student-card-grid">
    <?php foreach ($students as $st): ?>
    <article class="student-roster-card">
      <div class="student-card-heading"><span class="person"><?= avatar(['name' => $st['name'], 'avatar' => $st['user_avatar'] ?? null], 'sm') ?><button type="button" class="student-name-btn" data-student-id="<?= (int) $st['id'] ?>" data-student-name="<?= e($st['name']) ?>" title="View attendance calendar"><?= e($st['name']) ?><?= icon('calendar-days', 13) ?></button></span>
        <span class="student-card-actions"><button class="icon-btn" title="Edit student" data-open="studentModal" data-student="<?= (int) $st['id'] ?>" data-num="<?= e($st['student_number']) ?>" data-name="<?= e($st['name']) ?>"><?= icon('pencil', 15) ?></button>
        <form method="post" class="inline" onsubmit="return confirm('Remove this student from the section?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_student"><input type="hidden" name="student_id" value="<?= (int) $st['id'] ?>"><button class="icon-btn danger" title="Delete student"><?= icon('trash-2', 15) ?></button></form></span>
      </div>
      <div class="student-card-details"><span><small>Student ID</small><b><?= e($st['student_number']) ?></b></span><span><small>Enrollment</small><b><span class="badge <?= $st['user_id'] ? 'badge-green' : 'badge-amber' ?>"><?= $st['user_id'] ? 'Enrolled' : 'Pending' ?></span></b></span><span><small>Attendance</small><b><?= (int) $st['attended'] ?> / <?= $totalSessions ?></b></span></div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <nav class="pagination" aria-label="Class list pages">
    <?php $pageUrl = static fn(int $n): string => url('section.php?' . http_build_query(array_filter(['id' => $id, 'q' => $search ?: null, 'page' => $n > 1 ? $n : null]))); ?>
    <a class="page-control <?= $page <= 1 ? 'is-disabled' : '' ?>" href="<?= $page > 1 ? e($pageUrl($page - 1)) : '#' ?>" <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>‹ <span>Previous</span></a>
    <?php $shown = []; foreach ([1, $page - 1, $page, $page + 1, $totalPages] as $n) if ($n >= 1 && $n <= $totalPages) $shown[$n] = $n; ksort($shown); $last = 0; foreach ($shown as $n): ?>
      <?php if ($last && $n > $last + 1): ?><span class="page-ellipsis" aria-hidden="true">…</span><?php endif; ?>
      <a class="page-number <?= $n === $page ? 'is-current' : '' ?>" href="<?= e($pageUrl($n)) ?>" <?= $n === $page ? 'aria-current="page"' : '' ?>><?= $n ?></a>
    <?php $last = $n; endforeach; ?>
    <a class="page-control <?= $page >= $totalPages ? 'is-disabled' : '' ?>" href="<?= $page < $totalPages ? e($pageUrl($page + 1)) : '#' ?>" <?= $page >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>><span>Next</span> ›</a>
  </nav>
</section>

<section class="card">
  <div class="card-head"><h3>Recent sessions</h3></div>
  <?php if (!$sessions): ?><p class="muted small pad">No sessions yet. Click “Take attendance” to start one.</p><?php endif; ?>
  <?php foreach ($sessions as $s): ?>
    <div class="list-row">
      <div><b><?= e($s['title']) ?></b><small class="muted"><?= e(date('M j, Y g:i A', strtotime($s['started_at']))) ?> · <?= (int) $s['present'] ?> / <?= $rosterCount ?> present</small></div>
      <div class="actions">
        <span class="badge <?= $s['status'] === 'open' ? 'badge-green' : '' ?>"><?= $s['status'] === 'open' ? 'Live' : 'Closed' ?></span>
        <?php if ($s['status'] === 'open'): ?><a class="btn btn-primary btn-sm" href="<?= e(url('scan.php?session=' . $s['id'])) ?>"><?= icon('camera', 15) ?> Scan</a><?php endif; ?>
        <a class="btn btn-soft btn-sm" href="<?= e(url('export.php?type=session&id=' . $s['id'])) ?>"><?= icon('download', 15) ?> CSV</a>
      </div>
    </div>
  <?php endforeach; ?>
</section>

<form method="post" class="danger-zone" onsubmit="return confirm('Delete this section, its roster and all attendance records?')"><?= csrf_field() ?>
  <input type="hidden" name="action" value="delete_section"><button class="btn btn-danger-ghost btn-sm"><?= icon('trash-2', 15) ?> Delete section</button></form>

<dialog class="modal modal-wide" id="scheduleModal">
  <form method="post" class="modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="update_schedules">
    <h3>Meeting days &amp; times</h3>
    <p class="muted small">Add one row for every meeting — for example a lecture on Monday and a lab on Thursday.</p>
    <div class="schedule-rows" data-schedule-rows>
      <?php foreach (($schedules ?: [['day' => 'Mon', 'start_time' => '09:00', 'end_time' => '10:30', 'room' => '']]) as $s): ?>
        <div class="schedule-row">
          <select class="input" name="sched_day[]"><?php foreach (DAYS as $k => $full): ?><option value="<?= $k ?>" <?= ($s['day'] ?? '') === $k ? 'selected' : '' ?>><?= $full ?></option><?php endforeach; ?></select>
          <input class="input" type="time" name="sched_start[]" value="<?= e($s['start_time'] ?? '09:00') ?>" required>
          <input class="input" type="time" name="sched_end[]" value="<?= e($s['end_time'] ?? '10:30') ?>" required>
          <input class="input" name="sched_room[]" placeholder="Room" value="<?= e($s['room'] ?? '') ?>">
          <button type="button" class="icon-btn danger" data-schedule-remove title="Remove day"><?= icon('trash-2', 15) ?></button>
        </div>
      <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-soft btn-sm schedule-add" data-schedule-add><?= icon('calendar-plus', 15) ?><span data-schedule-label>Add another day</span></button>
    <div class="modal-actions"><button type="button" class="btn btn-secondary" data-close>Cancel</button><button class="btn btn-primary"><?= icon('save', 15) ?> Save schedule</button></div>
  </form>
  <template data-schedule-template>
    <div class="schedule-row">
      <select class="input" name="sched_day[]"><?php foreach (DAYS as $k => $full): ?><option value="<?= $k ?>"><?= $full ?></option><?php endforeach; ?></select>
      <input class="input" type="time" name="sched_start[]" value="09:00">
      <input class="input" type="time" name="sched_end[]" value="10:30">
      <input class="input" name="sched_room[]" placeholder="Room">
      <button type="button" class="icon-btn danger" data-schedule-remove title="Remove day"><?= icon('trash-2', 15) ?></button>
    </div>
  </template>
</dialog>

<dialog class="modal modal-wide" id="timesModal">
  <form method="post" class="modal-body" data-recording-mode-form><?= csrf_field() ?><input type="hidden" name="action" value="update_times">
    <?php if ($section['section_mode'] === 'direct'): ?>
      <?php /* $recordMode is loaded with the section above. */ ?>
      <h3>Choose what attendance to record</h3>
      <p class="muted small">Select time in, time out, or both. Each enabled time opens a 30-minute attendance window.</p>
      <div class="recording-mode-options" data-recording-mode-group>
        <?php foreach (direct_attendance_modes() as $modeValue => $modeLabel): ?>
          <label class="recording-mode-option <?= $recordMode === $modeValue ? 'selected' : '' ?>">
            <input type="radio" name="attendance_mode" value="<?= e($modeValue) ?>" data-recording-mode <?= $recordMode === $modeValue ? 'checked' : '' ?> required>
            <span class="recording-mode-check"><?= icon('check', 13) ?></span>
            <span><b><?= e($modeLabel) ?></b><small><?= e($modeValue === 'time_in' ? 'Morning in + afternoon in' : ($modeValue === 'time_out' ? 'Morning out + afternoon out' : 'All four daily windows')) ?></small></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="direct-time-grid" data-direct-time-grid>
        <?php foreach (direct_slot_labels() as $slot => $label): ?>
          <div class="time-slot" data-direct-slot="<?= e($slot) ?>">
            <span class="time-slot-title"><?= icon(str_contains($slot, 'am_') ? 'sun' : 'moon', 15) ?> <?= e($label) ?></span>
            <label class="label" for="edit_<?= e($slot) ?>">Window start</label>
            <input class="input" type="time" id="edit_<?= e($slot) ?>" name="<?= e($slot) ?>" value="<?= e($time($slot)) ?>">
            <small class="muted">Attendance remains open for 30 minutes.</small>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="note"><?= icon('clock', 14) ?> The selected 30-minute windows open automatically every day and record a separate check-in for each enabled event.</p>
    <?php else: ?>
      <h3>Record time in &amp; time out</h3>
      <p class="muted small">Set the expected arrival and dismissal for this subject section.</p>
      <div class="time-slots">
        <div class="time-slot"><span class="time-slot-title"><?= icon('sun', 15) ?> Morning</span>
          <div class="time-slot-grid"><div><label class="label" for="am_in">Time in</label><input class="input" type="time" id="am_in" name="am_in" value="<?= e($time('am_in')) ?>"></div>
          <div><label class="label" for="am_out">Time out</label><input class="input" type="time" id="am_out" name="am_out" value="<?= e($time('am_out')) ?>"></div></div>
        </div>
        <div class="time-slot"><span class="time-slot-title"><?= icon('moon', 15) ?> Afternoon</span>
          <div class="time-slot-grid"><div><label class="label" for="pm_in">Time in</label><input class="input" type="time" id="pm_in" name="pm_in" value="<?= e($time('pm_in')) ?>"></div>
          <div><label class="label" for="pm_out">Time out</label><input class="input" type="time" id="pm_out" name="pm_out" value="<?= e($time('pm_out')) ?>"></div></div>
        </div>
      </div>
      <p class="note"><?= icon('clock', 14) ?> These times are shown with the section for reference.</p>
    <?php endif; ?>
    <div class="modal-actions"><button type="button" class="btn btn-secondary" data-close>Cancel</button><button class="btn btn-primary"><?= icon('save', 15) ?> Save times</button></div>
  </form>
</dialog>

<dialog class="modal" id="holidayModal">
  <form method="post" class="modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="add_holiday">
    <h3>School holidays</h3>
    <p class="muted small">Nationwide Philippine regular and special non-working holidays are added to the calendar automatically. Use this form for local or school-specific holidays.</p>
    <p class="muted small">Holiday dates show <b>yellow</b> on every student's attendance calendar and are never counted as absences.</p>
    <div class="grid-2">
      <div><label class="label" for="holiday_date">Date</label><input class="input" type="date" id="holiday_date" name="holiday_date" required></div>
      <div><label class="label" for="holiday_name">Name <span class="optional-label">Optional</span></label><input class="input" id="holiday_name" name="holiday_name" placeholder="e.g. Independence Day" maxlength="120"></div>
    </div>
    <div class="modal-actions"><button type="button" class="btn btn-secondary" data-close>Done</button><button class="btn btn-primary"><?= icon('plus', 15) ?> Add holiday</button></div>
  </form>
  <?php if ($holidays): ?>
  <div class="holiday-list-wrap">
    <div class="holiday-list-heading"><b>Saved holidays</b><span><?= count($holidays) ?> total</span></div>
    <div class="holiday-list">
      <?php foreach ($holidays as $holiday): ?>
        <div class="holiday-row">
          <span class="holiday-dot" aria-hidden="true"></span>
          <span class="holiday-copy"><b><?= e(date('M j, Y', strtotime($holiday['holiday_date']))) ?></b><small><?= e($holiday['name'] ?: 'Holiday') ?></small></span>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete_holiday"><input type="hidden" name="holiday_id" value="<?= (int) $holiday['id'] ?>"><button class="icon-btn danger" title="Remove holiday"><?= icon('trash-2', 15) ?></button></form>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</dialog>

<dialog class="modal modal-wide" id="calendarModal">
  <div class="modal-body calendar-modal-body">
    <div class="calendar-modal-head">
      <div class="calendar-student">
        <span class="calendar-student-avatar" id="calStudentAvatar">—</span>
        <span class="calendar-student-copy"><b id="calStudentName">Student</b><small id="calStudentMeta">Attendance record</small></span>
      </div>
      <div class="calendar-legend">
        <span><i class="legend-dot legend-present" />Present</span>
        <span><i class="legend-dot legend-absent" />Absent</span>
        <span><i class="legend-dot legend-holiday" />Holiday</span>
      </div>
    </div>
    <div class="calendar-nav">
      <button type="button" class="icon-btn" id="calPrev" aria-label="Previous month"><?= icon('chevron-left', 18) ?></button>
      <strong id="calMonthLabel">Month</strong>
      <button type="button" class="icon-btn" id="calNext" aria-label="Next month"><?= icon('chevron-right', 18) ?></button>
    </div>
    <div class="calendar-weekdays" aria-hidden="true"><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span></div>
    <div class="calendar-grid" id="calendarGrid"></div>
    <div class="calendar-summary" id="calendarSummary"></div>
    <div class="modal-actions"><button type="button" class="btn btn-secondary" data-close>Close</button></div>
  </div>
</dialog>

<dialog class="modal" id="enrollModal">
  <div class="modal-body center">
    <h3>Enrollment QR code</h3>
    <p class="muted small">Students scan this, sign in, and enter their student ID. Only students on the class list can enroll.</p>
    <div class="qr-frame"><img src="<?= e(qr_data_uri($enrollUrl)) ?>" alt="Enrollment QR"></div>
    <div class="code-pill"><?= e($section['enroll_code']) ?></div>
    <input class="input small" readonly value="<?= e($enrollUrl) ?>" onclick="this.select()">
    <div class="modal-actions"><button type="button" class="btn btn-secondary" onclick="window.print()"><?= icon('download', 15) ?> Print</button><button type="button" class="btn btn-primary" data-close>Done</button></div>
  </div>
</dialog>

<dialog class="modal" id="sessionModal">
  <form method="post" class="modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="start_session">
    <h3>Start attendance</h3>
    <label class="label">Session title (optional)</label>
    <input class="input" name="title" placeholder="Week 5 – Lecture">
    <p class="note">The session stays open for <?= SESSION_MINUTES ?> minutes. Each student QR expires after <?= QR_TTL_SECONDS ?> seconds and can only be used once.</p>
    <div class="modal-actions"><button type="button" class="btn btn-secondary" data-close>Cancel</button><button class="btn btn-primary"><?= icon('qr-code', 15) ?> Start &amp; open scanner</button></div>
  </form>
</dialog>

<dialog class="modal" id="importModal">
  <form method="post" enctype="multipart/form-data" class="modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="import_csv">
    <h3>Import class list</h3>
    <p class="muted small">CSV columns: <code>student_id, name</code> (header row optional). Existing IDs are updated, not duplicated.</p>
    <label class="dropzone"><input type="file" name="csv" accept=".csv,text/csv" required><span class="upload-icon"><?= icon('file-up', 21) ?></span><span>Choose CSV file</span></label>
    <div class="modal-actions"><button type="button" class="btn btn-secondary" data-close>Cancel</button><button class="btn btn-primary"><?= icon('upload', 15) ?> Import</button></div>
  </form>
</dialog>

<dialog class="modal" id="studentModal">
  <form method="post" class="modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="save_student">
    <input type="hidden" name="student_id" id="fStudentId">
    <h3 id="studentModalTitle">Add student</h3>
    <div class="grid-2">
      <div><label class="label">Student ID</label><input class="input" name="student_number" id="fNum" required></div>
      <div><label class="label">Full name</label><input class="input" name="name" id="fName" required></div>
    </div>
    <div class="modal-actions"><button type="button" class="btn btn-secondary" data-close>Cancel</button><button class="btn btn-primary"><?= icon('save', 15) ?> Save</button></div>
  </form>
</dialog>

<script>
(function () {
  var MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  var WEEK = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
  var state = { year: 0, month: 0, data: null, student: '' };
  var grid = document.getElementById('calendarGrid');
  var label = document.getElementById('calMonthLabel');
  var summary = document.getElementById('calendarSummary');
  if (!grid || !label) return;

  function iso(d) {
    var m = d.getMonth() + 1, day = d.getDate();
    return d.getFullYear() + '-' + (m < 10 ? '0' + m : m) + '-' + (day < 10 ? '0' + day : day);
  }

  function escapeHtml(value) { return String(value).replace(/[&<>\"']/g, function (ch) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#39;'})[ch]; }); }

  function render() {
    if (!state.data) return;
    var first = new Date(state.year, state.month, 1);
    var daysInMonth = new Date(state.year, state.month + 1, 0).getDate();
    // Monday-first offset
    var offset = (first.getDay() + 6) % 7;
    label.textContent = MONTHS[state.month] + ' ' + state.year;

    var present = {}, absent = {}, holiday = {};
    state.data.present.forEach(function (d) { present[d] = true; });
    state.data.sessions.forEach(function (d) { if (!present[d]) absent[d] = true; });
    state.data.holidays.forEach(function (h) { holiday[h.date] = h.name || 'Holiday'; });

    var html = '<table class="calendar-table" role="grid" aria-label="' + MONTHS[state.month] + ' ' + state.year + '"><tbody>';
    var cells = Math.ceil((offset + daysInMonth) / 7) * 7;
    var presentCount = 0, absentCount = 0, holidayCount = 0;
    var todayIso = iso(new Date());

    for (var i = 0; i < cells; i++) {
      if (i % 7 === 0) html += '<tr>';
      var dayNum = i - offset + 1;
      if (dayNum < 1 || dayNum > daysInMonth) { html += '<td><span class="calendar-cell calendar-cell-empty" aria-hidden="true"></span></td>'; if (i % 7 === 6) html += '</tr>'; continue; }
      var d = new Date(state.year, state.month, dayNum);
      var key = iso(d);
      var cls = 'calendar-cell';
      var note = '';
      if (holiday[key]) { cls += ' is-holiday'; holidayCount++; note = holiday[key]; }
      else if (present[key]) { cls += ' is-present'; presentCount++; note = 'Present'; }
      else if (absent[key]) { cls += ' is-absent'; absentCount++; note = 'Absent'; }
      if (key === todayIso) cls += ' is-today';
      html += '<td><span class="calendar-cell ' + cls.replace('calendar-cell', '').trim() + '" role="gridcell" aria-label="' + key + (note ? ' · ' + escapeHtml(note) : '') + '" title="' + key + (note ? ' · ' + escapeHtml(note) : '') + '"><b>' + dayNum + '</b>' + (note ? '<i>' + escapeHtml(note) + '</i>' : '') + '</span></td>';
      if (i % 7 === 6) html += '</tr>';
    }
    html += '</tbody></table>';
    grid.innerHTML = html;

    var total = presentCount + absentCount;
    var rate = total ? Math.round(presentCount / total * 100) : 0;
    summary.innerHTML =
      '<span class="cal-summary-item"><i class="legend-dot legend-present"></i><b>' + presentCount + '</b> present</span>' +
      '<span class="cal-summary-item"><i class="legend-dot legend-absent"></i><b>' + absentCount + '</b> absent</span>' +
      '<span class="cal-summary-item"><i class="legend-dot legend-holiday"></i><b>' + holidayCount + '</b> holiday' + (holidayCount === 1 ? '' : 's') + '</span>' +
      '<span class="cal-summary-rate">' + rate + '% attendance</span>';
  }

  document.getElementById('calPrev').addEventListener('click', function () {
    state.month--; if (state.month < 0) { state.month = 11; state.year--; } render();
  });
  document.getElementById('calNext').addEventListener('click', function () {
    state.month++; if (state.month > 11) { state.month = 0; state.year++; } render();
  });

  document.querySelectorAll('.student-name-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.getAttribute('data-student-id');
      var name = btn.getAttribute('data-student-name') || 'Student';
      document.getElementById('calStudentName').textContent = name;
      document.getElementById('calStudentAvatar').textContent = name.split(/\s+/).filter(Boolean).slice(0, 2).map(function (p) { return p.charAt(0); }).join('').toUpperCase();
      document.getElementById('calStudentMeta').textContent = 'Attendance record';
      grid.innerHTML = '<div class="calendar-loading">Loading attendance…</div>';
      summary.innerHTML = '';
      var dlg = document.getElementById('calendarModal');
      if (dlg) dlg.showModal();
      fetch('<?= e(url('api/attendance_calendar.php')) ?>?section=<?= (int) $id ?>&student=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.ok) { grid.innerHTML = '<div class="calendar-loading">' + (data.error || 'Could not load attendance.') + '</div>'; return; }
          state.data = data;
          var now = new Date();
          state.year = now.getFullYear();
          state.month = now.getMonth();
          document.getElementById('calStudentMeta').textContent = data.section ? ('Section ' + data.section + ' · ' + (data.student ? data.student.student_number : '')) : '';
          render();
        })
        .catch(function () { grid.innerHTML = '<div class="calendar-loading">Could not load attendance. Please try again.</div>'; });
    });
  });
})();
</script>

<?php layout_end(); ?>
