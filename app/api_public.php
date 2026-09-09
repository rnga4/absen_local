<?php
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$today = date('Y-m-d');
$employees = active_employees();

$st = db()->prepare("SELECT DISTINCT emp_code FROM iclock_transaction_backup WHERE punch_time >= :d AND punch_time < DATE_ADD(:d, INTERVAL 1 DAY)");
$st->execute([':d' => $today]);
$present = array_column($st->fetchAll(), 'emp_code', 'emp_code');

$voteCounts = votes_today_counts();
$myVotes    = my_votes_today($_SESSION['username'] ?? null);

$notAbsen = [];
foreach ($employees as $emp) {
    if (!isset($present[$emp['emp_code']])) {
        $dept = $emp['dept_name'] ?? '-';
        $code = $emp['emp_code'];
        $notAbsen[$dept][] = [
            'emp_code'   => $code,
            'name'       => $emp['first_name'],
            'love_count' => (int) ($voteCounts[$code] ?? 0),
            'my_vote'    => !empty($myVotes[$code]),
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
