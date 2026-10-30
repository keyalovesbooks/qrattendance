<?php
/**
 * register.php – create a student or professor account (mirrors the Next.js
 * sign-up: role choice, name format, student ID or teacher level, first term).
 */
require __DIR__ . '/config.php';

$old = ['first_name' => '', 'middle_name' => '', 'last_name' => '', 'email' => '', 'student_number' => ''];
$role = ($_GET['role'] ?? '') === 'student' ? 'student' : 'professor';  // link from the sign-in page
$level = '';      // professor: primary|secondary|tertiary
$studentLevel = ''; // student: elementary|highschool|college
$errors = [];
$role = 'professor';  // same default as the Next.js sign-up
$level = '';
$termYear = date('Y') . '-' . (date('m') >= '09' ? date('Y') + 1 : date('Y') - 1);
$termSem = in_array(date('n'), [1, 2, 3, 4], true) ? '2nd Semester' : (in_array(date('n'), [5, 6, 7], true) ? 'Summer Term' : '1st Semester');

/** First Name, Middle Name, Last Name — the first name drives the greeting. */
function build_full_name(string $first, string $middle, string $last): string {
  $full = trim(trim($first) . ' ' . trim($middle) . ' ' . trim($last));
  return trim((string) preg_replace('/\s+/', ' ', $full));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $old['first_name'] = trim((string) ($_POST['first_name'] ?? ''));
    $old['middle_name'] = trim((string) ($_POST['middle_name'] ?? ''));
    $old['last_name'] = trim((string) ($_POST['last_name'] ?? ''));
    $old['email'] = trim(strtolower((string) ($_POST['email'] ?? '')));
    $old['student_number'] = trim((string) ($_POST['student_number'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $role = ($_POST['role'] ?? '') === 'professor' ? 'professor' : 'student';
    $level = (string) ($_POST['teacher_level'] ?? '');
    $studentLevel = (string) ($_POST['student_level'] ?? '');
    $termYear = trim((string) ($_POST['school_year'] ?? $termYear));
    $termSem = trim((string) ($_POST['semester'] ?? $termSem));

    $fullName = build_full_name($old['first_name'], $old['middle_name'], $old['last_name']);
    if ($old['first_name'] === '') $errors[] = 'Enter your first name.';
    if ($old['last_name'] === '') $errors[] = 'Enter your last name.';
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid school email.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($role === 'student' && $old['student_number'] === '') $errors[] = 'Enter your student ID.';
    if ($role === 'professor' && !in_array($level, ['primary', 'secondary', 'tertiary'], true)) $errors[] = 'Choose the school level you teach.';
    if ($role === 'student' && !in_array($studentLevel, ['elementary', 'highschool', 'college'], true)) $errors[] = 'Choose your level: elementary, highschool or college.';
    if (!$errors && q('SELECT id FROM users WHERE email = ?', [$old['email']])->fetch()) $errors[] = 'That email is already registered. Sign in instead.';

    if (!$errors) {
        try {
            q('INSERT INTO users (name, first_name, middle_name, last_name, email, password_hash, role, student_number, teacher_level, student_level) VALUES (?,?,?,?,?,?,?,?,?,?)', [
                $fullName, $old['first_name'], $old['middle_name'], $old['last_name'],
                $old['email'], password_hash($password, PASSWORD_DEFAULT), $role,
                $role === 'student' ? $old['student_number'] : null,
                $role === 'professor' ? $level : null,
                $role === 'student' ? $studentLevel : null,
            ]);
            $uid = (int) db()->lastInsertId();
            session_regenerate_id(true);
            $_SESSION['uid'] = $uid;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));

            if ($role === 'professor' && $termYear !== '' && $termSem !== '') {
                // this term belongs to the new professor only — storage is not shared
                q('INSERT INTO terms (school_year, semester, is_current, professor_id) VALUES (?,?,1,?)
                   ON DUPLICATE KEY UPDATE is_current = 1', [$termYear, $termSem, $uid]);
                q('UPDATE terms SET is_current = 0 WHERE professor_id = ?', [$uid]);
                q('UPDATE terms SET is_current = 1 WHERE professor_id = ? AND school_year = ? AND semester = ?',
                  [$uid, $termYear, $termSem]);
            }
            flash('Welcome! Your account is ready.');
            redirect($role === 'professor' ? 'dashboard.php' : 'student/index.php');
        } catch (PDOException $ex) {
            $errors[] = $ex->getCode() === '23000'
                ? 'That email is already registered. Sign in instead.'
                : 'We could not create your account. Please try again.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Create account · QRAttendance</title>
<link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>?v=<?= (int) (@filemtime(__DIR__ . '/assets/style.css') ?: 1) ?>">
<script src="https://unpkg.com/lucide@1.50.0/dist/umd/lucide.min.js"></script>
</head>
<body class="auth-body">
<div class="auth">
  <section class="auth-hero">
    <a class="brand" href="<?= e(url('index.php')) ?>"><span class="brand-mark"><?= icon('book-open-check', 21) ?></span><span>qr<span class="brand-light">attendance</span></span></a>
    <div class="auth-hero-copy">
      <span class="auth-eyebrow"><?= icon('sparkles', 14) ?> THE SMARTER WAY TO SHOW UP</span>
      <h2>Join your class<br><span>in one scan.</span></h2>
      <p>Students check in with a QR pass or their school ID. Professors get every record in one place.</p>
      <ul class="auth-points">
        <li><span class="benefit-icon"><?= icon('shield-check', 17) ?></span>One-time, expiring QR passes</li>
        <li><span class="benefit-icon"><?= icon('book-open-check', 17) ?></span>CSV roster import &amp; export</li>
        <li><span class="benefit-icon"><?= icon('nfc', 17) ?></span>RFID card check-in at the door</li>
      </ul>
    </div>
    <small>© <?= date('Y') ?> QRAttendance</small>
  </section>

  <section class="auth-panel">
    <form class="auth-card" method="post" autocomplete="on">
      <span class="auth-kicker">LET’S GET YOU SET UP</span>
      <h1>Create your account</h1>
      <p class="muted">A calmer way to manage class attendance starts here.</p>

      <?php foreach ($errors as $err): ?><div class="alert alert-error" role="alert"><?= e($err) ?></div><?php endforeach; ?>
      <?= csrf_field() ?>

      <div class="name-grid">
        <div>
          <label class="label" for="first_name">First name</label>
          <input class="input" id="first_name" name="first_name" required maxlength="60"
                 autocomplete="given-name" value="<?= e($old['first_name']) ?>" placeholder="e.g. Juan">
        </div>
        <div>
          <label class="label" for="middle_name">Middle name</label>
          <input class="input" id="middle_name" name="middle_name" maxlength="60"
                 autocomplete="additional-name" value="<?= e($old['middle_name']) ?>" placeholder="e.g. Miguel">
        </div>
        <div>
          <label class="label" for="last_name">Last name</label>
          <input class="input" id="last_name" name="last_name" required maxlength="60"
                 autocomplete="family-name" value="<?= e($old['last_name']) ?>" placeholder="e.g. Dela Cruz">
        </div>
      </div>
      <p class="field-hint">Your first name is used to greet you after signing in.</p>

      <span class="label role-label">I’m joining as a</span>
      <div class="role-options" role="radiogroup" aria-label="Account role">
        <label class="role-option <?= $role === 'professor' ? 'selected' : '' ?>">
          <input type="radio" name="role" value="professor" <?= $role === 'professor' ? 'checked' : '' ?>>
          <span class="role-option-icon"><?= icon('book-open-check', 17) ?></span>
          <span><b>Professor</b><small>Manage classes &amp; attendance</small></span>
          <span class="role-check">✓</span>
        </label>
        <label class="role-option <?= $role === 'student' ? 'selected' : '' ?>">
          <input type="radio" name="role" value="student" <?= $role === 'student' ? 'checked' : '' ?>>
          <span class="role-option-icon student-icon"><?= icon('users', 17) ?></span>
          <span><b>Student</b><small>Check in to class</small></span>
          <span class="role-check">✓</span>
        </label>
      </div>

      <label class="label" for="email">School email</label>
      <input class="input" id="email" name="email" type="email" required
             autocomplete="email" placeholder="you@school.edu" value="<?= e($old['email']) ?>">

      <div data-when="student" <?= $role === 'student' ? '' : 'hidden' ?>>
        <label class="label" for="student_number">Student ID</label>
        <input class="input" id="student_number" name="student_number" autocomplete="off"
               placeholder="e.g. 2024-0148" value="<?= e($old['student_number']) ?>"
               <?= $role === 'student' ? 'required' : '' ?>>
        <p class="field-hint">Use the same ID your professor has on the class roster.</p>

        <span class="label role-label">I am a student in</span>
        <div class="role-options role-options-levels" role="radiogroup" aria-label="Student level">
          <label class="role-option level-option"><input type="radio" name="student_level" value="elementary" required <?= $studentLevel === 'elementary' ? 'checked' : '' ?>>
            <span class="role-option-icon"><?= icon('book-open', 17) ?></span><span><b>Elementary</b><small>QR for the whole semester</small></span><span class="role-check">✓</span></label>
          <label class="role-option level-option"><input type="radio" name="student_level" value="highschool" <?= $studentLevel === 'highschool' ? 'checked' : '' ?>>
            <span class="role-option-icon"><?= icon('book-open', 17) ?></span><span><b>High school</b><small>Time-limited QR</small></span><span class="role-check">✓</span></label>
          <label class="role-option level-option"><input type="radio" name="student_level" value="college" <?= $studentLevel === 'college' ? 'checked' : '' ?>>
            <span class="role-option-icon"><?= icon('book-open', 17) ?></span><span><b>College</b><small>Time-limited QR</small></span><span class="role-check">✓</span></label>
        </div>
      </div>

      <div data-when="professor" <?= $role === 'professor' ? '' : 'hidden' ?>>
        <span class="label role-label">I teach at</span>
        <div class="role-options role-options-levels" role="radiogroup" aria-label="School level">
          <label class="role-option level-option"><input type="radio" name="teacher_level" value="primary" required <?= $level === 'primary' ? 'checked' : '' ?>>
            <span class="role-option-icon">1</span><span><b>Primary</b><small>Elementary</small></span><span class="role-check">✓</span></label>
          <label class="role-option level-option"><input type="radio" name="teacher_level" value="secondary" <?= $level === 'secondary' ? 'checked' : '' ?>>
            <span class="role-option-icon">2</span><span><b>Secondary</b><small>High school</small></span><span class="role-check">✓</span></label>
          <label class="role-option level-option"><input type="radio" name="teacher_level" value="tertiary" <?= $level === 'tertiary' ? 'checked' : '' ?>>
            <span class="role-option-icon">3</span><span><b>Tertiary</b><small>College</small></span><span class="role-check">✓</span></label>
        </div>

        <div class="term-fields">
          <div><label class="label" for="school_year">School year</label>
            <input class="input" id="school_year" name="school_year" value="<?= e($termYear) ?>" placeholder="2026-2027"></div>
          <div><label class="label" for="semester">Semester</label>
            <select class="input" id="semester" name="semester">
              <?php foreach (['1st Semester', '2nd Semester', 'Summer Term'] as $option): ?>
                <option <?= $termSem === $option ? 'selected' : '' ?>><?= e($option) ?></option>
              <?php endforeach; ?>
            </select></div>
        </div>
      </div>

      <div class="password-label-row">
        <label class="label" for="password">Password</label>
        <span class="password-tip"><?= icon('lock-keyhole', 12) ?> Secure sign-in</span>
      </div>
      <div class="pw-wrap">
        <input class="input" id="password" name="password" type="password" minlength="8"
               autocomplete="new-password" required placeholder="At least 8 characters">
        <button type="button" class="pw-toggle" data-toggle-password="password" aria-label="Show password">
          <span class="pw-show"><?= icon('eye', 18) ?></span><span class="pw-hide" hidden><?= icon('eye-off', 18) ?></span>
        </button>
      </div>
      <p class="field-hint">Use at least 8 characters to keep your account secure.</p>

      <label class="check remember-row"><input type="checkbox" name="remember" value="1"> Keep me signed in on this device</label>

      <button class="btn btn-primary btn-block auth-submit" type="submit">Create account</button>
      <p class="auth-terms">By continuing, you agree to keep class attendance accurate and respectful.</p>
      <p class="auth-switch">Already have an account? <a href="<?= e(url('login.php')) ?>">Sign in</a></p>
    </form>
    <a class="auth-preview-link" href="<?= e(url('index.php')) ?>"><?= icon('arrow-left', 14) ?> Back to sign in</a>
  </section>
</div>

<script src="<?= e(url('assets/app.js')) ?>"></script>
<script>
  // Role / level reveal (same behaviour as the Next.js sign-up)
  (function () {
    function sync() {
      var role = (document.querySelector('input[name="role"]:checked') || {}).value || 'student';
      document.querySelectorAll('[data-when]').forEach(function (block) {
        var show = block.getAttribute('data-when') === role;
        block.hidden = !show;
        // disable hidden fields so browser validation only asks for the
        // fields belonging to the selected role
        block.querySelectorAll('input, select').forEach(function (field) {
          field.disabled = !show;
        });
        if (show && role === 'student' && !document.querySelector('input[name="student_level"]:checked')) {
          document.querySelector('input[name="student_level"]').checked = true;
        }
        document.querySelectorAll('input[name="student_level"]').forEach(function (input) {
          input.closest('.role-option').classList.toggle('selected', input.checked);
        });
        if (show) {
          var studentId = block.querySelector('input[name="student_number"]');
          if (studentId) studentId.required = true;
        }
      });
      document.querySelectorAll('input[name="role"]').forEach(function (input) {
        input.closest('.role-option').classList.toggle('selected', input.checked);
      });
      if (role === 'professor' && !document.querySelector('input[name="teacher_level"]:checked')) {
        document.querySelector('input[name="teacher_level"]').checked = true;
      }
      document.querySelectorAll('input[name="teacher_level"]').forEach(function (input) {
        input.closest('.role-option').classList.toggle('selected', input.checked);
      });
    }
    document.querySelectorAll('input[name="role"], input[name="teacher_level"], input[name="student_level"]').forEach(function (input) {
      input.addEventListener('change', sync);
    });
    sync();
  })();
</script>
</body>
</html>
