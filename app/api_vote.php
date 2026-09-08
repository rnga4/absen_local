<?php
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method tidak diizinkan.']);
    exit;
}

function respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'login':
        $user = trim($_POST['username'] ?? '');
        $pass = $_POST['password'] ?? '';

        $mkSession = function () {
            session_regenerate_id(true);
        };
        $afterLogin = function (array $extra = []) {
            if (($_POST['intent'] ?? '') === 'profile') {
                $_SESSION['open_profile'] = 1;
            }
            respond(array_merge(['success' => true], $extra));
        };

        $loginOk = false;
        $db = local_db();
        if ($db !== null) {
            $st = $db->prepare("SELECT username, password_hash, algo, role, name FROM users WHERE username = :u AND is_active = 1 LIMIT 1");
            $st->execute([':u' => $user]);
            $row = $st->fetch();
            if ($row) {
                $ok = ($row['algo'] ?? '') === 'pbkdf2_sha256'
                    ? verify_pbkdf2($pass, $row['password_hash'])
                    : password_verify($pass, $row['password_hash']);
                if ($ok) {
                    $mkSession();
                    $_SESSION['logged_in'] = true;
                    $_SESSION['role'] = $row['role'];
                    $_SESSION['username'] = $row['username'];
                    if (($row['role'] ?? '') === 'employee') {
                        $ec = $db->prepare("SELECT emp_code FROM users WHERE username = :u");
                        $ec->execute([':u' => $user]);
                        $_SESSION['emp_code'] = $ec->fetchColumn() ?: '';
                    }
                    $loginOk = true;
                    $afterLogin(['name' => $row['name'] ?: $row['username'], 'role' => $row['role']]);
                }
            }
        }

        if (!$loginOk && hash_equals(APP_USER, $user) && hash_equals(APP_PASS, $pass)) {
            $mkSession();
            $_SESSION['logged_in'] = true;
            $_SESSION['role'] = 'admin';
            $_SESSION['username'] = $user;
            $afterLogin(['name' => $user, 'role' => 'admin']);
        }

        respond(['success' => false, 'message' => 'Username atau password salah.'], 401);

    case 'logout':
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        respond(['success' => true]);

    case 'vote':
        if (empty($_SESSION['logged_in'])) {
            respond(['success' => false, 'message' => 'Login dulu untuk memberi vote.'], 401);
        }

        $empCode = trim($_POST['emp_code'] ?? '');

        $valid = false;
        foreach (active_employees() as $emp) {
            if ($emp['emp_code'] === $empCode) {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            respond(['success' => false, 'message' => 'Karyawan tidak dikenal.'], 400);
        }

        $result = toggle_love($_SESSION['username'], $empCode);
        if (!$result['success']) {
            respond($result, 500);
        }
        respond($result);

    default:
        respond(['success' => false, 'message' => 'Aksi tidak dikenal.'], 400);
}