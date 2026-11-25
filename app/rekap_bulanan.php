<?php
require __DIR__ . '/config.php';
require_login();

if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: employee.php');
    exit;
}

$bulan = $_GET['bulan'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bulan)) {
    $bulan = date('Y-m');
}
$start = $bulan . '-01';
$end   = date('Y-m-t', strtotime($start));

$grand = ['count' => 0, 'basic' => 0.0, 'income' => 0.0, 'deduction' => 0.0, 'pay' => 0.0];
$groups = [];

$st = db()->prepare(
    "SELECT COALESCE(NULLIF(TRIM(company_name), ''), 'Lainnya') AS company,
            employee_name, code, date AS tanggal, basic_salary, total_income, total_deduction, take_home_pay
     FROM tax_app.salary_slips
     WHERE deleted_at IS NULL AND date BETWEEN :s AND :e
     ORDER BY company, employee_name"
);
$st->execute([':s' => $start, ':e' => $end]);
$emptyGroup = ['rows' => [], 'count' => 0, 'basic' => 0.0, 'income' => 0.0, 'deduction' => 0.0, 'pay' => 0.0];
foreach ($st->fetchAll() as $r) {
    $company = $r['company'];
    if (!isset($groups[$company])) {
        $groups[$company] = $emptyGroup;
    }
    $basic     = (float)$r['basic_salary'];
    $income    = (float)$r['total_income'];
    $deduction = (float)$r['total_deduction'];
    $pay       = (float)$r['take_home_pay'];

    $groups[$company]['rows'][] = $r;
    $groups[$company]['count']++;
    $groups[$company]['basic']     += $basic;
    $groups[$company]['income']    += $income;
    $groups[$company]['deduction'] += $deduction;
    $groups[$company]['pay']       += $pay;

    $grand['count']++;
    $grand['basic']     += $basic;
    $grand['income']    += $income;
    $grand['deduction'] += $deduction;
    $grand['pay']       += $pay;
}

function rupiah($n)
{
    return 'Rp ' . number_format($n, 0, ',', '.');
}

function csv_num($v)
{
    return number_format((float)$v, 0, '.', '');
}

function csv_text($v)
{
    return preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : $v;
}

