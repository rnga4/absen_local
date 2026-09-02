<?php
require __DIR__ . '/config.php';
require_login();

$start = $_GET['tanggal'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
    $start = date('Y-m-d');
}

function week_from_date($start)
{
    $t = strtotime($start);
    $n = (int) date('N', $t); // 1=Senin .. 7=Minggu
    $mon = $t - ($n - 1) * 86400;
    return [
        'sabtu'  => $mon - 2 * 86400,
        'senin'  => $mon,
        'selasa' => $mon + 86400,
        'rabu'   => $mon + 2 * 86400,
        'kamis'  => $mon + 3 * 86400,
        'jumat'  => $mon + 4 * 86400,
    ];
}

$days = week_from_date($start);
$dayOrder = ['sabtu', 'senin', 'selasa', 'rabu', 'kamis', 'jumat'];
$isSaturday = ['sabtu' => true, 'senin' => false, 'selasa' => false, 'rabu' => false, 'kamis' => false, 'jumat' => false];

$rangeStart = date('Y-m-d', $days['sabtu']);
$rangeEnd   = date('Y-m-d', $days['jumat']);

$employees = active_employees();

$punches = [];
$st = db()->prepare(
    "SELECT emp_code, punch_time FROM iclock_transaction_backup
     WHERE DATE(punch_time) BETWEEN :s AND :e
     ORDER BY emp_code, punch_time ASC"
);
$st->execute([':s' => $rangeStart, ':e' => $rangeEnd]);
foreach ($st->fetchAll() as $r) {
    $punches[$r['emp_code']][] = $r['punch_time'];
}

function pen_in($t)
{
    if ($t === null) return 90000;
    if ($t > '08:00' && $t <= '09:00') return 10000;
    if ($t > '09:00' && $t <= '10:00') return 20000;
    if ($t > '10:00' && $t <= '11:00') return 30000;
    if ($t > '11:00' && $t <= '13:00') return 40000;
    if ($t > '13:00') return 90000;
    return 0;
}

function pen_out($inT, $outT, $isSat)
{
    if ($inT === null || $outT === null) return 0;
    $inH  = (int) date('H', strtotime($inT));
    $outH = (int) date('H', strtotime($outT));
    $valid = ($inH !== $outH) && ($outH > $inH + 1);
    if (!$valid) return 0;

    if ($isSat) {
        if ($outT < '15:00' && $outT >= '14:00') return 10000;
        if ($outT < '14:00' && $outT >= '12:00') return 20000;
        if ($outT < '12:00' && $outT >= '11:00') return 30000;
        if ($outT < '11:00') return 40000;
    } else {
        if ($outT < '17:00' && $outT >= '16:00') return 10000;
        if ($outT < '16:00' && $outT >= '15:00') return 20000;
        if ($outT < '15:00' && $outT >= '14:00') return 30000;
        if ($outT < '14:00') return 40000;
    }
    return 0;
}

function out_display($inT, $outT)
{
    if ($outT === null) return '-';
    $inH  = (int) date('H', strtotime($inT));
    $outH = (int) date('H', strtotime($outT));
    if ($inT === null) return '-';
    if ($inH !== $outH && $outH > $inH + 1) return $outT;
    return '-';
}

$IN_COLORS = [
    10000 => 'oklch(0.93 0.07 85 / 0.5)',
    20000 => 'oklch(0.88 0.09 70 / 0.55)',
    30000 => 'oklch(0.84 0.11 55 / 0.55)',
    40000 => 'oklch(0.8 0.13 45 / 0.6)',
    90000 => 'oklch(0.72 0.17 27 / 0.65)',
    0     => '',
];
$OUT_COLORS = [
    10000 => 'oklch(0.92 0.05 300 / 0.45)',
    20000 => 'oklch(0.88 0.07 300 / 0.5)',
    30000 => 'oklch(0.84 0.09 300 / 0.55)',
    40000 => 'oklch(0.78 0.11 300 / 0.6)',
    0     => '',
];

$deptRows = [];
$names = array_column($employees, 'first_name', 'emp_code');
$deptOf = [];
foreach ($employees as $emp) {
    $deptOf[$emp['emp_code']] = $emp['dept_name'] ?? '-';
}
$deptOrder = [];
foreach ($employees as $emp) {
    $d = $emp['dept_name'] ?? '-';
    if (!in_array($d, $deptOrder, true)) $deptOrder[] = $d;
}

