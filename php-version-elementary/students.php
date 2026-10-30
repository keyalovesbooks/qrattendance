<?php
require __DIR__ . '/config.php';
$user = require_role('professor');
close_expired_sessions();

$term = current_term();
$sections = $term ? q("SELECT s.id, s.name, s.enroll_code, COALESCE(sub.code, 'SECTION') AS code,
        COALESCE(sub.name, 'Direct section attendance') AS subject_name,
        (SELECT COUNT(*) FROM students st WHERE st.section_id = s.id) AS total
    FROM sections s LEFT JOIN subjects sub ON sub.id = s.subject_id
    WHERE s.term_id = ? AND s.professor_id = ? ORDER BY COALESCE(sub.code,'SECTION'), s.name", [$term['id'], professor_id()])->fetchAll() : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $secId = (int) ($_POST['section_id'] ?? 0);
    $mine = $term && owns_section($secId) && (bool) q('SELECT 1 FROM sections WHERE id = ? AND term_id = ?', [$secId, $term['id']])->fetchColumn();
    try {
        if (!$mine) throw new RuntimeException('Choose one of your sections first.');
        if ($action === 'import_csv') {
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
                $ins->execute([$secId, $num, $name]);
                $ins->rowCount() === 1 ? $added++ : $updated++;
            }
            fclose($fh);
            flash("Import complete: $added added, $updated updated.");
        } elseif ($action === 'save_student') {
            $num = trim((string) $_POST['student_number']);
            $name = trim((string) $_POST['name']);
            if ($num === '' || $name === '') throw new RuntimeException('Student ID and name are required.');
            if (!empty($_POST['student_id'])) {
                q('UPDATE students SET student_number = ?, name = ? WHERE id = ? AND section_id = ?', [$num, $name, (int) $_POST['student_id'], $secId]);
                flash('Student updated.');
            } else {
                q('INSERT INTO students (section_id, student_number, name, enrolled_at) VALUES (?,?,?,?)', [$secId, $num, $name, now()]);
                flash('Student added to the roster.');
            }
        } elseif ($action === 'delete_student') {
            q('DELETE FROM students WHERE id = ? AND section_id = ?', [(int) $_POST['student_id'], $secId]);
            flash('Student removed.');
        }
    } catch (RuntimeException $ex) { flash($ex->getMessage(), 'error'); }
    catch (PDOException $ex) { flash($ex->getCode() === '23000' ? 'That student ID already exists in this section.' : 'Database error.', 'error'); }
    redirect('students.php');
}

$filterSection = (int) ($_GET['section'] ?? 0);
$search = trim((string) ($_GET['q'] ?? ''));
$sql = "SELECT st.*, s.name AS section_name, COALESCE(sub.code,'SECTION') AS code,
        COALESCE(sub.name,'Direct section attendance') AS subject_name, u.avatar AS user_avatar
        FROM students st JOIN sections s ON s.id = st.section_id
        LEFT JOIN subjects sub ON sub.id = s.subject_id
        LEFT JOIN users u ON u.id = st.user_id
        WHERE s.term_id = ? AND s.professor_id = ?";
