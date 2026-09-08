<?php
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

// Accept form-encoded (application/x-www-form-urlencoded) or JSON body.
$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: [];
$user = trim($_POST['username'] ?? ($data['username'] ?? ''));
$pass = $_POST['password'] ?? ($data['password'] ?? '');

if ($user === '' || $pass === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'username dan password wajib diisi']);
    exit;
}

// --- LOCAL USERS TABLE (server 37: SQLite app/data/users.sqlite) ---
$localUser = false;
try {
    $st = local_db()->prepare(
        "SELECT id, username, password_hash, algo, role, emp_code, name
         FROM users WHERE username = :u AND is_active = 1 LIMIT 1"
    );
    $st->execute([':u' => $user]);
    $localUser = $st->fetch();
} catch (Throwable $e) {
    $localUser = false;
}

if ($localUser) {
    $ok = $localUser['algo'] === 'pbkdf2_sha256'
        ? verify_pbkdf2($pass, $localUser['password_hash'])
        : password_verify($pass, $localUser['password_hash']);
    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['logged_in'] = true;
        $_SESSION['role'] = $localUser['role'];
        $_SESSION['username'] = $localUser['username'];
        if (!empty($localUser['emp_code'])) {
            $_SESSION['emp_code'] = $localUser['emp_code'];
        }
        if ($localUser['role'] === 'employee' && !empty($localUser['emp_code'])) {
            echo json_encode([
                'success'  => true,
                'role'     => 'employee',
                'username' => $localUser['username'],
                'name'     => $localUser['name'] ?: $localUser['username'],
                'emp_code' => $localUser['emp_code'],
                'dept'     => '-',
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'success'  => true,
                'role'     => 'admin',
                'username' => $localUser['username'],
                'name'     => $localUser['name'] ?: 'Admin',
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
}

// --- ADMIN PATH (env-based APP_USER / APP_PASS) — fallback migrasi ---
if (hash_equals(APP_USER, $user) && hash_equals(APP_PASS, $pass)) {
    session_regenerate_id(true);
    $_SESSION['logged_in'] = true;
    $_SESSION['role'] = 'admin';
    $_SESSION['username'] = $user;
    echo json_encode([
        'success'  => true,
        'role'     => 'admin',
        'username' => $user,
        'name'     => 'Admin',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- EMPLOYEE PATH (personnel_employee: emp_code + self_password) ---
// NOTE: read-only verification, DB is NOT modified.
$st = db()->prepare(
    "SELECT emp_code, first_name, department_id, self_password, b.dept_name
     FROM personnel_employee a
     LEFT JOIN personnel_department b ON b.dept_code = a.department_id
     WHERE a.emp_code = :code AND a.is_active = 1 AND a.department_id <> 1
     LIMIT 1"
);
$st->execute([':code' => $user]);
$emp = $st->fetch();

if ($emp && !empty($emp['self_password']) && verify_pbkdf2($pass, $emp['self_password'])) {
    session_regenerate_id(true);
    $_SESSION['logged_in'] = true;
    $_SESSION['role'] = 'employee';
    $_SESSION['username'] = $emp['emp_code'];
    $_SESSION['emp_code'] = $emp['emp_code'];
    echo json_encode([
        'success' => true,
        'role'    => 'employee',
        'username' => $emp['emp_code'],
        'name'    => $emp['first_name'],
        'emp_code' => $emp['emp_code'],
        'dept'    => $emp['dept_name'] ?? '-',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(401);
echo json_encode(['success' => false, 'error' => 'Username atau password salah.'], JSON_UNESCAPED_UNICODE);