$grandTotal = 0;

function compute_row($empCode, &$punches)
{
    global $days, $dayOrder, $isSaturday;
    $row = [];
    foreach ($dayOrder as $key) {
        $stamp = $days[$key];
        $day = date('Y-m-d', $stamp);
        $sat = $isSaturday[$key];
        $list = [];
        foreach (($punches[$empCode] ?? []) as $pt) {
            if (substr($pt, 0, 10) === $day) {
                $list[] = date('H:i', strtotime($pt));
            }
        }
        sort($list);
        $in = null;
        foreach ($list as $t) {
            if ($t <= '13:00') { $in = $t; break; }
        }
        $out = null;
        foreach (array_reverse($list) as $t) {
            if ($in !== null && $t > $in) { $out = $t; break; }
        }
        $penIn  = pen_in($in);
        $penOut = pen_out($in, $out, $sat);
        $row[$key] = [
            'in'     => $in,
            'outT'   => out_display($in, $out),
            'penIn'  => $penIn,
            'penOut' => $penOut,
            'pen'    => $penIn + $penOut,
            'inColor'  => $IN_COLORS[$penIn] ?? '',
            'outColor' => $OUT_COLORS[$penOut] ?? '',
        ];
    }
    return $row;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Absensi Mingguan - Absensi Monitor</title>
    <link rel="stylesheet" href="assets/pl-komatsu-ui-template.css">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <nav class="topbar">
        <span class="brand">Absensi Monitor</span>
        <label class="hamburger">
            <input type="checkbox" id="burger-toggle">
            <svg viewBox="0 0 32 32">
                <path class="line line-top-bottom" d="M27 10 13 10C10.8 10 9 8.2 9 6 9 3.5 10.8 2 13 2 15.2 2 17 3.8 17 6L17 26C17 28.2 15.2 30 13 30 10.8 30 9 28.2 9 26 9 23.8 10.8 22 13 22L27 22"></path>
                <path class="line" d="M7 16 27 16"></path>
            </svg>
        </label>
        <div class="nav-right" id="navMenu">
            <a href="index.php"><span class="nav-label">Dashboard</span></a>
            <a class="active" href="weekly.php"><span class="nav-label">Absensi Mingguan</span></a>
            <label class="theme-switch-btn" for="theme-popup-checkbox">
                <span>Tema</span>
                <span class="theme-popup__chevron">▾</span>
            </label>
            <a href="public.php" target="_blank"><span class="nav-label">Publik</span></a>
            <a href="logout.php"><span class="nav-label">Keluar</span></a>
        </div>
    </nav>

    <div class="theme-popup" id="themeSwitcher">
        <input type="checkbox" id="theme-popup-checkbox" class="theme-popup__checkbox">
        <div class="theme-popup__list-container">
            <ul class="theme-popup__list" id="themeList">
                <label data-theme=""><span>Light</span></label>
                <label data-theme="dark"><span>Dark</span></label>
                <label data-theme="theme-sakura"><span>Sakura</span></label>
                <label data-theme="theme-bamboo"><span>Bamboo</span></label>
                <label data-theme="theme-cyberpunk"><span>Cyberpunk</span></label>
                <label data-theme="theme-mingyu"><span>Mingyu</span></label>
                <label data-theme="theme-ocean"><span>Ocean</span></label>
                <label data-theme="theme-retrolight"><span>Retrolight</span></label>
            </ul>
        </div>
    </div>

    <main class="container">
        <h1>Absensi Mingguan</h1>

        <form method="get" class="week-form">
            <input type="date" name="tanggal" value="<?= e($start) ?>">
            <button type="button" onclick="move(-7)" id="prevBtn">Minggu Lalu</button>
            <button type="button" onclick="move(7)" id="nextBtn">Minggu Depan</button>
            <button type="submit" class="submit">Tampilkan</button>
        </form>
        <p class="range-info">Periode: <strong><?= date('d F Y', $days['sabtu']) ?></strong> — <strong><?= date('d F Y', $days['jumat']) ?></strong></p>

        <div class="tbl-wrap">
            <table class="tbl weekly">
                <thead>
                    <tr class="brand-row">
                        <th colspan="14">PT. UNICO — LAPORAN ABSENSI</th>
                    </tr>
                    <tr class="head-days">
                        <th class="col-no" rowspan="2">No</th>
                        <th class="col-emp" rowspan="2">Nama</th>
                        <?php foreach ($dayOrder as $k): ?>
                        <th class="col-day" colspan="2">
                            <span class="day-name"><?= e(strtoupper($k)) ?></span>
                            <span class="day-date"><?= date('d M', $days[$k]) ?></span>
                        </th>
                        <?php endforeach; ?>
                        <th class="col-total" rowspan="2">Total<br>Penalty</th>
                    </tr>
                    <tr class="head-sub">
                        <?php foreach ($dayOrder as $k): ?>
                        <th>In</th><th>Out</th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($deptOrder as $dept): ?>
                    <tr class="dept-row">
                        <td colspan="14"><?= e($dept) ?></td>
                    </tr>
                    <?php
                    $deptTot = 0;
                    $no = 1;
                    foreach ($employees as $emp):
                        if (($emp['dept_name'] ?? '-') !== $dept) continue;
                        $row = compute_row($emp['emp_code'], $punches);
                        $empTotal = array_sum(array_column($row, 'pen'));
                        $deptTot += $empTotal;
                    ?>
                    <tr>
                        <td class="emp-no"><?= $no++ ?></td>
                        <td class="emp-name"><?= e($emp['first_name']) ?></td>
                        <?php foreach ($dayOrder as $k): $c = $row[$k]; ?>
                            <td style="background:<?= e($c['inColor']) ?>"><?= $c['in'] !== null ? e($c['in']) : '' ?></td>
                            <td style="background:<?= e($c['outColor']) ?>"><?= e($c['outT']) ?></td>
                        <?php endforeach; ?>
                        <td class="num total"><?= number_format($empTotal, 0, ',', '.') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="dept-total">
                        <td class="left" colspan="13">TOTAL <?= e($dept) ?></td>
                        <td class="num total"><?= number_format($deptTot, 0, ',', '.') ?></td>
                    </tr>
                    <?php $grandTotal += $deptTot; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="weekly-legend">
            <span class="lg-label">Tingkat potongan:</span>
            <?php foreach ([10000, 20000, 30000, 40000, 90000] as $lv): ?>
            <span class="lg-item"><span class="lg-dot" style="background:<?= e($IN_COLORS[$lv]) ?>"></span>Rp <?= number_format($lv, 0, ',', '.') ?></span>
            <?php endforeach; ?>
        </div>
        <div class="weekly-summary">
            <span class="sum-label">Grand Total Penalty</span>
            <span class="sum-val">Rp <?= number_format($grandTotal, 0, ',', '.') ?></span>
        </div>
    </main>

    <script>
        function move(days) {
            var d = document.querySelector('input[name=tanggal]');
            var t = new Date(d.value + 'T00:00:00');
            t.setDate(t.getDate() + days);
            d.value = t.toISOString().slice(0, 10);
        }
    </script>

    <script>
        (function () {
            var list = document.getElementById('themeList');
            var checkbox = document.getElementById('theme-popup-checkbox');
            var saved = localStorage.getItem('absensi-theme') || '';
            applyTheme(saved);
            list.querySelectorAll('label').forEach(function (lbl) {
                if (String(lbl.dataset.theme) === saved) lbl.style.outline = '2px solid var(--primary)';
            });
            function applyTheme(t) {
                document.body.classList.remove('dark', 'theme-sakura', 'theme-bamboo',
                    'theme-cyberpunk', 'theme-mingyu', 'theme-ocean', 'theme-retrolight');
                if (t) document.body.classList.add(t);
            }
            list.addEventListener('click', function (e) {
                var lbl = e.target.closest('label');
                if (!lbl) return;
                var t = lbl.dataset.theme;
                localStorage.setItem('absensi-theme', t);
                applyTheme(t);
                if (checkbox) checkbox.checked = false;
                list.querySelectorAll('label').forEach(function (l) { l.style.outline = ''; });
                lbl.style.outline = '2px solid var(--primary)';
            });

            // === HAMBURGER CLICK OUTSIDE CLOSE ===
            document.addEventListener('click', function (e) {
                var burger = document.getElementById('burger-toggle');
                var navMenu = document.getElementById('navMenu');
                if (burger && burger.checked && !e.target.closest('.topbar')) {
                    burger.checked = false;
                    if (navMenu) navMenu.classList.remove('open');
                }
            });
        })();
    </script>
</body>
</html>