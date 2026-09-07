<?php
require __DIR__ . '/config.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$role = $_SESSION['role'] ?? '';

if ($role === 'employee') {
    $empCode = $_SESSION['emp_code'] ?? '';
    if ($empCode === '') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Sesi tidak valid']);
        exit;
    }

    // Employee info
    $stEmp = db()->prepare(
        "SELECT a.emp_code, a.first_name, a.department_id, b.dept_name
         FROM personnel_employee a
         LEFT JOIN personnel_department b ON b.dept_code = a.department_id
         WHERE a.emp_code = :code LIMIT 1"
    );
    $stEmp->execute([':code' => $empCode]);
    $emp = $stEmp->fetch();

    // Today status
    $today = date('Y-m-d');
    $stP = db()->prepare(
        "SELECT punch_time FROM iclock_transaction_backup
         WHERE emp_code = :code AND DATE(punch_time) = :d ORDER BY punch_time ASC"
    );
    $stP->execute([':code' => $empCode, ':d' => $today]);
    $punches = array_column($stP->fetchAll(), 'punch_time');

    $in = $out = null;
    foreach ($punches as $pt) {
        $t = date('H:i', strtotime($pt));
        if ($t <= '13:00' && $in === null) $in = $t;
        elseif ($in !== null && $t > $in) $out = $t;
    }
    $lateMin = 0;
    if ($in !== null && $in > '08:00') {
        $lateMin = (strtotime('1970-01-01 ' . $in) - strtotime('1970-01-01 08:00')) / 60;
    }
    $status = $in === null ? 'belum' : ($in > '08:00' ? 'telat' : 'hadir');

    echo json_encode([
        'success' => true,
        'role'    => 'employee',
        'employee' => [
            'emp_code' => $emp['emp_code'] ?? $empCode,
            'name'     => $emp['first_name'] ?? '-',
            'dept'     => $emp['dept_name'] ?? '-',
        ],
        'today' => [
            'date'   => $today,
            'in'     => $in ?? '-',
            'out'    => $out ?? '-',
            'status' => $status,
            'late'   => (int)$lateMin,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- ADMIN PATH ---
$today = date('Y-m-d');
if (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) {
    $today = $_GET['date'];
}
$filter = $_GET['f'] ?? '';
if (!in_array($filter, ['hadir', 'telat', 'belum'], true)) {
    $filter = '';
}

$employees = active_employees();

$punches = [];
$st = db()->prepare(
    "SELECT emp_code, punch_time FROM iclock_transaction_backup
     WHERE DATE(punch_time) = :d ORDER BY punch_time ASC"
);
$st->execute([':d' => $today]);
foreach ($st->fetchAll() as $r) {
    $punches[$r['emp_code']][] = $r['punch_time'];
}

$rows = [];
$stat = ['total' => 0, 'hadir' => 0, 'telat' => 0, 'belum' => 0];
foreach ($employees as $emp) {
    $code = $emp['emp_code'];
    $list = $punches[$code] ?? [];
    $in = $out = null;
    foreach ($list as $pt) {
        $t = date('H:i', strtotime($pt));
        if ($t <= '13:00' && $in === null) $in = $t;
        elseif ($in !== null && $t > $in) $out = $t;
    }
    $lateMin = 0;
    if ($in !== null && $in > '08:00') {
        $lateMin = (strtotime('1970-01-01 ' . $in) - strtotime('1970-01-01 08:00')) / 60;
    }
    $status = $in === null ? 'belum' : ($in > '08:00' ? 'telat' : 'hadir');

    if ($filter !== '' && $status !== $filter) continue;

    $stat['total']++;
    $stat[$status]++;
    $rows[$emp['dept_name'] ?? '-'][] = [
        'code'   => $emp['emp_code'],
        'name'   => $emp['first_name'],
        'in'     => $in ?? '-',
        'out'    => $out ?? '-',
        'status' => $status,
        'late'   => (int)$lateMin,
    ];
}

$departments = [];
foreach ($rows as $dept => $list) {
    $departments[] = ['department' => $dept, 'employees' => $list];
}

echo json_encode([
    'success'     => true,
    'role'        => 'admin',
    'date'        => $today,
    'stat'        => $stat,
    'departments' => $departments,
], JSON_UNESCAPED_UNICODE);
