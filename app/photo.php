<?php
require __DIR__ . '/config.php';
require_login();

$u = trim($_GET['u'] ?? '');
if ($u === '') {
    http_response_code(400);
    exit;
}

// Role guard: employee hanya boleh lihat foto sendiri, admin boleh semua.
$roleNow = $_SESSION['role'] ?? 'admin';
if ($roleNow === 'employee' && ($_SESSION['username'] ?? '') !== $u) {
    http_response_code(403);
    exit('Akses ditolak');
}

$path = photo_path($u);
if ($path === null) {
    http_response_code(404);
    exit;
}

$mime = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
];
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
header('Content-Type: ' . ($mime[$ext] ?? 'application/octet-stream'));
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . filesize($path));
readfile($path);