<?php
require __DIR__ . '/config.php';

// Embed the small school crest so the login logo does not depend on the host serving a separate image file.
$schoolLogoDataUri = 'data:image/jpeg;base64,' . implode('', [
    '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',
]);

$next = $_GET['next'] ?? $_POST['next'] ?? '';
$safeNext = (is_string($next) && str_starts_with($next, '/') && !str_starts_with($next, '//')) ? $next : '';

if ($u = current_user()) {
    redirect($u['role'] === 'professor' ? 'dashboard.php' : 'student/index.php');
}

$error = '';
$sessionExpired = !empty($_SESSION['session_expired']);
unset($_SESSION['session_expired']);
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
        security_log('login_locked_attempt', 429);
        $minutes = (int) ceil($lockRemaining / 60);
        // Generic wording: never reveals whether the email exists.
        $error = "Too many failed sign-in attempts. Please try again in about {$minutes} minute" . ($minutes === 1 ? '' : 's') . ".";
    } else {
    $user = q('SELECT * FROM users WHERE email = ?', [$email])->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        security_log('login_failure', 401);
        login_record_failure($email);
        $lockApplied = login_lock_remaining($email);
        if ($lockApplied > 0) {
            security_log('login_lockout_applied', 429);
            $error = 'Too many failed sign-in attempts. Please try again in about 30 minutes.';
        } else {
            // Generic error: identical for "no such email" and "wrong password".
            $error = 'Incorrect email or password.';
        }
    } else {
        login_reset_failures($email);
        session_regenerate_id(true);              // prevent session fixation
        $_SESSION['uid'] = (int) $user['id'];
        $_SESSION['created_at'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['remember_me'] = $remember;
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
  <section class="auth-hero" style="background-image:linear-gradient(135deg,rgba(7,24,57,.48),rgba(16,40,88,.36)),url('<?= e(url('school.jpeg')) ?>'),radial-gradient(circle at 20% 80%,#3261df,transparent 40%),linear-gradient(150deg,#102858,#0a1d41);background-position:center;background-size:cover;">
    <a class="brand school-brand" href="#"><span class="brand-mark"><img src="<?= e($schoolLogoDataUri) ?>" alt="SongSong Elementary School logo" width="36" height="36" decoding="async"><?= icon('book-open-check', 21) ?></span><span class="school-brand-name">SongSong Elementary School<small>Gamu, Isabela</small></span></a>
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
      <?php if ($sessionExpired): ?><div class="alert alert-error">Your session expired. Please sign in again.</div><?php endif; ?>
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
