<?php
require __DIR__ . '/config.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';
    if (hash_equals(APP_USER, $user) && hash_equals(APP_PASS, $pass)) {
        $_SESSION['logged_in'] = true;
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