<?php
require __DIR__ . '/config.php';
$u = current_user();
redirect(!$u ? 'login.php' : ($u['role'] === 'professor' ? 'dashboard.php' : 'student/index.php'));
