<?php
require __DIR__ . '/config.php';
$u = current_user();
if (!$u) redirect('login.php');

$back = $u['role'] === 'professor' ? 'dashboard.php' : 'student/index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'update_profile') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $email = trim(strtolower((string) ($_POST['email'] ?? '')));
            $num = trim((string) ($_POST['student_number'] ?? ''));
            if ($name === '') throw new RuntimeException('Enter your full name.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address.');
            if ($u['role'] === 'student' && $num === '') throw new RuntimeException('Your student ID is required.');
            $taken = q('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $u['id']])->fetch();
            if ($taken) throw new RuntimeException('That email address is already used by another account.');

            $avatarFile = $u['avatar'] ?? null;
            if (!empty($_FILES['avatar']['name'])) {
                $previous = $avatarFile;
                $avatarFile = save_avatar_upload($_FILES['avatar']);
                if ($previous && is_file(__DIR__ . '/uploads/avatars/' . $previous)) @unlink(__DIR__ . '/uploads/avatars/' . $previous);
            } elseif (!empty($_POST['remove_avatar']) && $avatarFile) {
                if (is_file(__DIR__ . '/uploads/avatars/' . $avatarFile)) @unlink(__DIR__ . '/uploads/avatars/' . $avatarFile);
                $avatarFile = null;
            }
            // Role and level are read-only on this page — always keep the stored values.
            $studentLevel = $u['role'] === 'student' ? ($u['student_level'] ?? null) : null;
            $level = $u['role'] === 'professor' ? ($u['teacher_level'] ?? null) : null;
            q('UPDATE users SET name = ?, email = ?, student_number = ?, avatar = ?, teacher_level = ?, student_level = ? WHERE id = ?', [
                $name, $email, $u['role'] === 'student' ? $num : ($u['student_number'] ?? null), $avatarFile, $level,
                $studentLevel, $u['id'],
            ]);
            flash('Your profile has been updated.');
            redirect($u['role'] === 'professor' ? 'profile.php' : 'student/profile.php');
        }

        if ($action === 'change_password') {
            $current = (string) ($_POST['current_password'] ?? '');
            $new = (string) ($_POST['new_password'] ?? '');
            $repeat = (string) ($_POST['repeat_password'] ?? '');
            if (!password_verify($current, (string) q('SELECT password_hash FROM users WHERE id = ?', [$u['id']])->fetchColumn())) throw new RuntimeException('Your current password is incorrect.');
            if (strlen($new) < 8) throw new RuntimeException('The new password must be at least 8 characters.');
            if ($new !== $repeat) throw new RuntimeException('The two new passwords do not match.');
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            flash('Your password has been changed.');
            redirect($u['role'] === 'professor' ? 'profile.php' : 'student/profile.php');
        }
    } catch (RuntimeException $ex) { flash($ex->getMessage(), 'error'); }
    catch (PDOException $ex) { flash($ex->getCode() === '23000' ? 'That email address is already in use.' : 'Database error. Please try again.', 'error'); }
    redirect($u['role'] === 'professor' ? 'profile.php' : 'student/profile.php');
}

$src = avatar_url($u['avatar'] ?? null);
layout_start('My profile', 'profile');
?>
<a class="back" href="<?= e(url($back)) ?>"><?= icon('arrow-left', 14) ?> Back</a>
<div class="page-head">
  <div><span class="eyebrow"><?= icon('user-round', 14) ?> Account</span><h2>My profile</h2>
    <p class="muted">Keep your details and profile picture up to date.</p></div>
</div>

<div class="profile-layout">
  <section class="card profile-card">
    <form method="post" enctype="multipart/form-data" class="modal-body no-border"><?= csrf_field() ?>
      <input type="hidden" name="action" value="update_profile">
      <h3>Profile details</h3>
      <div class="avatar-editor">
        <div class="avatar-preview">
          <?php if ($src): ?><img id="avatarPreview" class="avatar xl" src="<?= e($src) ?>" alt="">
          <?php else: ?><span class="avatar xl" id="avatarPreview"><?= e(initials($u['name'])) ?></span><?php endif; ?>
        </div>
        <div class="avatar-actions">
          <label class="btn btn-soft btn-sm"><?= icon('upload', 15) ?> Upload picture
            <input type="file" name="avatar" id="avatarInput" accept="image/png,image/jpeg,image/webp,image/gif" hidden></label>
          <?php if ($src): ?><label class="check"><input type="checkbox" name="remove_avatar" value="1"> Remove picture</label><?php endif; ?>
          <small class="muted">JPG, PNG, WebP or GIF · up to 2 MB</small>
        </div>
      </div>
      <label class="label">Full name</label><input class="input" name="name" required value="<?= e($u['name']) ?>">
      <label class="label">Email address</label><input class="input" type="email" name="email" required value="<?= e($u['email']) ?>">
      <?php if ($u['role'] === 'student'): ?>
        <label class="label">Student ID</label><input class="input" name="student_number" required value="<?= e($u['student_number'] ?? '') ?>">
        <p class="muted small">Your student ID must match the class list your professor imported.</p>
        <label class="label" for="profile-role-level">Role</label>
        <input class="input" id="profile-role-level" value="<?= e('Student · ' . ucfirst($u['student_level'] ?? 'college')) ?>" disabled>
        <?php if (($u['student_level'] ?? '') === 'elementary'): ?>
          <p class="field-hint">Elementary students use one QR code for the whole semester —
            <a href="<?= e(url('student/permanent-qr.php')) ?>">open my QR code</a>.</p>
        <?php endif; ?>
      <?php else: ?>
        <label class="label" for="profile-role-level">Role</label>
        <input class="input" id="profile-role-level" value="<?= e('Teacher · ' . ucfirst($u['teacher_level'] ?? 'tertiary') . ' (' . ($u['teacher_level'] === 'primary' ? 'Elementary' : ($u['teacher_level'] === 'secondary' ? 'High school' : 'College')) . ')') ?>" disabled>
      <?php endif; ?>
      <div class="modal-actions"><button class="btn btn-primary"><?= icon('save', 15) ?> Save changes</button></div>
    </form>
  </section>

  <section class="card">
    <form method="post" class="modal-body no-border"><?= csrf_field() ?><input type="hidden" name="action" value="change_password">
      <h3>Change password</h3>
      <p class="muted small">Use at least 8 characters. Changing your password keeps you signed in on this device.</p>
      <label class="label">Current password</label>
      <div class="pw-wrap"><input class="input" type="password" name="current_password" required autocomplete="current-password"></div>
      <div class="grid-2">
        <div><label class="label">New password</label><input class="input" type="password" name="new_password" minlength="8" required autocomplete="new-password"></div>
        <div><label class="label">Repeat new password</label><input class="input" type="password" name="repeat_password" minlength="8" required autocomplete="new-password"></div>
      </div>
      <div class="modal-actions"><button class="btn btn-primary"><?= icon('shield-check', 15) ?> Update password</button></div>
    </form>
    <div class="profile-note"><span><?= icon('shield-check', 17) ?></span>
      <p>Signed in as <b><?= e($u['email']) ?></b>. <?= icon('log-out', 13) ?> <a href="<?= e(url('logout.php')) ?>">Sign out everywhere</a></p></div>
  </section>
</div>
<?php layout_end('<script>window.AVATAR_PREVIEW = true;</script>'); ?>
