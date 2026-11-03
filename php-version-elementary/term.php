<?php
require __DIR__ . '/config.php';
$u = current_user();
if (!$u || $u['role'] !== 'professor') redirect('login.php');

$return = (string) ($_POST['return'] ?? '');
if (!str_starts_with($return, '/') || str_starts_with($return, '//')) {
    $return = url($u['role'] === 'professor' ? 'dashboard.php' : 'student/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pid = professor_id();
    $requested = (int) ($_POST['term_id'] ?? 0);
    if (!q('SELECT 1 FROM terms WHERE id = ? AND professor_id = ?', [$requested, $pid])->fetchColumn()) {
        flash('That academic term does not belong to your workspace.', 'error');
        header('Location: ' . $return);
        exit;
    }
    q('UPDATE terms SET is_current = 0 WHERE professor_id = ?', [$pid]);
    q('UPDATE terms SET is_current = 1 WHERE id = ? AND professor_id = ?', [$requested, $pid]);
    flash('Academic term changed.');
}
header('Location: ' . $return);
exit;
