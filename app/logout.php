<?php
require __DIR__ . '/config.php';
$msg = ['type' => 'info', 'text' => 'Anda telah keluar. Sampai jumpa!'];
$_SESSION = [];
session_destroy();
session_start();
$_SESSION['flash'] = $msg;
header('Location: login.php');
exit;