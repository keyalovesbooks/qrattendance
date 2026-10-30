<?php
/**
 * reset-password.php – choose a new password using a single-use reset token.
 */
require __DIR__ . '/config.php';

if ($u = current_user()) {
    redirect($u['role'] === 'professor' ? 'dashboard.php' : 'student/index.php');
}

$token = trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));
$error = '';
$done = false;
$valid = false;

if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $row = q('SELECT pr.id, pr.user_id, pr.expires_at, pr.used_at FROM password_resets pr
        WHERE pr.token_hash = ? LIMIT 1', [hash('sha256', $token)])->fetch();
    if ($row && !$row['used_at'] && strtotime($row['expires_at']) > time()) {
        $valid = true;
    } elseif ($row) {
        $error = 'This reset link has expired or was already used. Request a new one.';
    } else {
        $error = 'That reset link is not valid.';
    }
} else {
    $error = 'That reset link is not valid.';
}

if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $password = (string) ($_POST['password'] ?? '');
    $repeat = (string) ($_POST['confirm_password'] ?? '');
    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $repeat) {
        $error = 'The two passwords do not match.';
    } else {
        $updated = q('UPDATE password_resets SET used_at = ? WHERE id = ? AND used_at IS NULL', [now(), $row['id']]);
        if (!$updated->rowCount()) {
            $error = 'This reset link has already been used.';
        } else {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $row['user_id']]);
            $done = true;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reset password · QRAttendance</title>
<link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>?v=<?= (int) (@filemtime(__DIR__ . '/assets/style.css') ?: 1) ?>">
<script src="https://unpkg.com/lucide@1.50.0/dist/umd/lucide.min.js"></script>
</head>
<body class="auth-body">
<div class="auth auth-narrow">
  <section class="auth-panel">
    <?php if ($done): ?>
      <div class="auth-card">
        <div class="reset-done-icon"><?= icon('check', 26) ?></div>
        <h1>Password updated</h1>
        <p class="muted">You can now sign in with your new password.</p>
        <a class="btn btn-primary btn-block" href="<?= e(url('login.php')) ?>">Go to sign in</a>
      </div>
    <?php elseif ($valid): ?>
      <form class="auth-card" method="post" autocomplete="on">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <span class="auth-kicker">CHOOSE A NEW PASSWORD</span>
        <h1>Reset your password</h1>
        <p class="muted">Choose a strong password you haven’t used here before.</p>
        <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
        <?= csrf_field() ?>
        <label class="label" for="password">New password</label>
        <div class="pw-wrap">
          <input class="input" id="password" name="password" type="password" minlength="8" required autocomplete="new-password" placeholder="At least 8 characters">
          <button type="button" class="pw-toggle" data-toggle-password="password" aria-label="Show password"><span class="pw-show"><?= icon('eye', 18) ?></span><span class="pw-hide" hidden><?= icon('eye-off', 18) ?></span></button>
        </div>
        <label class="label" for="confirm_password">Repeat new password</label>
        <input class="input" id="confirm_password" name="confirm_password" type="password" minlength="8" required autocomplete="new-password" placeholder="Repeat your new password">
        <button class="btn btn-primary btn-block" type="submit">Update password</button>
        <p class="auth-switch"><a href="<?= e(url('login.php')) ?>">Back to sign in</a></p>
      </form>
    <?php else: ?>
      <div class="auth-card">
        <div class="reset-done-icon reset-done-error"><?= icon('x', 26) ?></div>
        <h1>Link not usable</h1>
        <p class="muted"><?= e($error) ?></p>
        <a class="btn btn-primary btn-block" href="<?= e(url('forgot-password.php')) ?>">Request a new link</a>
      </div>
    <?php endif; ?>
  </section>
</div>
<script src="<?= e(url('assets/app.js')) ?>"></script>
</body>
</html>