$params = [$term['id'] ?? 0, professor_id()];
if ($filterSection) { $sql .= ' AND st.section_id = ?'; $params[] = $filterSection; }
if ($search !== '') { $sql .= ' AND (st.name LIKE ? OR st.student_number LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
$totalStudents = (int) q('SELECT COUNT(*) FROM (' . $sql . ') roster_rows', $params)->fetchColumn();
$totalPages = max(1, (int) ceil($totalStudents / 20));
$page = min($totalPages, max(1, (int) ($_GET['page'] ?? 1)));
$students = q($sql . ' ORDER BY st.name LIMIT 20 OFFSET ' . (($page - 1) * 20), $params)->fetchAll();

$selected = null;
foreach ($sections as $s) if ((int) $s['id'] === $filterSection) $selected = $s;
if (!$selected && $sections && $filterSection === 0 && count($sections) === 1) $selected = $sections[0];
$inviteSection = $selected ?? $sections[0] ?? null;
$scheme = IS_HTTPS ? 'https' : 'http';
$enrollUrl = $inviteSection
    ? $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url('student/enroll.php?code=' . $inviteSection['enroll_code'])
    : '';

layout_start('Students roster', 'students');
?>
<div class="page-head">
  <div><span class="eyebrow"><?= icon('users', 14) ?> Your class community</span><h2>Student roster</h2>
    <p class="muted">Keep student details up to date and attendance ready.</p></div>
  <div class="actions">
    <button class="btn btn-secondary" data-open="importModal" <?= $sections ? '' : 'disabled' ?>><?= icon('file-up', 16) ?> Import CSV</button>
    <button class="btn btn-primary" data-open="studentModal" data-student="" <?= $sections ? '' : 'disabled' ?>><?= icon('plus', 17) ?> Add student</button>
  </div>
</div>

<section class="card roster-card">
  <div class="roster-toolbar">
    <div class="roster-context">
      <form method="get" class="section-selector-wrap">
        <?= icon('book-open', 16) ?>
        <select class="section-select" name="section" onchange="this.form.submit()" aria-label="Filter by section">
          <option value="0">All sections</option>
          <?php foreach ($sections as $s): ?>
            <option value="<?= (int) $s['id'] ?>" <?= $filterSection === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['code'] . ' · ' . $s['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?= icon('chevron-down', 15) ?>
        <input type="hidden" name="q" value="<?= e($search) ?>">
      </form>
      <span class="roster-count"><b><?= $totalStudents ?></b> student<?= $totalStudents === 1 ? '' : 's' ?></span>
    </div>
    <div class="roster-actions">
      <form method="get" class="table-search">
        <input type="hidden" name="section" value="<?= $filterSection ?>">
        <?= icon('search', 15) ?>
        <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search students">
      </form>
      <?php if ($filterSection): ?>
        <a class="btn btn-secondary btn-sm" href="<?= e(url('export.php?type=roster&id=' . $filterSection)) ?>"><?= icon('download', 15) ?> Export</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$students): ?>
    <div class="empty"><div class="empty-icon"><?= icon('search', 19) ?></div>
      <h3><?= ($search || $filterSection) ? 'No students found' : 'Your roster is empty' ?></h3>
      <p class="muted"><?= $search ? 'Try a different search or clear your filter.' : 'Add students manually or import your class list from a CSV.' ?></p>
      <?php if (!$search && $sections): ?>
        <div class="empty-state-actions">
          <button class="btn btn-secondary btn-sm" data-open="importModal"><?= icon('file-up', 15) ?> Import CSV</button>
          <button class="btn btn-primary btn-sm" data-open="studentModal" data-student=""><?= icon('plus', 15) ?> Add student</button>
        </div>
      <?php endif; ?>
    </div>
  <?php else: ?>
  <div class="student-card-grid">
    <?php foreach ($students as $st): ?>
    <article class="student-roster-card">
      <div class="student-card-heading"><span class="person"><?= avatar(['name' => $st['name'], 'avatar' => $st['user_avatar'] ?? null], 'sm') ?><span><b><?= e($st['name']) ?></b><small><?= e($st['code']) ?></small></span></span>
        <span class="student-card-actions">
          <button class="icon-btn" title="Edit student" data-open="studentModal" data-student="<?= (int) $st['id'] ?>" data-section="<?= (int) $st['section_id'] ?>" data-num="<?= e($st['student_number']) ?>" data-name="<?= e($st['name']) ?>"><?= icon('pencil', 15) ?></button>
          <form method="post" class="inline" onsubmit="return confirm('Remove this student from the section?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_student"><input type="hidden" name="section_id" value="<?= (int) $st['section_id'] ?>"><input type="hidden" name="student_id" value="<?= (int) $st['id'] ?>"><button class="icon-btn danger" title="Delete student"><?= icon('trash-2', 15) ?></button></form>
        </span>
      </div>
      <div class="student-card-details"><span><small>Student ID</small><b><?= e($st['student_number']) ?></b></span><span><small>Section</small><b><?= e($st['section_name']) ?></b></span><span><small>Enrolled</small><b><?= e($st['enrolled_at'] ? date('M j, Y', strtotime($st['enrolled_at'])) : 'Active') ?></b></span></div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <nav class="pagination" aria-label="Student roster pages">
    <?php $pageUrl = static fn(int $n): string => url('students.php?' . http_build_query(array_filter(['section' => $filterSection ?: null, 'q' => $search ?: null, 'page' => $n > 1 ? $n : null]))); ?>
    <a class="page-control <?= $page <= 1 ? 'is-disabled' : '' ?>" href="<?= $page > 1 ? e($pageUrl($page - 1)) : '#' ?>" <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>‹ <span>Previous</span></a>
    <?php $shown = []; foreach ([1, $page - 1, $page, $page + 1, $totalPages] as $n) if ($n >= 1 && $n <= $totalPages) $shown[$n] = $n; ksort($shown); $last = 0; foreach ($shown as $n): ?>
      <?php if ($last && $n > $last + 1): ?><span class="page-ellipsis" aria-hidden="true">…</span><?php endif; ?>
      <a class="page-number <?= $n === $page ? 'is-current' : '' ?>" href="<?= e($pageUrl($n)) ?>" <?= $n === $page ? 'aria-current="page"' : '' ?>><?= $n ?></a>
    <?php $last = $n; endforeach; ?>
    <a class="page-control <?= $page >= $totalPages ? 'is-disabled' : '' ?>" href="<?= $page < $totalPages ? e($pageUrl($page + 1)) : '#' ?>" <?= $page >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>><span>Next</span> ›</a>
  </nav>
  <div class="roster-footer">
    <span><?= icon('shield-check', 14) ?> Student information is visible only to your teaching workspace.</span>
    <?php if ($inviteSection): ?>
      <a class="text-link" href="<?= e(url('students.php?section=' . ($filterSection ?: (int) $inviteSection['id']) . '&invite=1')) ?>"><?= icon('qr-code', 14) ?> Invite students by QR</a>
    <?php endif; ?>
  </div>
</section>

<dialog class="modal" id="studentModal">
  <form method="post" class="modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="save_student">
    <input type="hidden" name="student_id" id="fStudentId">
    <h3 id="studentModalTitle">Add student</h3>
    <label class="label">Section</label>
    <select class="input" name="section_id" id="fSection">
      <?php foreach ($sections as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $filterSection === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['code'] . ' · ' . $s['name']) ?></option><?php endforeach; ?>
    </select>
    <div class="grid-2">
      <div><label class="label">Student ID</label><input class="input" name="student_number" id="fNum" required></div>
      <div><label class="label">Full name</label><input class="input" name="name" id="fName" required></div>
    </div>
    <div class="modal-actions"><button type="button" class="btn btn-secondary" data-close>Cancel</button><button class="btn btn-primary"><?= icon('save', 15) ?> Save</button></div>
  </form>
</dialog>

<dialog class="modal" id="importModal">
  <form method="post" enctype="multipart/form-data" class="modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="import_csv">
    <h3>Import students</h3>
    <p class="muted small">Add up to 500 students at once from a CSV file. Use two columns: student ID, then full name.</p>
    <label class="label">Import into section</label>
    <select class="input" name="section_id" required>
      <?php foreach ($sections as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $filterSection === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['code'] . ' · ' . $s['name']) ?></option><?php endforeach; ?>
    </select>
    <label class="dropzone"><input type="file" name="csv" accept=".csv,text/csv" required>
      <span class="upload-icon"><?= icon('file-up', 21) ?></span><span>Choose CSV file</span>
      <small class="muted">CSV format: student_id, name</small></label>
    <p class="note"><?= icon('shield-check', 14) ?> Existing student IDs in this section are updated, not duplicated.</p>
    <div class="modal-actions"><button type="button" class="btn btn-secondary" data-close>Cancel</button><button class="btn btn-primary"><?= icon('upload', 15) ?> Import</button></div>
  </form>
</dialog>

<dialog class="modal" id="inviteModal">
  <div class="modal-body center">
    <h3>Invite students to join</h3>
    <p class="muted small"><?= $inviteSection ? e($inviteSection['code'] . ' · ' . $inviteSection['name'] . ' — share this QR code with your class.') : '' ?></p>
    <div class="qr-frame"><?= $enrollUrl ? '<img src="' . e(qr_data_uri($enrollUrl)) . '" alt="Enrollment QR">' : '' ?></div>
    <span class="muted small">Section invite code</span>
    <div class="code-pill"><?= e($inviteSection['enroll_code'] ?? '—') ?></div>
    <p class="muted small"><?= icon('shield-check', 14) ?> Students must sign in with their school-issued ID to enroll.</p>
    <div class="modal-actions"><button type="button" class="btn btn-primary" data-close>Done</button></div>
  </div>
</dialog>
<?php layout_end(($_GET['invite'] ?? '') ? '<script>document.getElementById("inviteModal")?.showModal();</script>' : ''); ?>
