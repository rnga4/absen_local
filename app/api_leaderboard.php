<?php
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
if ($limit <= 0) {
    $limit = 10;
}
if ($limit > 50) {
    $limit = 50;
}

$deptFilter = isset($_GET['dept']) ? trim($_GET['dept']) : '';

$year = isset($_GET['year']) && preg_match('/^\d{4}$/', $_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$nowYear = (int)date('Y');
$start = new DateTime($year . '-01-01', new DateTimeZone('Asia/Jakarta'));
if ($year >= $nowYear) {
    $end = new DateTime('today', new DateTimeZone('Asia/Jakarta'));
} else {
    $end = new DateTime($year . '-12-31', new DateTimeZone('Asia/Jakarta'));
}

// Build list of workdays (Mon-Sat)
$workdays = [];
$cur = clone $start;
while ($cur <= $end) {
    $w = (int)$cur->format('w'); // 0 Sun, 1 Mon, ..., 6 Sat
    if ($w >= 1 && $w <= 6) {
        $workdays[] = $cur->format('Y-m-d');
    }
    $cur->modify('+1 day');
}
$workdaysCount = count($workdays);

// Get active employees
$employees = active_employees();

// Get all punches in range grouped by emp_code and date
$punchMap = [];
$st = db()->prepare(
    "SELECT emp_code, DATE(punch_time) as pdate
     FROM iclock_transaction_backup
     WHERE punch_time >= :start AND punch_time < DATE_ADD(:end, INTERVAL 1 DAY)
     ORDER BY punch_time ASC"
);
$st->execute([':start' => $start->format('Y-m-d'), ':end' => $end->format('Y-m-d')]);
foreach ($st->fetchAll() as $r) {
    $code = $r['emp_code'];
    $d = $r['pdate'];
    $w = (int)date('w', strtotime($d));
    if ($w >= 1 && $w <= 6) {
        if (!isset($punchMap[$code])) {
            $punchMap[$code] = [];
        }
        $punchMap[$code][$d] = true;
    }
}

// Build leaderboard
$leaderboardAll = [];
foreach ($employees as $emp) {
    $code = $emp['emp_code'];
    $empDept = $emp['dept_name'] ?? '-';
    if ($deptFilter !== '' && $empDept !== $deptFilter) {
        continue;
    }
    $presentSet = $punchMap[$code] ?? [];
    $presentDays = count($presentSet);
    // calculate streak (consecutive Mon-Sat days up to end with present)
    $streak = 0;
    $curChk = clone $end;
    while ($curChk >= $start) {
        $cw = (int)$curChk->format('w');
        if ($cw >= 1 && $cw <= 6) {
            $cd = $curChk->format('Y-m-d');
            if (isset($presentSet[$cd])) {
                $streak++;
                $curChk->modify('-1 day');
                continue;
            } else {
                break;
            }
        }
        $curChk->modify('-1 day');
    }
    $leaderboardAll[] = [
        'emp_code' => $code,
        'name' => $emp['first_name'],
        'dept_name' => $empDept,
        'photo_url' => emp_photo_url($code, true),
        'present_days' => $presentDays,
        'workdays' => $workdaysCount,
        'rate' => $workdaysCount > 0 ? round(($presentDays / $workdaysCount) * 100) : 0,
        'streak' => $streak,
        'perfect' => ($workdaysCount > 0 && $presentDays >= $workdaysCount),
    ];
}

// Sort
usort($leaderboardAll, function($a, $b) {
    if ($a['present_days'] === $b['present_days']) {
        return strcmp($a['name'], $b['name']);
    }
    return $b['present_days'] <=> $a['present_days'];
});

// Add rank
foreach ($leaderboardAll as $i => &$row) {
    $row['rank'] = $i + 1;
}
unset($row);

// Find me
$meEmpCode = $_SESSION['emp_code'] ?? '';
$meRow = null;
$meInTop = false;
if ($meEmpCode !== '') {
    foreach ($leaderboardAll as $r) {
        if ((string)$r['emp_code'] === (string)$meEmpCode) {
            $meRow = $r;
            break;
        }
    }
    if ($meRow !== null && $meRow['rank'] <= $limit) {
        $meInTop = true;
    }
}

// Limit for top list
$leaderboard = $leaderboardAll;
if ($limit > 0 && count($leaderboard) > $limit) {
    $leaderboard = array_slice($leaderboard, 0, $limit);
}

$departments = [];
$allDepts = array_unique(array_column($employees, 'dept_name'));
sort($allDepts);
foreach ($allDepts as $d) {
    if ($d === null || $d === '') continue;
    $departments[] = $d;
}

echo json_encode([
    'success' => true,
    'year' => $year,
    'period' => [
        'start' => $start->format('Y-m-d'),
        'end' => $end->format('Y-m-d'),
        'end_label' => $end->format('d M Y'),
    ],
    'workdays' => $workdaysCount,
    'leaderboard' => $leaderboard,
    'me' => ($meRow !== null && !$meInTop) ? $meRow : null,
    'departments' => $departments,
    'dept_filter' => $deptFilter,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
