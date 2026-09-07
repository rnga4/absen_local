<?php
require __DIR__ . '/config.php';
require_login();

if (($_SESSION['role'] ?? '') !== 'employee') {
    header('Location: index.php');
    exit;
}

$empCode = $_SESSION['emp_code'] ?? '';
if ($empCode === '') {
    header('Location: logout.php');
    exit;
}

$stEmp = db()->prepare(
    "SELECT a.emp_code, a.first_name, a.department_id, b.dept_name
     FROM personnel_employee a
     LEFT JOIN personnel_department b ON b.dept_code = a.department_id
     WHERE a.emp_code = :code LIMIT 1"
);
$stEmp->execute([':code' => $empCode]);
$emp = $stEmp->fetch();

$empName  = $emp['first_name'] ?? 'Karyawan';
$empDept  = $emp['dept_name'] ?? '-';
$empId    = $emp['emp_code'] ?? $empCode;

$today = date('Y-m-d');
$stP = db()->prepare(
    "SELECT punch_time FROM iclock_transaction_backup
     WHERE emp_code = :code AND DATE(punch_time) = :d ORDER BY punch_time ASC"
);
$stP->execute([':code' => $empCode, ':d' => $today]);
$punches = array_column($stP->fetchAll(), 'punch_time');

$in = $out = null;
foreach ($punches as $pt) {
    $t = date('H:i', strtotime($pt));
    if ($t <= '13:00' && $in === null) $in = $t;
    elseif ($in !== null && $t > $in) $out = $t;
}
$lateMin = 0;
if ($in !== null && $in > '08:00') {
    $lateMin = (strtotime('1970-01-01 ' . $in) - strtotime('1970-01-01 08:00')) / 60;
}
$status = $in === null ? 'belum' : ($in > '08:00' ? 'telat' : 'hadir');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Absensi Saya - <?= e($empName) ?></title>
    <link rel="stylesheet" href="assets/pl-komatsu-ui-template.css">
    <link rel="stylesheet" href="assets/style.css">
    <style>
        html { scroll-behavior: smooth; }
        .profile-header {
            text-align: center;
            padding: 32px 16px 24px;
        }
        .profile-avatar {
            width: 80px; height: 80px;
            border-radius: 50%;
            background: var(--primary);
            color: #fff;
            font-size: 2rem; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 14px;
        }
        .profile-name { font-size: 1.4rem; font-weight: 700; margin: 0; }
        .profile-meta { color: var(--muted); font-size: 0.9rem; margin-top: 4px; }

        .today-card {
            background: var(--card-bg, #fff);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg, 14px);
            padding: 20px;
            margin-bottom: 20px;
        }
        .today-card h2 { margin: 0 0 14px; font-size: 1.1rem; }
        .today-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 12px;
            text-align: center;
        }
        .today-item .label { font-size: 0.75rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; }
        .today-item .value { font-size: 1.2rem; font-weight: 700; margin-top: 4px; }

        .history-section { margin-top: 24px; }
        .history-section h2 { font-size: 1.1rem; margin-bottom: 12px; }

        .emp-loading-skeleton {
            padding: 24px; text-align: center; color: var(--muted);
        }
        .load-more-btn {
            display: block; width: 100%; padding: 12px;
            background: var(--card-bg, #fff); border: 1px solid var(--border);
            border-radius: var(--radius, 8px); cursor: pointer;
            font-size: 0.9rem; color: var(--primary); font-weight: 600;
            margin-top: 12px; text-align: center;
        }
        .load-more-btn:hover { background: color-mix(in oklch, var(--primary) 8%, transparent); }

        @media (max-width: 480px) {
            .today-grid { grid-template-columns: 1fr 1fr 1fr; gap: 8px; }
            .today-item .value { font-size: 1rem; }
        }
    </style>
</head>
<body>
    <input type="checkbox" id="drawer-toggle" class="drawer-checkbox">
    <nav class="topbar">
        <span class="brand">Absensi Saya</span>
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
            <span class="drawer-title">Menu</span>
            <button type="button" class="drawer-close-btn" id="drawerCloseBtn">&times;</button>
        </div>
        <nav class="drawer-nav">
            <a class="active" href="employee.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span>Absensi Saya</span>
            </a>
            <div class="drawer-divider"></div>
            <label class="theme-switch-btn" for="theme-popup-checkbox" style="justify-content:flex-start">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
                <span>Pilih Tema</span>
                <span style="margin-left:auto;font-size:0.8rem">▾</span>
            </label>
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
            </ul>
        </div>
    </div>

    <main class="container">
        <div class="profile-header">
            <div class="profile-avatar"><?= strtoupper(mb_substr($empName, 0, 1)) ?></div>
            <h1 class="profile-name"><?= e($empName) ?></h1>
            <div class="profile-meta"><?= e($empDept) ?> · ID: <?= e($empId) ?></div>
        </div>

        <div class="today-card">
            <h2>Status Hari Ini — <?= date('l, d F Y', strtotime($today)) ?></h2>
            <div class="today-grid">
                <div class="today-item">
                    <div class="label">Masuk</div>
                    <div class="value"><?= $in ?? '-' ?></div>
                </div>
                <div class="today-item">
                    <div class="label">Keluar</div>
                    <div class="value"><?= $out ?? '-' ?></div>
                </div>
                <div class="today-item">
                    <div class="label">Status</div>
                    <div class="value">
                        <?php if ($status === 'hadir'): ?>
                            <span class="badge st-hadir">Hadir</span>
                        <?php elseif ($status === 'telat'): ?>
                            <span class="badge st-telat">+<?= (int)$lateMin ?> m</span>
                        <?php else: ?>
                            <span class="badge st-belum">Belum</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="history-section">
            <h2>Riwayat Absensi</h2>
            <div id="historyBody">
                <div class="emp-loading-skeleton">Memuat riwayat...</div>
            </div>
        </div>
    </main>

    <script>
    (function() {
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

    (function() {
        var toggle = document.getElementById('drawer-toggle');
        var overlay = document.getElementById('drawerOverlay');
        var closeBtn = document.getElementById('drawerCloseBtn');
        function closeDrawer() { if (toggle) toggle.checked = false; }
        if (overlay) overlay.addEventListener('click', closeDrawer);
        if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
    })();

    (function() {
        var bodyEl = document.getElementById('historyBody');
        var empCode = <?= json_encode($empCode) ?>;
        var currentOffset = 0;
        var isLoading = false;
        var hasMore = false;

        function loadBatch(initial) {
            if (isLoading) return;
            isLoading = true;
            if (!initial) {
                var btn = document.getElementById('loadMoreBtn');
                if (btn) { btn.disabled = true; btn.textContent = 'Memuat...'; }
            }
            fetch('api_history.php?code=' + encodeURIComponent(empCode) + '&offset=' + currentOffset)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    isLoading = false;
                    if (!data.success) {
                        if (initial) bodyEl.innerHTML = '<div class="emp-loading-skeleton" style="color:var(--destructive)">Gagal memuat data</div>';
                        return;
                    }
                    hasMore = data.has_more;
                    currentOffset = data.next_offset;

                    if (initial) {
                        if (!data.history || data.history.length === 0) {
                            bodyEl.innerHTML = '<div class="emp-loading-skeleton">Belum ada riwayat absensi.</div>';
                            return;
                        }
                        bodyEl.innerHTML = '<div class="history-summary-tag">Total ' + data.total_days + ' hari terekam</div><div id="historyList"></div>';
                    }

                    var container = document.getElementById('historyList');
                    data.history.forEach(function(item) {
                        var badge = '';
                        if (item.status === 'hadir') badge = '<span class="badge st-hadir">Hadir</span>';
                        else if (item.status === 'telat') badge = '<span class="badge st-telat">+' + item.late + ' m</span>';
                        else badge = '<span class="badge st-belum">Belum</span>';

                        var card = document.createElement('div');
                        card.className = 'emp-history-card';
                        card.innerHTML =
                            '<div class="history-date-box">' +
                                '<span class="history-date-main">' + item.date_formatted + '</span>' +
                                '<span class="history-day-sub">' + item.day_name + '</span>' +
                            '</div>' +
                            '<div class="history-punches-box">' +
                                '<div class="history-punch-item"><span class="punch-label">Masuk</span><span class="punch-value">' + item.in + '</span></div>' +
                                '<div class="history-punch-item"><span class="punch-label">Keluar</span><span class="punch-value">' + item.out + '</span></div>' +
                                badge +
                            '</div>';
                        container.appendChild(card);
                    });

                    var oldBtn = document.getElementById('loadMoreBtn');
                    if (oldBtn) oldBtn.remove();
                    if (hasMore) {
                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.id = 'loadMoreBtn';
                        btn.className = 'load-more-btn';
                        btn.textContent = 'Muat Riwayat Lebih Lama';
                        btn.addEventListener('click', function() { loadBatch(false); });
                        bodyEl.appendChild(btn);
                    }
                })
                .catch(function() {
                    isLoading = false;
                    if (initial) bodyEl.innerHTML = '<div class="emp-loading-skeleton" style="color:var(--destructive)">Gagal terhubung ke server</div>';
                });
        }

        loadBatch(true);

        if (bodyEl) {
            bodyEl.addEventListener('scroll', function() {
                if (!hasMore || isLoading) return;
                if (bodyEl.scrollTop + bodyEl.clientHeight >= bodyEl.scrollHeight - 50) {
                    loadBatch(false);
                }
            }, { passive: true });
        }
    })();
    </script>
</body>
</html>
