<?php
require __DIR__ . '/config.php';
require_login();

if (($_SESSION['role'] ?? '') === 'employee') {
    header('Location: employee.php');
    exit;
}

$dateParam = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
    $dateParam = date('Y-m-d');
}
$showAll    = isset($_GET['all']);
$filterDate = $showAll ? null : $dateParam;

$summary = public_visit_ip_summary($filterDate);
$rows    = public_visit_ip_rows($filterDate);
$limited = count($rows) >= 500;

function fmt_visit_time(?string $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    $ts = strtotime($value);
    return $ts !== false ? date('d M Y H:i', $ts) : $value;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#3859A8">
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <title>Pengunjung Publik - Absensi Monitor</title>
    <link rel="stylesheet" href="assets/pl-komatsu-ui-template.css">
    <link rel="stylesheet" href="assets/style.css">
    <style>
        html { scroll-behavior: smooth; scrollbar-gutter: stable; overflow-y: scroll; }
        .visit-filter {
            display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
            margin: 0 0 18px;
        }
        .visit-filter input[type="date"] {
            padding: 8px 10px; border-radius: var(--radius-md);
            border: 1px solid var(--border); background: var(--card);
            color: var(--foreground); font-family: var(--font-sans);
        }
        .visit-filter .submit {
            padding: 8px 16px; border-radius: var(--radius-md);
            border: 1px solid var(--primary); background: var(--primary);
            color: var(--primary-foreground); font-weight: 600; cursor: pointer;
        }
        .visit-filter a {
            padding: 8px 14px; border-radius: var(--radius-md);
            border: 1px solid var(--border); background: var(--card);
            color: var(--foreground); font-size: 0.85rem; font-weight: 600;
            text-decoration: none;
        }
        .visit-filter a.active {
            border-color: var(--primary); color: var(--primary);
            background: color-mix(in oklch, var(--primary) 10%, transparent);
        }
        .visit-note {
            font-size: 0.78rem; color: var(--muted-foreground);
            margin: -8px 0 16px;
        }
        .visit-ua {
            display: block; max-width: 280px; overflow: hidden;
            text-overflow: ellipsis; white-space: nowrap;
            font-family: var(--font-sans); font-size: 0.78rem;
            color: var(--muted-foreground);
        }
        .visit-ip { font-family: var(--font-mono); font-weight: 600; }
        @media (max-width: 600px) {
            .visit-ua { max-width: 150px; }
        }
    </style>
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

    <!-- SLIDE-OVER DRAWER -->
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
            <a href="rekap_bulanan.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                <span>Rekap Uang Makan</span>
            </a>
            <a class="active" href="visitors.php">
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
        <?php breadcrumb([['label' => 'Dashboard', 'href' => 'index.php'], ['label' => 'Pengunjung Publik']]); ?>
        <h1>Pengunjung Publik</h1>
        <p class="visit-note">
            <?= $showAll ? 'Semua data (maks. 30 hari terakhir), dikelompokkan per IP.' : 'Data kunjungan pada ' . e(date('d F Y', strtotime($dateParam))) . '.' ?>
            Hanya pengunjung anonim yang dicatat. IP bukan identitas orang (bisa dipakai bersama/NAT).
        </p>

        <form method="get" class="visit-filter">
            <input type="date" name="date" value="<?= e($dateParam) ?>">
            <button type="submit" class="submit">Tampilkan</button>
            <a href="visitors.php" class="<?= $showAll ? '' : 'active' ?>">Hari ini</a>
            <a href="visitors.php?all=1" class="<?= $showAll ? 'active' : '' ?>">Semua</a>
        </form>

        <div class="cards" style="grid-template-columns:repeat(2,1fr);max-width:460px">
            <div class="card">
                <div class="num"><?= (int) $summary['ips'] ?></div>
                <div class="lbl">IP Unik</div>
            </div>
            <div class="card">
                <div class="num"><?= (int) $summary['hits'] ?></div>
                <div class="lbl">Total Hits</div>
            </div>
        </div>

        <?php if (empty($rows)): ?>
            <p class="range-info">Belum ada data pengunjung untuk filter ini.</p>
        <?php else: ?>
        <div class="tbl-wrap" style="overflow-x:auto">
            <table class="tbl">
                <thead>
                    <tr>
                        <th style="width:42px">No</th>
                        <th class="left">IP Address</th>
                        <th style="width:70px">Hits</th>
                        <?php if ($showAll): ?><th style="width:70px">Hari</th><?php endif; ?>
                        <th>Pertama</th>
                        <th>Terakhir</th>
                        <th class="left">User Agent</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $i => $row): ?>
                    <tr>
                        <td style="text-align:center;color:var(--muted-foreground)"><?= $i + 1 ?></td>
                        <td class="left"><span class="visit-ip"><?= e($row['ip']) ?></span></td>
                        <td class="num" style="text-align:center"><?= (int) $row['hits'] ?></td>
                        <?php if ($showAll): ?><td style="text-align:center"><?= (int) $row['days'] ?></td><?php endif; ?>
                        <td style="white-space:nowrap;font-size:0.8rem"><?= e(fmt_visit_time($row['first_seen'] ?? '')) ?></td>
                        <td style="white-space:nowrap;font-size:0.8rem"><?= e(fmt_visit_time($row['last_seen'] ?? '')) ?></td>
                        <td class="left">
                            <span class="visit-ua" title="<?= e($row['user_agent'] ?? '') ?>"><?= e($row['user_agent'] ?: '-') ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($limited): ?>
            <p class="range-info">Menampilkan maksimal 500 baris. Gunakan filter tanggal untuk mempersempit.</p>
        <?php endif; ?>
        <?php endif; ?>
    </main>

    <script>
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
        if (list) {
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
        }

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