if (($_GET['export'] ?? '') === '1') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="rekap_gaji_' . $bulan . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['Perusahaan', 'Nama', 'Kode', 'Tanggal', 'Gaji Pokok', 'Pendapatan', 'Potongan', 'Dibayar']);
    if ($grand['count'] > 0) {
        foreach ($groups as $company => $g) {
            foreach ($g['rows'] as $r) {
                fputcsv($out, [
                    csv_text($company),
                    csv_text($r['employee_name']),
                    csv_text($r['code']),
                    $r['tanggal'],
                    csv_num($r['basic_salary']),
                    csv_num($r['total_income']),
                    csv_num($r['total_deduction']),
                    csv_num($r['take_home_pay']),
                ]);
            }
        }
        fputcsv($out, [
            'GRAND TOTAL', '', '', '',
            csv_num($grand['basic']),
            csv_num($grand['income']),
            csv_num($grand['deduction']),
            csv_num($grand['pay']),
        ]);
    }
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#3859A8">
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <title>Rekap Gaji Bulanan - Absensi Monitor</title>
    <link rel="stylesheet" href="assets/pl-komatsu-ui-template.css">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <input type="checkbox" id="drawer-toggle" class="drawer-checkbox">
    <nav class="topbar">
        <span class="brand">Absensi Monitor</span>
        <div class="topbar-right" style="display:flex;align-items:center;gap:10px">
            <label class="theme-switch-btn" for="theme-popup-checkbox" title="Pilih Tema">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
                <span class="theme-btn-label">Tema</span>
            </label>
            <label class="hamburger" for="drawer-toggle">
                <svg viewBox="0 0 32 32">
                    <path class="line line-top-bottom" d="M27 10 13 10C10.8 10 9 8.2 9 6 9 3.5 10.8 2 13 2 15.2 2 17 3.8 17 6L17 26C17 28.2 15.2 30 13 30 10.8 30 9 28.2 9 26 9 23.8 10.8 22 13 22L27 22"></path>
                    <path class="line" d="M7 16 27 16"></path>
                </svg>
            </label>
        </div>
    </nav>

    <div class="drawer-overlay" id="drawerOverlay"></div>
    <aside class="drawer-panel" id="drawerPanel">
        <div class="drawer-header">
            <span class="drawer-title">Menu Absensi</span>
            <button type="button" class="drawer-close-btn" id="drawerCloseBtn">&times;</button>
        </div>
        <nav class="drawer-nav">
            <a href="index.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span>Dashboard</span>
            </a>
            <a href="weekly.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                <span>Mingguan</span>
            </a>
            <a class="active" href="rekap_bulanan.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                <span>Rekap Bulanan</span>
            </a>
            <a href="visitors.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                <span>Pengunjung</span>
            </a>
            <a href="public.php" target="_blank">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>
                <span>Halaman Publik</span>
            </a>
            <a href="profile.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <span>Profil & Pengaturan</span>
            </a>
            <div class="drawer-divider"></div>
            <a href="logout.php" class="logout-btn">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
                <span>Keluar</span>
            </a>
        </nav>
    </aside>

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
                <label data-theme="theme-younglavender"><span>Young Lavender</span></label>
            </ul>
        </div>
    </div>

    <main class="container">
        <?php breadcrumb([['label' => 'Dashboard', 'href' => 'index.php'], ['label' => 'Rekap Gaji Bulanan']]); ?>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:22px">
        <h1 style="margin:0">Rekap Gaji Bulanan</h1>
        <a href="rekap_bulanan.php?bulan=<?= e($bulan) ?>&export=1" class="btn-export">Export Excel</a>
        </div>

        <form method="get" class="week-form">
            <input type="month" name="bulan" value="<?= e($bulan) ?>">
            <button type="button" onclick="moveMonth(-1)">Bulan Lalu</button>
            <button type="button" onclick="moveMonth(1)">Bulan Berikutnya</button>
            <button type="submit" class="submit">Tampilkan</button>
        </form>
        <p class="range-info">Periode: <strong><?= date('F Y', strtotime($start)) ?></strong> — <strong><?= date('d F Y', strtotime($end)) ?></strong></p>

        <?php if ($grand['count'] === 0): ?>
            <p class="range-info">Belum ada data gaji untuk bulan ini.</p>
        <?php else: ?>
        <div class="cards rekap-cards">
            <div class="card"><div class="num" style="font-size:1.05rem"><?= rupiah($grand['pay']) ?></div><div class="lbl">Total Gaji Dibayar</div></div>
            <div class="card"><div class="num" style="font-size:1.05rem"><?= rupiah($grand['basic']) ?></div><div class="lbl">Total Gaji Pokok</div></div>
            <div class="card"><div class="num" style="font-size:1.05rem"><?= rupiah($grand['deduction']) ?></div><div class="lbl">Total Potongan</div></div>
            <div class="card"><div class="num"><?= $grand['count'] ?></div><div class="lbl">Jumlah Slip Gaji</div></div>
        </div>
        <div class="tbl-wrap">
            <table class="tbl rekap">
                <thead>
                    <tr class="brand-row"><th colspan="6">REKAP GAJI BULANAN — <?= strtoupper(date('F Y', strtotime($start))) ?></th></tr>
                    <tr>
                        <th>Nama</th><th>Kode</th><th>Gaji Pokok</th><th>Pendapatan</th><th>Potongan</th><th>Dibayar</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($groups as $company => $g): ?>
                    <tr class="dept-row" style="background:var(--muted);font-weight:700;text-align:left">
                        <td colspan="6"><?= e($company) ?></td>
                    </tr>
                    <?php foreach ($g['rows'] as $r): ?>
                    <tr>
                        <td class="left"><?= e($r['employee_name']) ?></td>
                        <td><?= e($r['code']) ?></td>
                        <td class="num"><?= rupiah((float)$r['basic_salary']) ?></td>
                        <td class="num"><?= rupiah((float)$r['total_income']) ?></td>
                        <td class="num"><?= rupiah((float)$r['total_deduction']) ?></td>
                        <td class="num total"><?= rupiah((float)$r['take_home_pay']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="dept-total" style="background:var(--muted);font-weight:700;text-align:left">
                        <td class="left" colspan="2">Subtotal</td>
                        <td class="num total"><?= rupiah($g['basic']) ?></td>
                        <td class="num total"><?= rupiah($g['income']) ?></td>
                        <td class="num total"><?= rupiah($g['deduction']) ?></td>
                        <td class="num total"><?= rupiah($g['pay']) ?></td>
                    </tr>
                <?php endforeach; ?>
                    <tr>
                        <td class="left total" colspan="2">GRAND TOTAL</td>
                        <td class="num total"><?= rupiah($grand['basic']) ?></td>
                        <td class="num total"><?= rupiah($grand['income']) ?></td>
                        <td class="num total"><?= rupiah($grand['deduction']) ?></td>
                        <td class="num total"><?= rupiah($grand['pay']) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="range-info">Sumber: tax_app.salary_slips</p>
        <?php endif; ?>
    </main>

    <script>
        function moveMonth(n) {
            var input = document.querySelector('input[name=bulan]');
            if (!input.value) return;
            var d = new Date(input.value + '-01T12:00:00');
            d.setMonth(d.getMonth() + n);
            input.value = d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2);
        }

        (function () {
            var list = document.getElementById('themeList');
            var checkbox = document.getElementById('theme-popup-checkbox');
            var saved = localStorage.getItem('absensi-theme') || '';
            function applyTheme(t) {
                document.body.classList.remove('dark', 'theme-sakura', 'theme-bamboo',
                    'theme-cyberpunk', 'theme-mingyu', 'theme-ocean', 'theme-retrolight', 'theme-younglavender');
                if (t) document.body.classList.add(t);
            }
            applyTheme(saved);
            list.querySelectorAll('label').forEach(function (lbl) {
                if (String(lbl.dataset.theme) === saved) lbl.style.outline = '2px solid var(--primary)';
            });
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

            var toggle = document.getElementById('drawer-toggle');
            var overlay = document.getElementById('drawerOverlay');
            var closeBtn = document.getElementById('drawerCloseBtn');
            function closeDrawer() { if (toggle) toggle.checked = false; }
            if (overlay) overlay.addEventListener('click', closeDrawer);
            if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
        })();
    </script>
</body>
</html>
