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

function verify_pbkdf2(string $password, string $stored): bool
{
    $parts = explode('$', $stored);
    if (count($parts) !== 4 || $parts[0] !== 'pbkdf2_sha256') {
        return false;
    }
    $iterations = (int)$parts[1];
    $salt = $parts[2];
    $expected = $parts[3];
    $calc = base64_encode(hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true));
    return hash_equals($expected, $calc);
}

function local_db(): ?PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $file = __DIR__ . '/data/users.sqlite';
        if (!is_file($file)) {
            return null;
        }
        $pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function local_user(string $username): ?array
{
    $db = local_db();
    if ($db === null) {
        return null;
    }
    $st = $db->prepare("SELECT * FROM users WHERE username = :u LIMIT 1");
    $st->execute([':u' => $username]);
    return $st->fetch() ?: null;
}

function verify_user_password(string $password, string $hash, string $algo): bool
{
    return $algo === 'pbkdf2_sha256'
        ? verify_pbkdf2($password, $hash)
        : password_verify($password, $hash);
}

function photo_dir(): string
{
    $dir = __DIR__ . '/data/photos';
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    return $dir;
}

function photo_path(string $username): ?string
{
    $user = local_user($username);
    $photo = $user['photo'] ?? '';
    if ($photo === '') {
        return null;
    }
    $base = basename(photo_dir() . '/' . $photo);
    $path = photo_dir() . '/' . $base;
    return is_file($path) ? $path : null;
}

function flash_set(string $type, string $text): void
{
    $_SESSION['flash'] = ['type' => $type, 'text' => $text];
}

function flash_out(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function toast_js(): string
{
    $f = flash_out();
    if ($f === null) {
        return '';
    }
    $type = match ($f['type']) {
        'success', 'ok'     => 'success',
        'error', 'err'      => 'error',
        'warning'           => 'warning',
        default             => 'info',
    };
    $title = json_encode($f['text'], JSON_UNESCAPED_UNICODE);
    return '<script>if (window.AppToast) AppToast.' . $type . '(' . $title . ');</script>';
}

// Cari username (login app) yang punya foto & terhubung ke emp_code tertentu.
function photo_user_for_emp(string $empCode): ?string
{
    $db = local_db();
    if ($db === null) {
        return null;
    }
    $st = $db->prepare(
        "SELECT username, photo FROM users
         WHERE emp_code = :ec AND photo IS NOT NULL AND photo <> '' LIMIT 1"
    );
    $st->execute([':ec' => $empCode]);
    $r = $st->fetch();
    if (!$r) {
        return null;
    }
    $path = photo_dir() . '/' . basename($r['photo']);
    return is_file($path) ? $r['username'] : null;
}

// URL foto untuk sebuah emp_code. Jika perlu publik (dashboard publik), set $public.
function emp_photo_url(string $empCode, bool $public = false): ?string
{
    $u = photo_user_for_emp($empCode);
    if ($u === null) {
        return null;
    }
    return 'photo.php?u=' . rawurlencode($u) . ($public ? '&pub=1' : '');
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
