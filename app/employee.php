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

$locUser = local_user($_SESSION['username'] ?? '');
$hasPhoto = $locUser ? (photo_path($locUser['username']) !== null) : false;
$photoUrl = $hasPhoto ? ('photo.php?u=' . rawurlencode($locUser['username'])) : '';

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
        html { scroll-behavior: smooth; scrollbar-gutter: stable; overflow-y: scroll; }
        .container { max-width: 720px; }

        .profile-header {
            text-align: center;
            padding: 8px 0 26px;
        }
        .profile-avatar {
            width: 84px; height: 84px;
            margin: 0 auto 16px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), color-mix(in oklch, var(--primary) 70%, #fff));
            color: var(--primary-foreground);
            font-family: var(--font-display);
            font-size: 2.2rem; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
            box-shadow: 0 8px 24px -6px color-mix(in oklch, var(--primary) 40%, transparent),
                        inset 0 1.5px 1.5px 0 color-mix(in oklch, #fff 40%, transparent);
            border: 1px solid color-mix(in oklch, #fff 30%, var(--primary));
        }
        .profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .profile-name { font-family: var(--font-display); font-size: 1.5rem; font-weight: 700; margin: 0; color: var(--foreground); }
        .profile-meta { color: var(--muted-foreground); font-size: 0.9rem; margin-top: 6px; }

        .today-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            padding: 20px;
            margin-bottom: 24px;
        }
        .today-card h2 { margin: 0 0 16px; font-size: 1.05rem; color: var(--foreground); }
        .today-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 12px;
            text-align: center;
        }
        .today-item {
            background: var(--muted);
            border-radius: var(--radius-md);
            padding: 14px 8px;
        }
        .today-item .label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted-foreground); }
        .today-item .value { font-family: var(--font-mono); font-size: 1.35rem; font-weight: 700; margin-top: 6px; color: var(--foreground); }
        .today-item .value .badge { font-family: var(--font-sans); font-size: 0.8rem; padding: 6px 14px; }

        .history-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            width: 100%;
            padding: 16px 20px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            cursor: pointer;
            font-family: var(--font-display);
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--foreground);
            text-align: left;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .history-toggle:hover {
            border-color: color-mix(in oklch, var(--primary) 50%, var(--border));
        }
        .history-toggle .toggle-icon {
            display: inline-flex;
            width: 28px; height: 28px;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: var(--muted);
            color: var(--muted-foreground);
            transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1), background 0.2s ease, color 0.2s ease;
            flex-shrink: 0;
        }
        .history-section.open .history-toggle {
            border-color: color-mix(in oklch, var(--primary) 45%, var(--border));
        }
        .history-section.open .history-toggle .toggle-icon {
            transform: rotate(180deg);
            background: var(--primary);
            color: var(--primary-foreground);
        }
        .history-panel {
            overflow: hidden;
            max-height: 0;
            opacity: 0;
            transition: max-height 0.4s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.35s ease, margin 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .history-section.open .history-panel {
            max-height: 1400px;
            opacity: 1;
            margin-top: 14px;
        }
        .emp-loading-skeleton { padding: 32px 16px; text-align: center; color: var(--muted-foreground); font-size: 0.9rem; }

        /* Input filter (mirip search di index) */
        .history-filter {
            margin-bottom: 14px;
            position: relative;
            max-width: 320px;
        }
        .history-filter input {
            width: 100%;
            padding: 9px 16px 9px 38px;
            border-radius: 9999px;
            border: 1px solid var(--border);
            background: color-mix(in oklch, var(--card) 72%, transparent);
            color: var(--foreground);
            font-family: var(--font-sans);
            font-size: 0.85rem;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
            box-sizing: border-box;
        }
        .history-filter input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px color-mix(in oklch, var(--primary) 22%, transparent);
        }
        .history-filter svg {
            position: absolute;
            top: 50%;
            left: 14px;
            transform: translateY(-50%);
            color: var(--muted-foreground);
            pointer-events: none;
        }

        @media (max-width: 480px) {
            .today-grid { gap: 8px; }
            .today-item .value { font-size: 1.05rem; }
            .profile-avatar { width: 72px; height: 72px; font-size: 1.8rem; }
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

    <!-- SLIDE-OVER DRAWER -->
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
            <a href="profile.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <span>Profil & Pengaturan</span>
            </a>
            <a href="public.php" target="_blank">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>
                <span>Dashboard Publik</span>
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
            </ul>
        </div>
    </div>

    <main class="container">
        <?php breadcrumb([['label' => 'Dashboard Publik', 'href' => 'public.php'], ['label' => 'Absensi Saya']]); ?>
        <div class="profile-header">
            <div class="profile-avatar"><?php if ($hasPhoto): ?><img src="<?= e($photoUrl) ?>" alt="<?= e($empName) ?>"><?php else: ?><?= strtoupper(mb_substr($empName, 0, 1)) ?><?php endif; ?></div>
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

        <div class="history-section" id="historySection">
            <button type="button" class="history-toggle" id="historyToggle" aria-expanded="false" aria-controls="historyPanel">
                <span>Riwayat Absensi</span>
                <span class="toggle-icon">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                </span>
            </button>
            <div class="history-panel" id="historyPanel">
                <div class="history-filter">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    <input type="text" id="historySearch" placeholder="Cari tanggal atau hari..." autocomplete="off">
                </div>
                <div id="historyBody">
                    <div class="emp-loading-skeleton">Memuat riwayat...</div>
                </div>
            </div>
        </div>
    </main>

    <!-- BACK TO TOP FLOATING BUTTON -->
    <button type="button" class="back-to-top" id="backToTopBtn" aria-label="Kembali ke atas">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg>
    </button>

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
            var label = t ? t.replace('theme-', '') : 'Light';
            label = label.charAt(0).toUpperCase() + label.slice(1);
            if (window.AppToast) AppToast.info('Tema diubah', 'Tema \u201C' + label + '\u201D aktif.');
        });
    })();

    // NAVBAR FLOAT ON SCROLL
    (function() {
        var nav = document.querySelector('.topbar');
        if (!nav) return;
        var ticking = false;
        var isScrolled = false;
        window.addEventListener('scroll', function () {
            if (!ticking) {
                window.requestAnimationFrame(function() {
                    var scrollY = window.scrollY || window.pageYOffset;
                    if (!isScrolled && scrollY > 90) {
                        nav.classList.add('scrolled');
                        isScrolled = true;
                    } else if (isScrolled && scrollY < 30) {
                        nav.classList.remove('scrolled');
                        isScrolled = false;
                    }
                    ticking = false;
                });
                ticking = true;
            }
        }, { passive: true });
    })();

    // DRAWER CLOSE
    (function() {
        var toggle = document.getElementById('drawer-toggle');
        var overlay = document.getElementById('drawerOverlay');
        var closeBtn = document.getElementById('drawerCloseBtn');
        function closeDrawer() { if (toggle) toggle.checked = false; }
        if (overlay) overlay.addEventListener('click', closeDrawer);
        if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
    })();

    // BACK TO TOP
    (function() {
        var btn = document.getElementById('backToTopBtn');
        if (!btn) return;
        window.addEventListener('scroll', function() {
            if ((window.scrollY || window.pageYOffset) > 250) btn.classList.add('visible');
            else btn.classList.remove('visible');
        }, { passive: true });
        btn.addEventListener('click', function() { window.scrollTo({ top: 0, behavior: 'smooth' }); });
    })();

    // HISTORY ACCORDION + LAZY LOAD + SEARCH FILTER
    (function() {
        var section = document.getElementById('historySection');
        var toggle = document.getElementById('historyToggle');
        var panel = document.getElementById('historyPanel');
        
        if (section && toggle) {
            toggle.addEventListener('click', function() {
                var isOpen = section.classList.contains('open');
                if (isOpen) {
                    section.classList.remove('open');
                    toggle.setAttribute('aria-expanded', 'false');
                } else {
                    section.classList.add('open');
                    toggle.setAttribute('aria-expanded', 'true');
                    if (!historyLoaded) loadBatch(true);
                }
            });
        }

        var bodyEl = document.getElementById('historyBody');
        var searchInput = document.getElementById('historySearch');
        var empCode = <?= json_encode($empCode) ?>;
        var currentOffset = 0;
        var isLoading = false;
        var hasMore = false;
        var historyLoaded = false;

        function loadBatch(initial) {
            if (isLoading || historyLoaded && initial) return;
            isLoading = true;
            historyLoaded = true;
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
                        card.setAttribute('data-search', (item.date_formatted + ' ' + item.day_name).toLowerCase());
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
                    applySearchFilter();
                })
                .catch(function() {
                    isLoading = false;
                    if (initial) bodyEl.innerHTML = '<div class="emp-loading-skeleton" style="color:var(--destructive)">Gagal terhubung ke server</div>';
                });
        }

        function applySearchFilter() {
            var q = searchInput ? searchInput.value.toLowerCase().trim() : '';
            var cards = document.querySelectorAll('#historyList .emp-history-card');
            cards.forEach(function(c) {
                var hay = c.getAttribute('data-search') || '';
                c.style.display = (q === '' || hay.indexOf(q) !== -1) ? '' : 'none';
            });
            var loadBtn = document.getElementById('loadMoreBtn');
            if (loadBtn) loadBtn.style.display = (q === '') ? '' : 'none';
        }

        if (searchInput) searchInput.addEventListener('input', applySearchFilter);

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
    <script src="assets/toast.js"></script>
    <?= toast_js() ?>
</body>
</html>