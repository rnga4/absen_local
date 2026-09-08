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
    photo         TEXT,
    is_active     INTEGER NOT NULL DEFAULT 1,
    created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
)
");

// Migrasi idempotent: tambah kolom photo kalau belum ada.
try {
    $pdo->query("SELECT photo FROM users LIMIT 1");
} catch (Throwable $e) {
    $pdo->exec("ALTER TABLE users ADD COLUMN photo TEXT");
    echo "migrasi: kolom photo ditambahkan\n";
}

// Migrasi idempotent: tabel votes (single 'love' per user per emp per hari).
// Jika schema lama (like/dislike, ada kolom vote_type) terdeteksi, tabel dibuat ulang.
$oldSql = strtolower((string) $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'votes'")->fetchColumn());
if ($oldSql !== '' && strpos($oldSql, 'vote_type') !== false) {
    $pdo->exec("DROP TABLE votes");
    echo "migrasi: tabel votes dibuat ulang (single love)\n";
}
$pdo->exec("
CREATE TABLE IF NOT EXISTS votes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    username   TEXT    NOT NULL,
    emp_code   TEXT    NOT NULL,
    vote_date  TEXT    NOT NULL,
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
)
");
try {
    $exists = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'idx_votes_u_e_d'")->fetchColumn();
    if (!$exists) {
        $pdo->exec("CREATE UNIQUE INDEX idx_votes_u_e_d ON votes (username, emp_code, vote_date)");
        echo "migrasi: index votes dibuat\n";
    }
} catch (Throwable $e) {
    echo "migrasi: index votes gagal -> " . $e->getMessage() . "\n";
}

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