<?php
require __DIR__ . '/config.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';

    $sqliteFile = __DIR__ . '/data/users.sqlite';
    $loginOk = false;

    if (is_file($sqliteFile)) {
        $sl = new PDO('sqlite:' . $sqliteFile, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $st = $sl->prepare("SELECT username, password_hash, algo, role FROM users WHERE username = :u AND is_active = 1 LIMIT 1");
        $st->execute([':u' => $user]);
        $row = $st->fetch();
        if ($row) {
            $ok = $row['algo'] === 'pbkdf2_sha256'
                ? verify_pbkdf2($pass, $row['password_hash'])
                : password_verify($pass, $row['password_hash']);
            if ($ok) {
                session_regenerate_id(true);
                $_SESSION['logged_in'] = true;
                $_SESSION['role'] = $row['role'];
                $_SESSION['username'] = $row['username'];
                if (($row['role'] ?? '') === 'employee') {
                    $ec = $sl->prepare("SELECT emp_code FROM users WHERE username = :u");
                    $ec->execute([':u' => $user]);
                    $_SESSION['emp_code'] = $ec->fetchColumn() ?: '';
                }
                $loginOk = true;
                header('Location: ' . (($_SESSION['role'] ?? '') === 'employee' ? 'employee.php' : 'index.php'));
                exit;
            }
        }
    }

    if (!$loginOk && hash_equals(APP_USER, $user) && hash_equals(APP_PASS, $pass)) {
        session_regenerate_id(true);
        $_SESSION['logged_in'] = true;
        $_SESSION['role'] = 'admin';
        $_SESSION['username'] = $user;
        header('Location: index.php');
        exit;
    }
    $error = 'Username atau password salah.';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Absensi Monitor</title>
    <link rel="stylesheet" href="assets/pl-komatsu-ui-template.css">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-body">
    <div class="login-box">
        <h1>Absensi Monitor</h1>
        <p class="login-sub">Masuk untuk memantau kehadiran karyawan</p>
        <?php if ($error): ?><p class="error-banner"><?= e($error) ?></p><?php endif; ?>
        <form method="post" autocomplete="off">
            <label>Username
                <input type="text" name="username" required autofocus>
            </label>
            <label>Password
                <input type="password" name="password" required>
            </label>
            <button type="submit">Masuk</button>
        </form>
    </div>
</body>
</html>