<?php
date_default_timezone_set('Asia/Jakarta');
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
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

// ---- Like/love dashboard publik (SQLite) ----

function votes_db(): ?PDO
{
    return local_db();
}

function votes_today_counts(): array
{
    $db = votes_db();
    if ($db === null) {
        return [];
    }
    $st = $db->query("SELECT emp_code, COUNT(*) AS n FROM votes GROUP BY emp_code");
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[$r['emp_code']] = (int) $r['n'];
    }
    return $out;
}

function my_votes_today(?string $username): array
{
    $db = votes_db();
    if ($db === null || $username === null || $username === '') {
        return [];
    }
    $st = $db->prepare("SELECT emp_code FROM votes WHERE username = :u AND vote_date = :d");
    $st->execute([':u' => $username, ':d' => date('Y-m-d')]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[$r['emp_code']] = true;
    }
    return $out;
}

// Toggle love: sudah ada -> hapus, belum -> tambah. Return array status utk response client.
function toggle_love(string $username, string $empCode): array
{
    $db = votes_db();
    if ($db === null) {
        return ['success' => false, 'message' => 'Database lokal tidak tersedia.'];
    }
    $today = date('Y-m-d');

    $st = $db->prepare("SELECT id FROM votes WHERE username = :u AND emp_code = :e AND vote_date = :d");
    $st->execute([':u' => $username, ':e' => $empCode, ':d' => $today]);
    $existing = $st->fetch();

    if ($existing) {
        $db->prepare("DELETE FROM votes WHERE id = :id")->execute([':id' => $existing['id']]);
        $state = 'removed';
    } else {
        $db->prepare("INSERT INTO votes (username, emp_code, vote_date) VALUES (:u, :e, :d)")
            ->execute([':u' => $username, ':e' => $empCode, ':d' => $today]);
        $state = 'added';
    }

    $count = votes_today_counts()[$empCode] ?? 0;
    return [
        'success'    => true,
        'state'      => $state,
        'my_vote'    => $state === 'added',
        'love_count' => $count,
    ];
}

// Breadcrumb: daftar item ['label' => ..., 'href' => ...] (yang terakhir tanpa href = halaman aktif).
function breadcrumb(array $items): void
{
    echo '<nav class="breadcrumb" aria-label="Breadcrumb">';
    $n = count($items);
    foreach ($items as $i => $item) {
        $label = $item['label'];
        $href  = $item['href'] ?? '';
        if ($i === $n - 1) {
            echo '<span class="crumb-current">' . e($label) . '</span>';
        } elseif ($href !== '') {
            echo '<a class="crumb-link" href="' . e($href) . '">' . e($label) . '</a>';
            echo '<span class="crumb-sep">/</span>';
        } else {
            echo '<span class="crumb-current">' . e($label) . '</span>';
            echo '<span class="crumb-sep">/</span>';
        }
    }
    echo '</nav>';
}

// ---- Counter pengunjung anonim public.php (SQLite) ----

function public_visit_ensure_table(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS public_visits (
            id          INTEGER PRIMARY KEY CHECK(id = 1),
            total_count INTEGER NOT NULL DEFAULT 0,
            today_count INTEGER NOT NULL DEFAULT 0,
            today_date  TEXT    NOT NULL DEFAULT ''
        )"
    );
    $db->exec("INSERT OR IGNORE INTO public_visits (id, total_count, today_count, today_date) VALUES (1, 0, 0, '')");
}

function public_visit_stats(): array
{
    $db = local_db();
    if ($db === null) {
        return ['today' => 0, 'total' => 0];
    }
    try {
        $row = $db->query("SELECT total_count, today_count, today_date FROM public_visits WHERE id = 1")->fetch();
        if (!$row) {
            return ['today' => 0, 'total' => 0];
        }
        $today = date('Y-m-d');
        $todayCount = ($row['today_date'] === $today) ? (int)$row['today_count'] : 0;
        return ['today' => $todayCount, 'total' => (int)$row['total_count']];
    } catch (Throwable $e) {
        return ['today' => 0, 'total' => 0];
    }
}

