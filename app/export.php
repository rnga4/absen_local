<?php
require __DIR__ . '/config.php';
require_login();

if (($_SESSION['role'] ?? '') === 'employee') {
    http_response_code(403);
    exit;
}

function csv_safe_text(string $value): string
{
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
}

$today = date('Y-m-d');
$date  = request_date($_GET['d'] ?? null);

$employees = active_employees();

$punches = [];
$st = db()->prepare("SELECT emp_code, punch_time FROM iclock_transaction_backup WHERE punch_time >= :d AND punch_time < DATE_ADD(:d, INTERVAL 1 DAY) ORDER BY punch_time ASC");
$st->execute([':d' => $date]);
foreach ($st->fetchAll() as $r) {
    $punches[$r['emp_code']][] = $r['punch_time'];
}

$rows = [];
foreach ($employees as $emp) {
    $code = $emp['emp_code'];
    $list = $punches[$code] ?? [];
    $in = $out = null;
    foreach ($list as $pt) {
        $t = date('H:i', strtotime($pt));
        if ($t <= '13:00' && $in === null) { $in = $t; }
        elseif ($in !== null && $t > $in)  { $out = $t; }
    }
    $lateMin = 0;
    if ($in !== null && $in > '08:00') {
        $lateMin = (strtotime('1970-01-01 ' . $in) - strtotime('1970-01-01 08:00')) / 60;
    }
    $status = $in === null ? 'Belum' : ($in > '08:00' ? 'Telat' : 'Hadir');
    $rows[] = [
        'No'         => count($rows) + 1,
        'Nama'       => csv_safe_text($emp['first_name']),
        'Departemen' => csv_safe_text($emp['dept_name'] ?? '-'),
        'Masuk'      => $in  ?? '-',
        'Keluar'     => $out ?? '-',
        'Status'     => $status,
        'Telat (m)'  => $lateMin > 0 ? $lateMin : '-',
    ];
}

// Output CSV
$filename = 'absensi_' . $date . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8 biar Excel gak rusak
fputcsv($out, array_keys($rows[0]));
foreach ($rows as $row) fputcsv($out, $row);
fclose($out);
exit;
