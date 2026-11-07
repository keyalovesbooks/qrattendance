<?php
/**
 * forgot-password.php – request a password reset link.
 * Generates a single-use token (stored hashed, 60-minute TTL) and displays
 * the reset link on screen; no email service is used.
 */
require __DIR__ . '/config.php';
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Referrer-Policy: no-referrer');

if ($u = current_user()) {
    redirect($u['role'] === 'professor' ? 'dashboard.php' : 'student/index.php');
}

$sent = false;
$resetUrl = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim(strtolower((string) ($_POST['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter the email address you registered with.';
    } else {
        $user = q('SELECT id FROM users WHERE email = ?', [$email])->fetch();
        // Create a one-time reset token and show its link directly on this page.
        // No PHP mail(), PHPMailer, or SMTP service is used by this flow.
        if ($user) {
            $token = bin2hex(random_bytes(32));
            q('DELETE FROM password_resets WHERE user_id = ?', [(int) $user['id']]);
            q('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?,?,?)', [
                (int) $user['id'], hash('sha256', $token), date('Y-m-d H:i:s', time() + 3600),
            ]);
            $resetUrl = rtrim(APP_ORIGIN, '/') . url('reset-password.php?token=' . urlencode($token));
            security_log('password_reset_link_displayed', 200);
        }
        // Keep the confirmation neutral for unregistered addresses.
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
          <div style="margin-top:14px;padding:16px;border:1px solid #d9e2ff;border-radius:14px;background:#f3f5ff;overflow-wrap:anywhere">
            <strong style="display:block;margin-bottom:10px">YOUR RESET LINK</strong>
            <a href="<?= e($resetUrl) ?>" rel="noreferrer"><?= e($resetUrl) ?></a>
            <p class="muted" style="margin:12px 0 0">This deployment does not send email. Open this one-time link to choose a new password.</p>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <label class="label" for="email">School email</label>
        <input class="input" id="email" name="email" type="email" required autocomplete="email" placeholder="you@school.edu">
        <button class="btn btn-primary btn-block" type="submit">Generate reset link</button>
      <?php endif; ?>

      <p class="auth-switch">Remembered it? <a href="<?= e(url('login.php')) ?>">Back to sign in</a></p>
    </form>
  </section>
</div>
<script src="<?= e(url('assets/app.js')) ?>"></script>
</body>
</html>
