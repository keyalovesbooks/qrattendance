<?php
require __DIR__ . '/config.php';

$_SESSION = [];
$past = ['expires' => time() - 3600, 'path' => '/', 'secure' => IS_HTTPS, 'httponly' => true, 'samesite' => 'Lax'];
setcookie(session_name(), '', $past);          // session / remember-me cookie
setcookie('qr_remember_email', '', $past);     // remembered email
setcookie('qr_device', '', $past);             // device cookie
session_destroy();
redirect('login.php');
