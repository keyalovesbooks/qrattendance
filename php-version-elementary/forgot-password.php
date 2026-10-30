<?php
/**
 * forgot-password.php – request a password reset link.
 * Generates a single-use token (stored hashed, 60-minute TTL). The link is
 * shown on screen because this deployment has no mail transport configured;
 * wire $resetUrl into an email on a host that provides mail().
 */
require __DIR__ . '/config.php';

if ($u = current_user()) {
    redirect($u['role'] === 'professor' ? 'dashboard.php' : 'student/index.php');
}

$sent = false;
$resetUrl = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim(strtolower((string) ($_POST['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter the email address you registered with.';
    } else {
        $user = q('SELECT id FROM users WHERE email = ?', [$email])->fetch();
        // Always respond as if sent, so the page cannot be used to probe accounts.
        if ($user) {
            $token = bin2hex(random_bytes(32));
            q('DELETE FROM password_resets WHERE user_id = ?', [(int) $user['id']]);
            q('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?,?,?)', [
                (int) $user['id'],
                hash('sha256', $token),
                date('Y-m-d H:i:s', time() + 3600),
            ]);
            $scheme = IS_HTTPS ? 'https' : 'http';
            $resetUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                . url('reset-password.php?token=' . urlencode($token));
        }
        $sent = true;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Forgot password · QRAttendance</title>
<link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>?v=<?= (int) (@filemtime(__DIR__ . '/assets/style.css') ?: 1) ?>">
<script src="https://unpkg.com/lucide@1.50.0/dist/umd/lucide.min.js"></script>
</head>
<body class="auth-body">
<div class="auth auth-narrow">
  <section class="auth-panel">
    <form class="auth-card" method="post" autocomplete="on">
      <span class="auth-kicker">ACCOUNT RECOVERY</span>
      <h1>Forgot your password?</h1>
      <p class="muted">Enter your account email and we’ll generate a secure reset link.</p>

      <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
      <?= csrf_field() ?>

      <?php if ($sent): ?>
        <div class="alert alert-success">If that email belongs to an account, a reset link has been created. It expires in 60 minutes.</div>
        <?php if ($resetUrl): ?>
          <div class="reset-link-box">
            <span class="reset-link-label"><?= icon('key-round', 14) ?> Your reset link</span>
            <div class="reset-link-value"><?= e($resetUrl) ?></div>
            <p class="reset-link-hint">This deployment has no email service configured, so the link is shown here. Send this address to yourself, then open it to choose a new password.</p>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <label class="label" for="email">School email</label>
        <input class="input" id="email" name="email" type="email" required autocomplete="email" placeholder="you@school.edu">
        <button class="btn btn-primary btn-block" type="submit">Create reset link</button>
      <?php endif; ?>

      <p class="auth-switch">Remembered it? <a href="<?= e(url('login.php')) ?>">Back to sign in</a></p>
    </form>
  </section>
</div>
<script src="<?= e(url('assets/app.js')) ?>"></script>
</body>
</html>
