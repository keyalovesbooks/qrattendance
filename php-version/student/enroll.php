<?php
require dirname(__DIR__) . '/config.php';
$user = require_role('student');   // redirects to login (with ?next=) when signed out

$code = strtoupper(trim((string) ($_GET['code'] ?? $_POST['code'] ?? '')));
$section = q("SELECT s.*, COALESCE(sub.code,'SECTION') AS code,
    COALESCE(sub.name,'Direct section attendance') AS subject_name
    FROM sections s LEFT JOIN subjects sub ON sub.id = s.subject_id WHERE s.enroll_code = ?", [$code])->fetch();
$error = '';

if ($section && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $num = trim((string) ($_POST['student_number'] ?? ''));
    $row = q('SELECT * FROM students WHERE section_id = ? AND student_number = ?', [$section['id'], $num])->fetch();
    if (!$row) {
        $error = 'Student ID ' . $num . ' is not on the class list for this section. Please contact your professor.';
    } elseif ($row['user_id'] && (int) $row['user_id'] !== (int) $user['id']) {
        $error = 'This student ID is already linked to another account.';
    } else {
        // link the account AND record the student's email on the professor's class list
        q('UPDATE students SET user_id = ?, email = ?, enrolled_at = COALESCE(enrolled_at, ?) WHERE id = ?', [$user['id'], $user['email'], now(), $row['id']]);
        if (!$user['student_number']) q('UPDATE users SET student_number = ? WHERE id = ?', [$num, $user['id']]);
        flash('You are now enrolled in ' . $section['code'] . ' · ' . $section['name'] . '.');
        redirect('student/index.php');
    }
}

layout_start('Join a class', 'home');
?>
<a class="back" href="<?= e(url('student/index.php')) ?>"><?= icon('arrow-left', 14) ?> Back</a>
<section class="card narrow">
  <?php if (!$section): ?>
    <div class="empty"><h3>Invalid class code</h3><p class="muted">Check the code or scan the QR again.</p>
      <a class="btn btn-primary" href="<?= e(url('student/index.php')) ?>">Back to dashboard</a></div>
  <?php else: ?>
    <form method="post" class="modal-body"><?= csrf_field() ?><input type="hidden" name="code" value="<?= e($code) ?>">
      <span class="empty-icon"><?= icon('qr-code', 24) ?></span><span class="badge badge-blue"><?= e($section['code']) ?></span>
      <h3><?= e($section['subject_name']) ?> · <?= e($section['name']) ?></h3>
      <p class="muted small">Enter your student ID to confirm you’re on this section’s class list.</p>
      <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
      <label class="label">Student ID</label>
      <input class="input" name="student_number" required value="<?= e($_POST['student_number'] ?? $user['student_number'] ?? '') ?>">
      <button class="btn btn-primary btn-block">Check &amp; enroll</button>
    </form>
  <?php endif; ?>
</section>
<?php layout_end(); ?>
