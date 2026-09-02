<?php
require __DIR__ . '/config.php';
require_login();

header('Content-Type: application/json');

$empCode = trim($_GET['code'] ?? '');
if ($empCode === '') {
    echo json_encode(['success' => false, 'error' => 'Parameter code required']);
    exit;
}

$offset    = max(0, (int)($_GET['offset'] ?? 0));
$limit     = 30; // 30 hari per halaman
$startDate = trim($_GET['start_date'] ?? '');
$endDate   = trim($_GET['end_date'] ?? '');

// Fetch employee info
$stEmp = db()->prepare(
    "SELECT a.emp_code, a.first_name, a.department_id, b.dept_name
     FROM personnel_employee a
     LEFT JOIN personnel_department b ON b.dept_code = a.department_id
     WHERE a.emp_code = :code LIMIT 1"
);
$stEmp->execute([':code' => $empCode]);
$employee = $stEmp->fetch();

if (!$employee) {
    echo json_encode(['success' => false, 'error' => 'Karyawan tidak ditemukan']);
    exit;
}

$whereClause = "WHERE emp_code = :code";
$params = [':code' => $empCode];

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    $whereClause .= " AND DATE(punch_time) >= :startDate";
    $params[':startDate'] = $startDate;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    $whereClause .= " AND DATE(punch_time) <= :endDate";
    $params[':endDate'] = $endDate;
}

// Count total distinct dates matching criteria
$stTotal = db()->prepare(
    "SELECT COUNT(DISTINCT DATE(punch_time)) as total_days 
     FROM iclock_transaction_backup 
     {$whereClause}"
);
$stTotal->execute($params);
$totalDays = (int)($stTotal->fetch()['total_days'] ?? 0);

// Fetch distinct dates for current page
$stDates = db()->prepare(
    "SELECT DISTINCT DATE(punch_time) as punch_date 
     FROM iclock_transaction_backup 
     {$whereClause}
     ORDER BY punch_date DESC 
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $k => $v) {
    $stDates->bindValue($k, $v, PDO::PARAM_STR);
}
$stDates->bindValue(':limit', $limit, PDO::PARAM_INT);
$stDates->bindValue(':offset', $offset, PDO::PARAM_INT);
$stDates->execute();
$dateRows = $stDates->fetchAll();

if (empty($dateRows)) {
    echo json_encode([
        'success'     => true,
        'employee'    => [
            'emp_code' => $employee['emp_code'],
            'name'     => $employee['first_name'],
            'dept'     => $employee['dept_name'] ?? '-'
        ],
        'history'     => [],
        'has_more'    => false,
        'next_offset' => $offset,
        'total_days'  => 0
    ]);
    exit;
}

$targetDates = array_column($dateRows, 'punch_date');
$minDate = min($targetDates);
$maxDate = max($targetDates);

// Fetch all punches for target dates
$stPunches = db()->prepare(
    "SELECT punch_time 
     FROM iclock_transaction_backup 
     WHERE emp_code = :code 
       AND DATE(punch_time) >= :minD 
       AND DATE(punch_time) <= :maxD 
     ORDER BY punch_time DESC"
);
$stPunches->execute([
    ':code' => $empCode,
    ':minD' => $minDate,
    ':maxD' => $maxDate
]);
$rawPunches = $stPunches->fetchAll();

// Group punches by date
$byDate = [];
foreach ($rawPunches as $row) {
    $pt = $row['punch_time'];
    $date = date('Y-m-d', strtotime($pt));
    $byDate[$date][] = $pt;
}

// Format history rows
$history = [];
foreach ($targetDates as $date) {
    $list = $byDate[$date] ?? [];
    sort($list);
    
    $in = null;
    $out = null;
    foreach ($list as $pt) {
        $t = date('H:i', strtotime($pt));
        if ($t <= '13:00' && $in === null) {
            $in = $t;
        } elseif ($in !== null && $t > $in) {
            $out = $t;
        }
    }

    $lateMin = 0;
    if ($in !== null && $in > '08:00') {
        $lateMin = (strtotime('1970-01-01 ' . $in) - strtotime('1970-01-01 08:00')) / 60;
    }

    if ($in === null) {
        $status = 'belum';
    } else {
        $status = $in > '08:00' ? 'telat' : 'hadir';
    }

    $punchTimesFormatted = array_map(function($pt) {
        return date('H:i:s', strtotime($pt));
    }, $list);

    $history[] = [
        'date'           => $date,
        'date_formatted' => date('d M Y', strtotime($date)),
        'day_name'       => date('l', strtotime($date)),
        'in'             => $in ?? '-',
        'out'            => $out ?? '-',
        'status'         => $status,
        'late'           => $lateMin,
        'punches'        => $punchTimesFormatted
    ];
}

$nextOffset = $offset + count($targetDates);
$hasMore    = $nextOffset < $totalDays;

echo json_encode([
    'success'     => true,
    'employee'    => [
        'emp_code' => $employee['emp_code'],
        'name'     => $employee['first_name'],
        'dept'     => $employee['dept_name'] ?? '-'
    ],
    'history'     => $history,
    'has_more'    => $hasMore,
    'next_offset' => $nextOffset,
    'total_days'  => $totalDays
]);
