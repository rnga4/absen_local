<?php
require __DIR__ . '/config.php';

function notifyTelegram(string $message): bool {
    if (!TELEGRAM_BOT_TOKEN || !TELEGRAM_CHAT_ID) return false;
    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";
    $context = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => 'Content-Type: application/json',
        'content' => json_encode(['chat_id' => TELEGRAM_CHAT_ID, 'text' => $message, 'parse_mode' => 'Markdown']),
        'timeout' => 3,
    ]]);
    return @file_get_contents($url, false, $context) !== false;
}

$today = date('Y-m-d');

$employees = db()->query(
    "SELECT a.emp_code, a.first_name FROM personnel_employee a WHERE a.department_id <> 1"
)->fetchAll();

$st = db()->prepare(
    "SELECT DISTINCT emp_code FROM iclock_transaction_backup WHERE punch_time >= :d AND punch_time < DATE_ADD(:d, INTERVAL 1 DAY)"
);
$st->execute([':d' => $today]);
$present = array_column($st->fetchAll(), 'emp_code', 'emp_code');

$notRegistered = array_filter($employees, fn($e) => !isset($present[$e['emp_code']]));

if (empty($notRegistered)) {
    echo "Semua karyawan sudah absen.\n";
    exit(0);
}

$msg  = "*\[ABSENSI BELUM MASUK\]*\n";
$msg .= "Tanggal: " . date('l, d F Y') . "\n";
$msg .= "Pukul: "   . date('H:i') . "\n";
$msg .= "Total: "   . count($notRegistered) . " orang\n\n";
foreach ($notRegistered as $emp) $msg .= "- {$emp['first_name']} ({$emp['emp_code']})\n";

$ok = notifyTelegram($msg);
echo $ok ? "Notif terkirim.\n" : "Gagal kirim notif.\n";
