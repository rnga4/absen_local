<?php
require __DIR__ . '/config.php';
require_login();

$today = date('Y-m-d');
$filter = $_GET['f'] ?? '';
if (!in_array($filter, ['hadir', 'telat', 'belum'], true)) {
    $filter = '';
}

$employees = active_employees();

$punches = [];
$st = db()->prepare(
    "SELECT emp_code, punch_time FROM iclock_transaction_backup WHERE DATE(punch_time) = :d ORDER BY punch_time ASC"
);
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
    $stat['total']++;
    $stat[$status]++;
    $rows[$emp['dept_name'] ?? '-'][] = [
        'code'   => $emp['emp_code'],
        'name'   => $emp['first_name'],
        'in'     => $in,
        'out'    => $out,
        'status' => $status,
        'late'   => $lateMin,
    ];
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
    <style>
        html {
            scroll-behavior: smooth;
            scrollbar-gutter: stable;
            overflow-y: scroll;
        }
        .tbl {
            table-layout: fixed;
            width: 100%;
        }
        .col-no { width: 60px; text-align: center; }
        .col-nama { text-align: left; word-break: break-word; }
        .col-masuk { width: 90px; text-align: center; }
        .col-keluar { width: 90px; text-align: center; }
        .col-status { width: 110px; text-align: center; }

        @media (max-width: 600px) {
            .tbl { table-layout: auto !important; }
            .col-no { width: 34px !important; padding: 6px 2px !important; }
            .col-nama { text-align: left !important; word-break: break-word !important; white-space: normal !important; }
            .col-masuk, .col-keluar { width: 60px !important; padding: 6px 4px !important; }
            .col-status { width: 75px !important; padding: 6px 4px !important; }
        }

        /* Card micro-interactions & smooth transitions */
        .cards a.card {
            position: relative;
            transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1),
                        box-shadow 0.25s cubic-bezier(0.16, 1, 0.3, 1),
                        border-color 0.25s cubic-bezier(0.16, 1, 0.3, 1),
                        background-color 0.25s ease;
            will-change: transform, box-shadow;
            overflow: hidden;
        }
        .cards a.card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: transparent;
            transition: background-color 0.25s ease;
            border-top-left-radius: var(--radius-lg);
            border-top-right-radius: var(--radius-lg);
        }
        .cards a.card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 24px -4px color-mix(in oklch, var(--primary) 18%, transparent);
        }
        .cards a.card:active {
            transform: translateY(-1px) scale(0.97);
            transition: transform 0.08s ease;
        }
        .cards a.card.active {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px color-mix(in oklch, var(--primary) 25%, transparent),
                        0 8px 20px -4px color-mix(in oklch, var(--primary) 15%, transparent);
        }
        .cards a.card.active::before {
            background: var(--primary);
        }
        .cards a.card.ok.active {
            border-color: var(--success);
            box-shadow: 0 0 0 2px color-mix(in oklch, var(--success) 30%, transparent),
                        0 8px 20px -4px color-mix(in oklch, var(--success) 18%, transparent);
        }
        .cards a.card.ok.active::before {
            background: var(--success);
        }
        .cards a.card.late.active {
            border-color: var(--destructive);
            box-shadow: 0 0 0 2px color-mix(in oklch, var(--destructive) 30%, transparent),
                        0 8px 20px -4px color-mix(in oklch, var(--destructive) 18%, transparent);
        }
        .cards a.card.late.active::before {
            background: var(--destructive);
        }
        .cards a.card.miss.active {
            border-color: oklch(0.6 0.2 27);
            box-shadow: 0 0 0 2px color-mix(in oklch, oklch(0.6 0.2 27) 30%, transparent),
                        0 8px 20px -4px color-mix(in oklch, oklch(0.6 0.2 27) 18%, transparent);
        }
        .cards a.card.miss.active::before {
            background: oklch(0.6 0.2 27);
        }

        /* Department block & row transitions */
        .dept-block {
            transition: opacity 0.25s ease, transform 0.25s ease;
        }
        .dept-block.hidden-dept {
            display: none !important;
        }
        tr.visible-row {
            display: table-row;
            animation: fadeInRow 0.22s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        tr.hidden-row {
            display: none !important;
        }
        @keyframes fadeInRow {
            from {
                opacity: 0.1;
                transform: translateY(4px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body>
    <input type="checkbox" id="drawer-toggle" class="drawer-checkbox">
    <nav class="topbar">
        <span class="brand">Absensi Monitor</span>
        <div class="container-input">
            <input type="text" id="empSearchInput" placeholder="Cari..." class="input" autocomplete="off">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
        </div>
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
            <a class="active" href="index.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span>Dashboard</span>
            </a>
            <a href="weekly.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                <span>Mingguan</span>
            </a>
            <a href="public.php" target="_blank">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>
                <span>Halaman Publik</span>
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

        <p class="range-info" id="rangeInfo" style="<?= $filter === '' ? 'display:none;' : '' ?>">
            Menampilkan: <strong id="filterName"><?= $filter === 'hadir' ? 'Sudah Absen' : ($filter === 'telat' ? 'Telat Masuk' : ($filter === 'belum' ? 'Belum Absen' : '')) ?></strong> (<span id="filterCount"><?= $stat[$filter] ?? 0 ?></span> karyawan) —
            <a href="index.php" id="resetFilterBtn">Tampilkan semua</a>
        </p>

        <?php foreach ($rows as $dept => $list): 
            $visibleRowsInDept = 0;
            foreach ($list as $r) {
                if ($filter === '' || $r['status'] === $filter) {
                    $visibleRowsInDept++;
                }
            }
        ?>
        <div class="dept-block<?= $visibleRowsInDept === 0 ? ' hidden-dept' : '' ?>">
            <h2 class="dept-title"><?= e($dept) ?></h2>
            <table class="tbl">
                <thead>
                    <tr>
                        <th class="col-no">No</th>
                        <th class="col-nama">Nama</th>
                        <th class="col-masuk">Masuk</th>
                        <th class="col-keluar">Keluar</th>
                        <th class="col-status">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $i = 1;
                    foreach ($list as $r): 
                        $hidden = ($filter !== '' && $r['status'] !== $filter);
                    ?>
                    <tr class="<?= $hidden ? 'hidden-row' : 'visible-row' ?>" data-status="<?= $r['status'] ?>">
                        <td class="col-no"><span class="row-num"><?= $hidden ? '' : $i++ ?></span></td>
                        <td class="col-nama">
                            <a class="emp-click-link" data-code="<?= e($r['code'] ?? '') ?>" data-name="<?= e($r['name']) ?>" data-dept="<?= e($dept) ?>" href="javascript:void(0)" title="Klik untuk lihat riwayat detail">
                                <?= e($r['name']) ?>
                            </a>
                        </td>
                        <td class="col-masuk"><?= $r['in'] ?? '-' ?></td>
                        <td class="col-keluar"><?= $r['out'] ?? '-' ?></td>
                        <td class="col-status"><span class="badge st-<?= $r['status'] ?>"><?= $r['status'] === 'hadir' ? 'Hadir' : ($r['status'] === 'telat' ? '+' . $r['late'] . ' m' : 'Belum') ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>
    </main>

    <!-- BACK TO TOP FLOATING BUTTON -->
    <button type="button" class="back-to-top" id="backToTopBtn" aria-label="Kembali ke atas">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg>
    </button>

    <!-- EMPLOYEE DETAIL MODAL -->
    <div class="emp-modal-overlay" id="empModalOverlay" role="dialog" aria-modal="true">
        <div class="emp-modal-box">
            <div class="emp-modal-header">
                <div class="emp-profile-meta">
                    <div class="emp-avatar-circle" id="modalAvatar">E</div>
                    <div>
                        <h3 class="emp-info-title" id="modalEmpName">Nama Karyawan</h3>
                        <div class="emp-info-sub">
                            <span id="modalEmpDept">Departemen</span> · ID: <span id="modalEmpCode">1001</span>
                        </div>
                    </div>
                </div>
                <button type="button" class="emp-modal-close-btn" id="modalCloseBtn" aria-label="Tutup">&times;</button>
            </div>
            <div class="emp-modal-filter-bar">
                <form id="modalDateFilterForm" class="modal-filter-form">
                    <div class="filter-input-group">
                        <label for="modalStartDate">Dari:</label>
                        <input type="date" id="modalStartDate" class="modal-date-input">
                    </div>
                    <div class="filter-input-group">
                        <label for="modalEndDate">s/d:</label>
                        <input type="date" id="modalEndDate" class="modal-date-input">
                    </div>
                    <button type="submit" class="modal-filter-btn">Filter</button>
                    <button type="button" id="modalResetFilterBtn" class="modal-reset-btn" title="Reset filter tanggal">Reset</button>
                </form>
            </div>
            <div class="emp-modal-body" id="modalBody">
                <div class="emp-loading-skeleton">Memuat data riwayat...</div>
            </div>
        </div>
    </div>

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

        // === INSTANT CARD FILTERING & TRANSITIONS ===
        (function() {
            var stats = {
                '': { label: '', count: <?= (int)$stat['total'] ?> },
                'hadir': { label: 'Sudah Absen', count: <?= (int)$stat['hadir'] ?> },
                'telat': { label: 'Telat Masuk', count: <?= (int)$stat['telat'] ?> },
                'belum': { label: 'Belum Absen', count: <?= (int)$stat['belum'] ?> }
            };

            function applyFilter(filterVal, pushState) {
                if (pushState) {
                    var newUrl = filterVal ? 'index.php?f=' + filterVal : 'index.php';
                    history.pushState({ f: filterVal }, '', newUrl);
                }

                // Update cards active state
                var cards = document.querySelectorAll('.cards a.card');
                cards.forEach(function (card) {
                    var href = card.getAttribute('href');
                    var cardFilter = '';
                    if (href.indexOf('f=') !== -1) {
                        cardFilter = href.split('f=')[1];
                    }
                    if (cardFilter === filterVal) {
                        card.classList.add('active');
                    } else {
                        card.classList.remove('active');
                    }
                });

                // Update range info banner
                var rangeInfo = document.getElementById('rangeInfo');
                var filterName = document.getElementById('filterName');
                var filterCount = document.getElementById('filterCount');
                if (filterVal !== '' && stats[filterVal]) {
                    if (filterName) filterName.textContent = stats[filterVal].label;
                    if (filterCount) filterCount.textContent = stats[filterVal].count;
                    if (rangeInfo) rangeInfo.style.display = 'block';
                } else {
                    if (rangeInfo) rangeInfo.style.display = 'none';
                }

                // Filter rows and handle department visibility
                var deptBlocks = document.querySelectorAll('.dept-block');
                deptBlocks.forEach(function (dept) {
                    var rows = dept.querySelectorAll('tbody tr');
                    var visibleCount = 0;
                    rows.forEach(function (tr) {
                        var st = tr.getAttribute('data-status');
                        if (filterVal === '' || st === filterVal) {
                            tr.classList.remove('hidden-row');
                            tr.classList.add('visible-row');
                            visibleCount++;
                            var numSpan = tr.querySelector('.row-num');
                            if (numSpan) numSpan.textContent = visibleCount;
                        } else {
                            tr.classList.remove('visible-row');
                            tr.classList.add('hidden-row');
                        }
                    });

                    if (visibleCount > 0) {
                        dept.classList.remove('hidden-dept');
                    } else {
                        dept.classList.add('hidden-dept');
                    }
                });
            }

            // Click listener for cards
            document.querySelectorAll('.cards a.card').forEach(function(card) {
                card.addEventListener('click', function(e) {
                    e.preventDefault();
                    var href = this.getAttribute('href');
                    var filterVal = '';
                    if (href.indexOf('f=') !== -1) {
                        filterVal = href.split('f=')[1];
                    }
                    applyFilter(filterVal, true);
                });
            });

            // Click listener for reset link
            var resetBtn = document.getElementById('resetFilterBtn');
            if (resetBtn) {
                resetBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    applyFilter('', true);
                });
            }

            // Browser back / forward buttons support
            window.addEventListener('popstate', function() {
                var params = new URLSearchParams(window.location.search);
                var filterVal = params.get('f') || '';
                applyFilter(filterVal, false);
            });
        })();

        // === NAVBAR FLOAT ON SCROLL (Relaxed threshold & less sensitive) ===
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

        // === SLIDE-OVER DRAWER CLOSE LISTENERS ===
        (function() {
            var toggle = document.getElementById('drawer-toggle');
            var overlay = document.getElementById('drawerOverlay');
            var closeBtn = document.getElementById('drawerCloseBtn');

            function closeDrawer() {
                if (toggle) toggle.checked = false;
            }

            if (overlay) overlay.addEventListener('click', closeDrawer);
            if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
        })();

        // === BACK TO TOP BUTTON LOGIC ===
        (function() {
            var btn = document.getElementById('backToTopBtn');
            if (!btn) return;
            window.addEventListener('scroll', function() {
                if ((window.scrollY || window.pageYOffset) > 250) {
                    btn.classList.add('visible');
                } else {
                    btn.classList.remove('visible');
                }
            }, { passive: true });
            btn.addEventListener('click', function() {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        })();

        // === EMPLOYEE DETAIL MODAL LOGIC (Paginated & Date Range Filter) ===
        (function() {
            var overlay = document.getElementById('empModalOverlay');
            var closeBtn = document.getElementById('modalCloseBtn');
            var avatar = document.getElementById('modalAvatar');
            var nameEl = document.getElementById('modalEmpName');
            var deptEl = document.getElementById('modalEmpDept');
            var codeEl = document.getElementById('modalEmpCode');
            var bodyEl = document.getElementById('modalBody');
            var filterForm = document.getElementById('modalDateFilterForm');
            var startDateInput = document.getElementById('modalStartDate');
            var endDateInput = document.getElementById('modalEndDate');
            var resetFilterBtn = document.getElementById('modalResetFilterBtn');

            var currentCode = '';
            var currentOffset = 0;
            var isLoading = false;
            var hasMore = false;

            function openModal(code, name, dept) {
                if (!overlay) return;
                currentCode = code;
                currentOffset = 0;
                isLoading = false;
                hasMore = false;

                if (startDateInput) startDateInput.value = '';
                if (endDateInput) endDateInput.value = '';

                var initials = name ? name.trim().charAt(0).toUpperCase() : 'E';
                avatar.textContent = initials;
                nameEl.textContent = name || 'Karyawan';
                deptEl.textContent = dept || '-';
                codeEl.textContent = code || '-';
                bodyEl.innerHTML = '<div class="emp-loading-skeleton">🔄 Memuat riwayat absensi...</div>';
                
                overlay.classList.add('active');

                loadHistoryBatch(true);
            }

            function loadHistoryBatch(isInitial) {
                if (isLoading || !currentCode) return;
                isLoading = true;

                if (!isInitial) {
                    var btn = document.getElementById('loadMoreHistoryBtn');
                    if (btn) {
                        btn.disabled = true;
                        btn.textContent = '🔄 Memuat data...';
                    }
                }

                var url = 'api_history.php?code=' + encodeURIComponent(currentCode) + '&offset=' + currentOffset;
                var sDate = startDateInput ? startDateInput.value : '';
                var eDate = endDateInput ? endDateInput.value : '';
                if (sDate) url += '&start_date=' + encodeURIComponent(sDate);
                if (eDate) url += '&end_date=' + encodeURIComponent(eDate);

                fetch(url)
                    .then(function(res) { return res.json(); })
                    .then(function(data) {
                        isLoading = false;
                        if (!data.success) {
                            if (isInitial) {
                                bodyEl.innerHTML = '<div class="emp-loading-skeleton" style="color:var(--destructive)">❌ ' + (data.error || 'Gagal memuat data') + '</div>';
                            }
                            return;
                        }

                        hasMore = data.has_more;
                        currentOffset = data.next_offset;

                        if (isInitial) {
                            if (!data.history || data.history.length === 0) {
                                bodyEl.innerHTML = '<div class="emp-loading-skeleton">Tidak ada riwayat absensi pada rentang tanggal ini.</div>';
                                return;
                            }

                            var dateRangeInfo = '';
                            if (sDate || eDate) {
                                dateRangeInfo = ' (Filter: ' + (sDate || 'Awal') + ' s/d ' + (eDate || 'Sekarang') + ')';
                            }

                            var summaryHeader = '<div class="history-summary-tag">📅 Total ' + data.total_days + ' hari terekam' + dateRangeInfo + '</div>';
                            bodyEl.innerHTML = summaryHeader + '<div class="emp-history-list" id="empHistoryList"></div>';
                        }

                        var listContainer = document.getElementById('empHistoryList');
                        if (listContainer) {
                            data.history.forEach(function(item) {
                                var statusBadge = '';
                                if (item.status === 'hadir') {
                                    statusBadge = '<span class="badge st-hadir">Hadir</span>';
                                } else if (item.status === 'telat') {
                                    statusBadge = '<span class="badge st-telat">+' + item.late + ' m</span>';
                                } else {
                                    statusBadge = '<span class="badge st-belum">Belum</span>';
                                }

                                var cardEl = document.createElement('div');
                                cardEl.className = 'emp-history-card';
                                cardEl.innerHTML = 
                                    '<div class="history-date-box">' +
                                        '<span class="history-date-main">' + item.date_formatted + '</span>' +
                                        '<span class="history-day-sub">' + item.day_name + '</span>' +
                                    '</div>' +
                                    '<div class="history-punches-box">' +
                                        '<div class="history-punch-item"><span class="punch-label">Masuk</span><span class="punch-value">' + item.in + '</span></div>' +
                                        '<div class="history-punch-item"><span class="punch-label">Keluar</span><span class="punch-value">' + item.out + '</span></div>' +
                                        statusBadge +
                                    '</div>';
                                listContainer.appendChild(cardEl);
                            });
                        }

                        // Remove old load more button if exists
                        var oldBtn = document.getElementById('loadMoreHistoryBtn');
                        if (oldBtn) oldBtn.remove();

                        // Append load more button if there are more records
                        if (hasMore) {
                            var loadMoreBtn = document.createElement('button');
                            loadMoreBtn.type = 'button';
                            loadMoreBtn.id = 'loadMoreHistoryBtn';
                            loadMoreBtn.className = 'load-more-btn';
                            loadMoreBtn.innerHTML = '⬇️ Muat Riwayat Lebih Lama';
                            loadMoreBtn.addEventListener('click', function() {
                                loadHistoryBatch(false);
                            });
                            bodyEl.appendChild(loadMoreBtn);
                        }
                    })
                    .catch(function(err) {
                        isLoading = false;
                        if (isInitial) {
                            bodyEl.innerHTML = '<div class="emp-loading-skeleton" style="color:var(--destructive)">❌ Gagal terhubung ke server</div>';
                        }
                    });
            }

            function closeModal() {
                if (overlay) overlay.classList.remove('active');
            }

            if (filterForm) {
                filterForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    currentOffset = 0;
                    bodyEl.innerHTML = '<div class="emp-loading-skeleton">🔄 Memfilter data...</div>';
                    loadHistoryBatch(true);
                });
            }

            if (resetFilterBtn) {
                resetFilterBtn.addEventListener('click', function() {
                    if (startDateInput) startDateInput.value = '';
                    if (endDateInput) endDateInput.value = '';
                    currentOffset = 0;
                    bodyEl.innerHTML = '<div class="emp-loading-skeleton">🔄 Memuat riwayat absensi...</div>';
                    loadHistoryBatch(true);
                });
            }

            // Click listener for employee names
            document.addEventListener('click', function(e) {
                var link = e.target.closest('.emp-click-link');
                if (link) {
                    e.preventDefault();
                    var code = link.getAttribute('data-code');
                    var name = link.getAttribute('data-name');
                    var dept = link.getAttribute('data-dept');
                    openModal(code, name, dept);
                }
            });

            if (closeBtn) closeBtn.addEventListener('click', closeModal);
            if (overlay) {
                overlay.addEventListener('click', function(e) {
                    if (e.target === overlay) closeModal();
                });
            }

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') closeModal();
            });

            // Infinite scroll inside modal body when reaching bottom
            if (bodyEl) {
                bodyEl.addEventListener('scroll', function() {
                    if (!hasMore || isLoading) return;
                    if (bodyEl.scrollTop + bodyEl.clientHeight >= bodyEl.scrollHeight - 50) {
                        loadHistoryBatch(false);
                    }
                }, { passive: true });
            }
        })();

        // === QUICK REAL-TIME EMPLOYEE SEARCH ===
        (function() {
            var input = document.getElementById('empSearchInput');
            if (!input) return;
            input.addEventListener('input', function() {
                var query = this.value.toLowerCase().trim();
                var deptBlocks = document.querySelectorAll('.dept-block');
                deptBlocks.forEach(function(dept) {
                    var rows = dept.querySelectorAll('tbody tr');
                    var visibleCount = 0;
                    rows.forEach(function(tr) {
                        var nameEl = tr.querySelector('.col-nama');
                        var nameText = nameEl ? nameEl.textContent.toLowerCase() : '';
                        if (query === '' || nameText.indexOf(query) !== -1) {
                            tr.classList.remove('search-hidden');
                            if (!tr.classList.contains('hidden-row')) {
                                visibleCount++;
                            }
                        } else {
                            tr.classList.add('search-hidden');
                        }
                    });

                    if (visibleCount > 0) {
                        dept.classList.remove('hidden-dept');
                    } else {
                        dept.classList.add('hidden-dept');
                    }
                });
            });
        })();
    </script>
</body>
</html>