<?php
date_default_timezone_set('Asia/Jakarta');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('DB_HOST', getenv('DB_HOST') ?: '192.168.1.141');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'fingerprint_absensi');
define('APP_USER', getenv('APP_USER') ?: 'admin');
define('APP_PASS', getenv('APP_PASS') ?: 'admin123');
define('TELEGRAM_BOT_TOKEN', getenv('TELEGRAM_BOT_TOKEN') ?: '');
define('TELEGRAM_CHAT_ID',   getenv('TELEGRAM_CHAT_ID')   ?: '');

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
    }
    return $pdo;
}

function require_login(): void
{
    if (empty($_SESSION['logged_in'])) {
        header('Location: login.php');
        exit;
    }
}

function e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function active_employees(): array
{
    return db()->query(
        "SELECT a.emp_code, a.first_name, a.department_id, b.dept_name
         FROM personnel_employee a
         LEFT JOIN personnel_department b ON b.dept_code = a.department_id
         WHERE a.department_id <> 1
         ORDER BY b.dept_name, a.first_name"
    )->fetchAll();
}
