<?php
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$today = date('Y-m-d');
$employees = active_employees();

$st = db()->prepare("SELECT DISTINCT emp_code FROM iclock_transaction_backup WHERE punch_time >= :d AND punch_time < DATE_ADD(:d, INTERVAL 1 DAY)");
$st->execute([':d' => $today]);
$present = array_column($st->fetchAll(), 'emp_code', 'emp_code');

$notAbsen = [];
foreach ($employees as $emp) {
    if (!isset($present[$emp['emp_code']])) {
        $dept = $emp['dept_name'] ?? '-';
        $notAbsen[$dept][] = [
            'emp_code' => $emp['emp_code'],
            'name'     => $emp['first_name'],
        ];
    }
}

$total = array_sum(array_map('count', $notAbsen));
$allPresent = empty($notAbsen);

$departments = [];
foreach ($notAbsen as $dept => $list) {
    $departments[] = [
        'department' => $dept,
        'employees'  => $list,
    ];
}

echo json_encode([
    'date'        => date('Y-m-d'),
    'time'        => date('H:i:s'),
    'all_present' => $allPresent,
    'total_not_absen' => $total,
    'departments' => $departments,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
