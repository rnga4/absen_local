<?php
// Setup tabel users lokal (SQLite) di server 37.
// HANYA via CLI:  docker exec -u www-data absensi-fpm php /var/www/html/setup_users.php
// Blokir akses HTTP di nginx.conf (location = /setup_users.php deny all).

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require __DIR__ . '/config.php';

$dir = __DIR__ . '/data';
if (!is_dir($dir)) {
    mkdir($dir, 0770, true);
}

$pdo = new PDO('sqlite:' . $dir . '/users.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$pdo->exec("
CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    algo          TEXT    NOT NULL DEFAULT 'bcrypt',
    role          TEXT    NOT NULL DEFAULT 'employee'
                          CHECK (role IN ('admin','employee')),
    emp_code      TEXT,
    name          TEXT,
    is_active     INTEGER NOT NULL DEFAULT 1,
    created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
)
");

// Seed admin dari env (idempotent: jalankan ulang aman).
$hash = password_hash(APP_PASS, PASSWORD_DEFAULT);
$st = $pdo->prepare("
    INSERT INTO users (username, password_hash, algo, role, name, is_active)
    VALUES (:u, :h, 'bcrypt', 'admin', 'Admin', 1)
    ON CONFLICT(username) DO UPDATE SET
        password_hash = excluded.password_hash,
        name          = excluded.name
");
$st->execute([':u' => APP_USER, ':h' => $hash]);

echo "users table ready. admin='" . APP_USER . "' seeded (bcrypt).\n";;
echo 'db file: ' . $dir . '/users.sqlite' . "\n";