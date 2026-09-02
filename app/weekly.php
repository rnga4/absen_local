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
            <a href="index.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span>Dashboard</span>
            </a>
            <a class="active" href="weekly.php">
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
                        <td class="emp-name">
                            <a class="emp-click-link" data-code="<?= e($emp['emp_code']) ?>" data-name="<?= e($emp['first_name']) ?>" data-dept="<?= e($dept) ?>" href="javascript:void(0)" title="Klik untuk lihat riwayat detail">
                                <?= e($emp['first_name']) ?>
                            </a>
                        </td>
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

            // === SLIDE-OVER DRAWER CLOSE LISTENERS ===
            var toggle = document.getElementById('drawer-toggle');
            var overlay = document.getElementById('drawerOverlay');
            var closeBtn = document.getElementById('drawerCloseBtn');

            function closeDrawer() {
                if (toggle) toggle.checked = false;
            }

            if (overlay) overlay.addEventListener('click', closeDrawer);
            if (closeBtn) closeBtn.addEventListener('click', closeDrawer);

            // === NAVBAR FLOAT ON SCROLL (Relaxed threshold & less sensitive) ===
            var nav = document.querySelector('.topbar');
            if (nav) {
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
            }
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
                var rows = document.querySelectorAll('.tbl.weekly tbody tr');
                rows.forEach(function(tr) {
                    if (tr.classList.contains('dept-row') || tr.classList.contains('dept-total')) {
                        return;
                    }
                    var nameEl = tr.querySelector('.emp-name');
                    var nameText = nameEl ? nameEl.textContent.toLowerCase() : '';
                    if (query === '' || nameText.indexOf(query) !== -1) {
                        tr.style.display = '';
                    } else {
                        tr.style.display = 'none';
                    }
                });
            });
        })();
    </script>
</body>
</html>