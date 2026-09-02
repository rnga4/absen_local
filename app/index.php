<?php
require __DIR__ . '/config.php';
require_login();

$today = date('Y-m-d');
$filter = $_GET['f'] ?? '';
if (!in_array($filter, ['hadir', 'telat', 'belum'], true)) $filter = '';

$employees = active_employees();

$punches = [];
$st = db()->prepare("SELECT emp_code, punch_time FROM iclock_transaction_backup WHERE punch_time >= :d AND punch_time < DATE_ADD(:d, INTERVAL 1 DAY) ORDER BY punch_time ASC");
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
        if ($t <= '13:00' && $in === null) { $in = $t; }
        elseif ($in !== null && $t > $in)  { $out = $t; }
    }
    $lateMin = 0;
    if ($in !== null && $in > '08:00') {
        $lateMin = (strtotime('1970-01-01 ' . $in) - strtotime('1970-01-01 08:00')) / 60;
    }
    $status = $in === null ? 'belum' : ($in > '08:00' ? 'telat' : 'hadir');
    $stat['total']++;
    $stat[$status]++;
    if ($filter !== '' && $status !== $filter) continue;
    $rows[$emp['dept_name'] ?? '-'][] = ['name' => $emp['first_name'], 'in' => $in, 'out' => $out, 'status' => $status, 'late' => $lateMin];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Absensi Monitor</title>
    <link rel="stylesheet" href="assets/pl-komatsu-ui-template.css">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <nav class="topbar">
        <span class="brand">Absensi Monitor</span>
        <span class="nav-right">
            <a class="active" href="index.php"><span class="nav-label">Dashboard</span></a>
            <a href="weekly.php"><span class="nav-label">Mingguan</span></a>
            <label class="theme-switch-btn" for="theme-popup-checkbox">
                <span>Tema</span>
                <span class="theme-popup__chevron">▾</span>
            </label>
            <a href="public.php" target="_blank"><span class="nav-label">Publik</span></a>
            <a href="logout.php"><span class="nav-label">Keluar</span></a>
        </span>
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
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:22px">
        <h1 style="margin:0">Kehadiran Hari Ini — <?= date('l, d F Y', strtotime($today)) ?></h1>
        <a href="export.php" class="btn-export">Export CSV</a>
        </div>

        <div class="cards">
            <a class="card<?= $filter === '' ? ' active' : '' ?>" href="index.php">
                <div class="num"><?= $stat['total'] ?></div>
                <div class="lbl">Total Karyawan</div>
            </a>
            <a class="card ok<?= $filter === 'hadir' ? ' active' : '' ?>" href="index.php?f=hadir">
                <div class="num"><?= $stat['hadir'] ?></div>
                <div class="lbl">Sudah Absen</div>
            </a>
            <a class="card late<?= $filter === 'telat' ? ' active' : '' ?>" href="index.php?f=telat">
                <div class="num"><?= $stat['telat'] ?></div>
                <div class="lbl">Telat Masuk</div>
            </a>
            <a class="card miss<?= $filter === 'belum' ? ' active' : '' ?>" href="index.php?f=belum">
                <div class="num"><?= $stat['belum'] ?></div>
                <div class="lbl">Belum Absen</div>
            </a>
        </div>

        <?php if ($filter !== ''): ?>
        <p class="range-info">
            Menampilkan: <strong><?= $filter === 'hadir' ? 'Sudah Absen'
                : ($filter === 'telat' ? 'Telat Masuk' : 'Belum') ?></strong> (<?= $stat[$filter] ?> karyawan) —
            <a href="index.php">Tampilkan semua</a>
        </p>
        <?php endif; ?>

        <?php foreach ($rows as $dept => $list): ?>
        <h2 class="dept-title"><?= e($dept) ?></h2>
        <table class="tbl">
            <thead>
                <tr><th>No</th><th>Nama</th><th>Masuk</th><th>Keluar</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($list as $r): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= e($r['name']) ?></td>
                    <td><?= $r['in'] ?? '-' ?></td>
                    <td><?= $r['out'] ?? '-' ?></td>
                    <td><span class="badge st-<?= $r['status'] ?>"><?= $r['status'] === 'hadir' ? 'Hadir' : ($r['status'] === 'telat' ? '+' . $r['late'] . ' m' : 'Belum') ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endforeach; ?>
    </main>

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
                document.body.classList.remove('dark','theme-sakura','theme-bamboo','theme-cyberpunk','theme-mingyu','theme-ocean','theme-retrolight');
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
        })();
        // === NAVBAR FLOAT ON SCROLL ===
        window.addEventListener('scroll', function () {
            var nav = document.querySelector('.topbar');
            if (window.scrollY > 60) nav.classList.add('scrolled');
            else nav.classList.remove('scrolled');
        });
    </script>
</body>
</html>
