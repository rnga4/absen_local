<?php
// Seed akun employee ke SQLite users.sqlite dari MySQL personnel_employee.
// HANYA via CLI:  docker exec -u www-data absensi-fpm php /var/www/html/seed_employee_users.php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require __DIR__ . '/config.php';

$dir = __DIR__ . '/data';
if (!is_dir($dir)) {
    mkdir($dir, 0770, true);
}

$sqlite = new PDO('sqlite:' . $dir . '/users.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$empRows = db()->query(
    "SELECT emp_code, first_name
     FROM personnel_employee
     WHERE is_active = 1 AND department_id <> 1
     ORDER BY first_name"
)->fetchAll();

$existing = $sqlite->query("SELECT username FROM users")->fetchAll(PDO::FETCH_COLUMN);
$taken = array_flip($existing);

$count = 0;
$skipped = 0;

foreach ($empRows as $emp) {
    $empCode = $emp['emp_code'];
    $fullName = trim($emp['first_name']);
    $base = strtolower(explode(' ', $fullName)[0]);

    $username = $base;
    $n = 2;
    while (isset($taken[$username])) {
        $username = $base . $n;
        $n++;
    }
    $taken[$username] = true;

    $dup = $sqlite->prepare("SELECT 1 FROM users WHERE emp_code = :ec LIMIT 1");
    $dup->execute([':ec' => $empCode]);
    if ($dup->fetch()) {
        $skipped++;
        continue;
    }

    $hash = password_hash('unico@123', PASSWORD_DEFAULT);
    $st = $sqlite->prepare("
        INSERT INTO users (username, password_hash, algo, role, emp_code, name, is_active)
        VALUES (:u, :h, 'bcrypt', 'employee', :ec, :n, 1)
    ");
    $st->execute([':u' => $username, ':h' => $hash, ':ec' => $empCode, ':n' => $fullName]);
    $count++;
    echo "  {$username} ({$empCode}) → {$fullName}\n";
}

echo "\n--- Selesai ---\n";
echo "Akun employee dibuat : {$count}\n";
echo "Dilewati (sudah ada) : {$skipped}\n";
echo "Total di SQLite      : " . $sqlite->query("SELECT COUNT(*) FROM users")->fetchColumn() . "\n";