function public_visit_bump(): array
{
    $db = local_db();
    if ($db === null) {
        return ['today' => 0, 'total' => 0];
    }
    $today = date('Y-m-d');
    try {
        $db->setAttribute(PDO::ATTR_TIMEOUT, 2);
        $db->exec('PRAGMA busy_timeout = 2000');
        public_visit_ensure_table($db);
        $st = $db->prepare(
            "UPDATE public_visits
             SET total_count = total_count + 1,
                 today_count = CASE WHEN today_date = :today THEN today_count + 1 ELSE 1 END,
                 today_date  = :today
             WHERE id = 1"
        );
        $st->execute([':today' => $today]);
        if ($st->rowCount() === 0) {
            $db->prepare(
                "INSERT INTO public_visits (id, total_count, today_count, today_date) VALUES (1, 1, 1, :today)"
            )->execute([':today' => $today]);
        }
        $row = $db->query("SELECT total_count, today_count, today_date FROM public_visits WHERE id = 1")->fetch();
        if (!$row) {
            return ['today' => 0, 'total' => 0];
        }
        $todayCount = ($row['today_date'] === $today) ? (int)$row['today_count'] : 0;
        return ['today' => $todayCount, 'total' => (int)$row['total_count']];
    } catch (Throwable $e) {
        return public_visit_stats();
    }
}

// ---- Log pengunjung anonim per IP (SQLite) ----

function trusted_proxies(): array
{
    static $list = null;
    if ($list === null) {
        $raw = getenv('TRUSTED_PROXIES');
        if ($raw === false || trim($raw) === '') {
            $raw = '127.0.0.1,::1';
        }
        $list = [];
        foreach (explode(',', $raw) as $item) {
            $item = trim($item);
            if ($item !== '') {
                $list[] = strtolower($item);
            }
        }
    }
    return $list;
}

function normalize_ip(string $ip): ?string
{
    $ip = trim($ip);
    if ($ip !== '' && $ip[0] === '[') {
        $end = strpos($ip, ']');
        if ($end !== false) {
            $ip = substr($ip, 1, $end - 1);
        }
    }
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return null;
    }
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return null;
    }
    $canon = @inet_ntop($bin);
    if ($canon === false) {
        return null;
    }
    if (strpos($canon, '::ffff:') === 0
        && filter_var(substr($canon, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $canon = substr($canon, 7);
    }
    return $canon;
}

function client_ip(): string
{
    $remote = normalize_ip($_SERVER['REMOTE_ADDR'] ?? '');
    if ($remote === null) {
        return '0.0.0.0';
    }
    if (!in_array(strtolower($remote), trusted_proxies(), true)) {
        return $remote;
    }
    $cf = normalize_ip($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
    if ($cf !== null) {
        return $cf;
    }
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        foreach (explode(',', $xff) as $part) {
            $cand = normalize_ip($part);
            if ($cand !== null) {
                return $cand;
            }
        }
    }
    return $remote;
}

function public_visit_ip_ensure_table(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $db->exec(
        "CREATE TABLE IF NOT EXISTS public_visit_ips (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            ip         TEXT    NOT NULL,
            visit_date TEXT    NOT NULL,
            hits       INTEGER NOT NULL DEFAULT 0,
            first_seen TEXT    NOT NULL DEFAULT '',
            last_seen  TEXT    NOT NULL DEFAULT '',
            user_agent TEXT
        )"
    );
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_public_visit_ips_ip_date ON public_visit_ips (ip, visit_date)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_public_visit_ips_date ON public_visit_ips (visit_date)");
    $db->exec("CREATE TABLE IF NOT EXISTS app_meta (k TEXT PRIMARY KEY, v TEXT)");
    $done = true;
}

