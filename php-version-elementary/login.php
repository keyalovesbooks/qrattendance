<?php
require __DIR__ . '/config.php';

$next = $_GET['next'] ?? $_POST['next'] ?? '';
$safeNext = (is_string($next) && str_starts_with($next, '/') && !str_starts_with($next, '//')) ? $next : '';

if ($u = current_user()) {
    redirect($u['role'] === 'professor' ? 'dashboard.php' : 'student/index.php');
}

$error = '';
$email = $_COOKIE['qr_remember_email'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim(strtolower((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $remember = !empty($_POST['remember']);

    // --- Brute-force lockout: checked BEFORE any password work so a locked
    // --- account costs nothing and the response stays generic.
    $lockRemaining = login_lock_remaining($email);
    if ($lockRemaining > 0) {
        $minutes = (int) ceil($lockRemaining / 60);
        // Generic wording: never reveals whether the email exists.
        $error = "Too many failed sign-in attempts. Please try again in about {$minutes} minute" . ($minutes === 1 ? '' : 's') . ".";
    } else {
    $user = q('SELECT * FROM users WHERE email = ?', [$email])->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        login_record_failure($email);
        $lockApplied = login_lock_remaining($email);
        if ($lockApplied > 0) {
            $error = 'Too many failed sign-in attempts. Please try again in about 30 minutes.';
        } else {
            // Generic error: identical for "no such email" and "wrong password".
            $error = 'Incorrect email or password.';
        }
    } else {
        login_reset_failures($email);
        session_regenerate_id(true);              // prevent session fixation
        $_SESSION['uid'] = (int) $user['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));

        $cookie = ['path' => '/', 'secure' => IS_HTTPS, 'httponly' => true, 'samesite' => 'Lax'];
        if ($remember) {
            // extend the session cookie to 30 days and remember the email
            setcookie(session_name(), session_id(), $cookie + ['expires' => time() + REMEMBER_SECONDS]);
            setcookie('qr_remember_email', $email, ['expires' => time() + REMEMBER_SECONDS, 'path' => '/', 'secure' => IS_HTTPS, 'httponly' => false, 'samesite' => 'Lax']);
        } else {
            setcookie('qr_remember_email', '', ['expires' => time() - 3600, 'path' => '/']);
        }
        setcookie('qr_device', bin2hex(random_bytes(16)), $cookie + ['expires' => $remember ? time() + REMEMBER_SECONDS : 0]);

        if ($safeNext) { header('Location: ' . $safeNext); exit; }
        redirect($user['role'] === 'professor' ? 'dashboard.php' : 'student/index.php');
    }
    }
}
$hasProfessor = (bool) q("SELECT COUNT(*) FROM users WHERE role='professor'")->fetchColumn();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · QRAttendance</title>
<link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>?v=<?= (int) (@filemtime(__DIR__ . '/assets/style.css') ?: 1) ?>">
<script src="https://unpkg.com/lucide@1.50.0/dist/umd/lucide.min.js"></script>
</head>
<body class="auth-body">
<div class="auth">
  <section class="auth-hero">
    <a class="brand school-brand" href="#"><span class="brand-mark"><img src="<?= e(url('logo.jpeg')) ?>" alt="" onerror="this.style.display='none'"><?= icon('book-open-check', 21) ?></span><span class="school-brand-name">SongSong Elementary School<small>Gamu, Isabela</small></span></a>
    <div class="auth-hero-copy">
      <h2>SongSong Elementary<br><span>Attendance Portal</span></h2>
      <p>A secure, simple attendance portal for SongSong Elementary School in Gamu, Isabela.</p>
      <ul class="auth-points"><li><span class="benefit-icon"><?= icon('shield-check', 17) ?></span>One-time, expiring QR passes</li><li><span class="benefit-icon"><?= icon('book-open-check', 17) ?></span>CSV roster import &amp; export</li><li><span class="benefit-icon"><?= icon('history', 17) ?></span>History for every school year and semester</li></ul>
    </div>
    <small>© <?= date('Y') ?> SongSong Elementary School</small>
  </section>
  <section class="auth-panel">
    <form class="auth-card" method="post" autocomplete="on">
      <h1>Welcome back</h1>
      <p class="muted">Sign in to continue to your dashboard.</p>
      <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($safeNext) ?>">
      <label class="label" for="email">Email</label>
      <input class="input" id="email" name="email" type="email" required value="<?= e($email) ?>" placeholder="you@school.edu">
      <label class="label" for="password">Password</label>
      <div class="pw-wrap">
        <input class="input" id="password" name="password" type="password" required placeholder="Your password">
        <button type="button" class="pw-toggle" data-toggle-password="password" aria-label="Show password"><span class="pw-show"><?= icon('eye', 18) ?></span><span class="pw-hide" hidden><?= icon('eye-off', 18) ?></span></button>
      </div>
      <div class="login-options">
        <label class="check"><input type="checkbox" name="remember" value="1" <?= $email ? 'checked' : '' ?>> Keep me signed in</label>
        <a class="forgot-link" href="<?= e(url('forgot-password.php')) ?>">Forgot password?</a>
      </div>
      <button class="btn btn-primary btn-block" type="submit">Sign in</button>
      <div class="auth-switches">
        <p class="auth-switch">Don’t have an account? <a href="<?= e(url('register.php' . ($safeNext ? '?next=' . urlencode($safeNext) : ''))) ?>">Create Account</a></p>
      </div>
    </form>
  </section>
</div>
<script src="<?= e(url('assets/app.js')) ?>"></script>
</body>
</html>