function public_visit_ip_prune(PDO $db): void
{
    try {
        $today = date('Y-m-d');
        $st = $db->prepare("SELECT v FROM app_meta WHERE k = 'visit_ip_prune_date' LIMIT 1");
        $st->execute();
        if ($st->fetchColumn() === $today) {
            return;
        }
        $db->prepare("DELETE FROM public_visit_ips WHERE visit_date < :d")
            ->execute([':d' => date('Y-m-d', strtotime('-30 days'))]);
        $db->prepare(
            "INSERT INTO app_meta (k, v) VALUES ('visit_ip_prune_date', :d)
             ON CONFLICT(k) DO UPDATE SET v = excluded.v"
        )->execute([':d' => $today]);
    } catch (Throwable $e) {
        // prune bukan kritikal
    }
}

function public_visit_ip_log(string $ip, string $userAgent = ''): void
{
    $db = local_db();
    if ($db === null) {
        return;
    }
    $ip = normalize_ip($ip);
    if ($ip === null) {
        return;
    }
    $ua = trim($userAgent);
    if (mb_strlen($ua) > 255) {
        $ua = mb_substr($ua, 0, 255);
    }
    $today = date('Y-m-d');
    $now   = date('Y-m-d H:i:s');
    try {
        $db->setAttribute(PDO::ATTR_TIMEOUT, 2);
        $db->exec('PRAGMA busy_timeout = 2000');
        public_visit_ip_ensure_table($db);
        $db->prepare(
            "INSERT INTO public_visit_ips (ip, visit_date, hits, first_seen, last_seen, user_agent)
             VALUES (:ip, :d, 1, :now, :now, :ua)
             ON CONFLICT(ip, visit_date) DO UPDATE SET
                 hits       = hits + 1,
                 last_seen  = excluded.last_seen,
                 user_agent = excluded.user_agent"
        )->execute([':ip' => $ip, ':d' => $today, ':now' => $now, ':ua' => $ua]);
        public_visit_ip_prune($db);
    } catch (Throwable $e) {
        // jangan sampai mengganggu render halaman publik
    }
}

function public_visit_ip_summary(?string $date = null): array
{
    $db = local_db();
    if ($db === null) {
        return ['ips' => 0, 'hits' => 0];
    }
    try {
        if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $st = $db->prepare(
                "SELECT COUNT(*) AS ips, COALESCE(SUM(hits), 0) AS hits
                 FROM public_visit_ips WHERE visit_date = :d"
            );
            $st->execute([':d' => $date]);
        } else {
            $st = $db->query(
                "SELECT COUNT(DISTINCT ip) AS ips, COALESCE(SUM(hits), 0) AS hits
                 FROM public_visit_ips"
            );
        }
        $row = $st->fetch();
        return [
            'ips'  => (int) ($row['ips'] ?? 0),
            'hits' => (int) ($row['hits'] ?? 0),
        ];
    } catch (Throwable $e) {
        return ['ips' => 0, 'hits' => 0];
    }
}

function public_visit_ip_rows(?string $date = null): array
{
    $db = local_db();
    if ($db === null) {
        return [];
    }
    try {
        if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $st = $db->prepare(
                "SELECT ip, hits, first_seen, last_seen, user_agent, 1 AS days
                 FROM public_visit_ips WHERE visit_date = :d
                 ORDER BY last_seen DESC LIMIT 500"
            );
            $st->execute([':d' => $date]);
            return $st->fetchAll();
        }
        return $db->query(
            "SELECT ip,
                    SUM(hits) AS hits,
                    MIN(first_seen) AS first_seen,
                    MAX(last_seen) AS last_seen,
                    (SELECT b.user_agent FROM public_visit_ips b
                     WHERE b.ip = public_visit_ips.ip
                     ORDER BY b.last_seen DESC LIMIT 1) AS user_agent,
                    COUNT(*) AS days
             FROM public_visit_ips
             GROUP BY ip
             ORDER BY last_seen DESC LIMIT 500"
        )->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}
