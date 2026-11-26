<?php
require __DIR__ . '/config.php';
require __DIR__ . '/uang_makan.php';
require_login();

if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: employee.php');
    exit;
}

$bulan = str_param($_GET, 'bulan');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bulan)) {
    $bulan = date('Y-m');
}
$start = $bulan . '-01';
$end   = date('Y-m-t', strtotime($start));

$workdays = hari_kerja_bulan($start, $end);
$recaps = [];
foreach (active_employees() as $emp) {
    $code = (string)$emp['emp_code'];
    $recaps[$code] = [
        'name'        => $emp['first_name'],
        'dept'        => $emp['dept_name'] ?? '-',
        'hadir'       => 0,
        'late'        => 0,
        'pot_telat'   => 0,
        'late_days'   => [],
    ];
}

$seen = [];
$hasData = false;
$st = db()->prepare(
    "SELECT emp_code, punch_time FROM iclock_transaction_backup
     WHERE punch_time BETWEEN :s AND :e ORDER BY emp_code, punch_time"
);
$st->execute([':s' => $start . ' 00:00:00', ':e' => $end . ' 23:59:59']);
foreach ($st->fetchAll() as $r) {
    $code = (string)$r['emp_code'];
    if (!isset($recaps[$code])) {
        continue;
    }
    $date = substr($r['punch_time'], 0, 10);
    if (isset($seen[$code][$date]) || !is_hari_kerja($date) || substr($r['punch_time'], 11, 5) > '13:00') {
        continue;
    }
    $seen[$code][$date] = true;
    $jamMasuk = substr($r['punch_time'], 11, 8);
    $potongan = potongan_telat($jamMasuk);
    $recaps[$code]['hadir']++;
    $recaps[$code]['pot_telat'] += $potongan;
    if ($potongan > 0) {
        $menit = (int) ceil((strtotime('1970-01-01 ' . $jamMasuk) - strtotime('1970-01-01 08:00:00')) / 60);
        $recaps[$code]['late']++;
        $recaps[$code]['late_days'][] = [
            'date' => $date,
            'in'   => substr($jamMasuk, 0, 5),
            'min'  => $menit,
            'pot'  => $potongan,
        ];
    }
    $hasData = true;
}

$groups = [];
$subs = [];
$grand = ['count' => count($recaps), 'days' => 0, 'late' => 0, 'potongan' => 0, 'uang' => 0];
foreach ($recaps as $rec) {
    $absen = $workdays - $rec['hadir'];
    $potongan = $absen * 90000 + $rec['pot_telat'];
    $uang = $workdays * 90000 - $potongan;
    $rec['absen'] = $absen;
    $rec['potongan'] = $potongan;
    $rec['uang'] = $uang;
    $rec['detail'] = json_encode([
        'name'       => $rec['name'],
        'dept'       => $rec['dept'],
        'workdays'   => $workdays,
        'hadir'      => $rec['hadir'],
        'absen'      => $absen,
        'pot_absen'  => $absen * 90000,
        'pot_telat'  => $rec['pot_telat'],
        'potongan'   => $potongan,
        'late_days'  => $rec['late_days'],
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    $dept = $rec['dept'];
    if (!isset($groups[$dept])) {
        $groups[$dept] = [];
        $subs[$dept] = ['days' => 0, 'late' => 0, 'potongan' => 0, 'uang' => 0];
    }
    $groups[$dept][] = $rec;
    $subs[$dept]['days'] += $rec['hadir'];
    $subs[$dept]['late'] += $rec['late'];
    $subs[$dept]['potongan'] += $potongan;
    $subs[$dept]['uang'] += $uang;
    $grand['days'] += $rec['hadir'];
    $grand['late'] += $rec['late'];
    $grand['potongan'] += $potongan;
    $grand['uang'] += $uang;
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
    header('Content-Disposition: attachment; filename="rekap_uang_makan_' . $bulan . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['Departemen', 'Nama', 'Hadir', 'Telat', 'Potongan', 'Uang Makan']);
    if ($hasData) {
        foreach ($groups as $dept => $rows) {
            foreach ($rows as $rec) {
                fputcsv($out, [
                    csv_text($dept),
                    csv_text($rec['name']),
                    $rec['hadir'],
                    $rec['late'],
                    csv_num($rec['potongan']),
                    csv_num($rec['uang']),
                ]);
            }
            fputcsv($out, [
                'Subtotal ' . csv_text($dept), '',
                $subs[$dept]['days'], $subs[$dept]['late'],
                csv_num($subs[$dept]['potongan']), csv_num($subs[$dept]['uang']),
            ]);
        }
        fputcsv($out, [
            'GRAND TOTAL', '', '', '',
            csv_num($grand['potongan']),
            csv_num($grand['uang']),
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
    <title>Rekap Uang Makan Bulanan - Absensi Monitor</title>
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
                <span>Rekap Uang Makan</span>
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
        <?php breadcrumb([['label' => 'Dashboard', 'href' => 'index.php'], ['label' => 'Rekap Uang Makan Bulanan']]); ?>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:22px">
        <h1 style="margin:0">Rekap Uang Makan Bulanan</h1>
        <a href="rekap_bulanan.php?bulan=<?= e($bulan) ?>&export=1" class="btn-export">Export Excel</a>
        </div>

        <form method="get" class="week-form">
            <input type="month" name="bulan" value="<?= e($bulan) ?>">
            <button type="button" onclick="moveMonth(-1)">Bulan Lalu</button>
            <button type="button" onclick="moveMonth(1)">Bulan Berikutnya</button>
            <button type="submit" class="submit">Tampilkan</button>
        </form>
        <p class="range-info">Periode: <strong><?= date('F Y', strtotime($start)) ?></strong> — <strong><?= date('d F Y', strtotime($end)) ?></strong></p>
        <div class="weekly-legend">
            <span class="lg-label">Ketentuan:</span>
            <span class="lg-item">Basis Rp 90.000 per hari kerja (Senin–Sabtu)</span>
            <span class="lg-item">Tidak hadir = potong Rp 90.000 per hari</span>
            <span class="lg-item">Telat = jumlah hari masuk setelah 08.00</span>
            <span class="lg-item">Potong Rp 10.000 per jam yang dimulai (1 menit = Rp 10.000, 61 menit = Rp 20.000)</span>
            <span class="lg-item">Punch setelah 13.00 tidak dihitung hadir</span>
        </div>

        <?php if (!$hasData): ?>
            <p class="range-info">Belum ada data absen untuk bulan ini.</p>
        <?php else: ?>
        <div class="cards rekap-cards">
            <a href="#" class="card" data-detail="uang"><div class="num" style="font-size:1.05rem"><?= rupiah($grand['uang']) ?></div><div class="lbl">Total Uang Makan</div></a>
            <a href="#" class="card" data-detail="potongan"><div class="num" style="font-size:1.05rem"><?= rupiah($grand['potongan']) ?></div><div class="lbl">Total Potongan</div></a>
            <a href="#" class="card" data-detail="hadir"><div class="num"><?= $grand['days'] ?></div><div class="lbl">Total Hari Hadir</div></a>
            <a href="#" class="card" data-detail="count"><div class="num"><?= $grand['count'] ?></div><div class="lbl">Jumlah Karyawan</div></a>
        </div>
        <div class="tbl-wrap">
            <table class="tbl rekap">
                <thead>
                    <tr class="brand-row"><th colspan="6">REKAP UANG MAKAN BULANAN — <?= strtoupper(date('F Y', strtotime($start))) ?></th></tr>
                    <tr>
                        <th class="company-col"></th>
                        <th>Nama</th><th>Hadir</th><th>Telat</th><th>Potongan</th><th>Uang Makan</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($groups as $dept => $rows): ?>
                    <?php $first = true; foreach ($rows as $rec): ?>
                    <tr class="<?= $first ? 'company-start' : '' ?>">
                        <?php if ($first): ?>
                        <th class="company-label" rowspan="<?= count($rows) + 1 ?>" title="<?= e($dept) ?>"><span><?= e($dept) ?></span></th>
                        <?php $first = false; endif; ?>
                        <td class="left"><?= e($rec['name']) ?></td>
                        <td class="num"><?= $rec['hadir'] ?></td>
                        <td class="num"><button type="button" class="pot-detail-btn" data-detail='<?= e($rec['detail']) ?>'><?= $rec['late'] ?></button></td>
                        <td class="num"><button type="button" class="pot-detail-btn" data-detail='<?= e($rec['detail']) ?>'><?= rupiah($rec['potongan']) ?></button></td>
                        <td class="num total"><?= rupiah($rec['uang']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="dept-total" style="background:var(--muted);font-weight:700;text-align:left">
                        <td class="left">Subtotal</td>
                        <td class="num total"><?= $subs[$dept]['days'] ?></td>
                        <td class="num total"><?= $subs[$dept]['late'] ?></td>
                        <td class="num total"><?= rupiah($subs[$dept]['potongan']) ?></td>
                        <td class="num total"><?= rupiah($subs[$dept]['uang']) ?></td>
                    </tr>
                <?php endforeach; ?>
                    <tr>
                        <td class="company-col"></td>
                        <td class="left total">GRAND TOTAL</td>
                        <td class="num total"><?= $grand['days'] ?></td>
                        <td class="num total"><?= $grand['late'] ?></td>
                        <td class="num total"><?= rupiah($grand['potongan']) ?></td>
                        <td class="num total"><?= rupiah($grand['uang']) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="range-info">Sumber: iclock_transaction_backup &amp; personnel_employee</p>
        <?php
        $detailSets = [
            'uang'     => ['Uang Makan', 'uang'],
            'potongan' => ['Potongan', 'potongan'],
            'hadir'    => ['Hadir', 'days'],
            'count'    => ['Karyawan', 'count'],
        ];
        $detailValue = function ($field, $dept = null) use ($groups, $subs, $grand) {
            if ($field === 'count') {
                return (string)($dept === null ? $grand['count'] : count($groups[$dept]));
            }
            $n = $dept === null ? $grand[$field] : $subs[$dept][$field];
            return $field === 'uang' || $field === 'potongan' ? rupiah($n) : (string)$n;
        };
        ?>
        <div class="emp-modal-overlay" id="detailOverlay" role="dialog" aria-modal="true">
            <div class="emp-modal-box">
                <div class="emp-modal-header">
                    <h3 class="emp-info-title" id="detailTitle">Detail</h3>
                    <button type="button" class="emp-modal-close-btn" id="detailCloseBtn" aria-label="Tutup">&times;</button>
                </div>
                <div class="emp-modal-body">
                    <?php $firstPanel = true; foreach ($detailSets as $key => [$label, $field]): ?>
                    <div data-panel="<?= $key ?>"<?= $firstPanel ? '' : ' hidden' ?>>
                        <table class="tbl rekap-detail">
                            <thead><tr><th>Departemen</th><th><?= e($label) ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($groups as $dept => $rows): ?>
                                <tr><td class="left"><?= e($dept) ?></td><td class="num"><?= $detailValue($field, $dept) ?></td></tr>
                            <?php endforeach; ?>
                                <tr><td class="left total">TOTAL</td><td class="num total"><?= $detailValue($field) ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <?php $firstPanel = false; endforeach; ?>
                    <div data-panel="pot-detail" hidden>
                        <p class="pot-detail-meta" id="potDetailMeta"></p>
                        <div class="pot-detail-summary">
                            <div class="pot-detail-line"><span>Absen</span><strong id="potDetailAbsen"></strong></div>
                            <div class="pot-detail-line"><span>Denda telat</span><strong id="potDetailLate"></strong></div>
                            <div class="pot-detail-line total"><span>Total potongan</span><strong id="potDetailTotal"></strong></div>
                        </div>
                        <table class="tbl rekap-detail" id="potDetailTable">
                            <thead><tr><th>Tanggal</th><th>Masuk</th><th>Telat</th><th>Denda</th></tr></thead>
                            <tbody id="potDetailBody"></tbody>
                        </table>
                        <p class="range-info" id="potDetailEmpty" hidden>Tidak ada denda telat.</p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <script>
        (function () {
            var overlay = document.getElementById('detailOverlay');
            var title = document.getElementById('detailTitle');
            var panels = [].slice.call(document.querySelectorAll('#detailOverlay [data-panel]'));
            var cards = [].slice.call(document.querySelectorAll('.rekap-cards a.card[data-detail]'));
            var potBtns = [].slice.call(document.querySelectorAll('.pot-detail-btn'));
            function rupiah(n) { return 'Rp ' + Number(n || 0).toLocaleString('id-ID'); }
            function showPanel(key) { panels.forEach(function (p) { p.hidden = p.dataset.panel !== key; }); }
            function openDetail(card) {
                if (title) title.textContent = card.querySelector('.lbl').textContent.trim();
                showPanel(card.dataset.detail);
                if (overlay) overlay.classList.add('active');
            }
            function openPotDetail(btn) {
                var d = JSON.parse(btn.dataset.detail || '{}');
                if (title) title.textContent = 'Rincian Potongan — ' + (d.name || '');
                var meta = document.getElementById('potDetailMeta');
                var absen = document.getElementById('potDetailAbsen');
                var late = document.getElementById('potDetailLate');
                var total = document.getElementById('potDetailTotal');
                var body = document.getElementById('potDetailBody');
                var table = document.getElementById('potDetailTable');
                var empty = document.getElementById('potDetailEmpty');
                if (meta) meta.textContent = (d.dept || '-') + ' • Hadir ' + (d.hadir || 0) + '/' + (d.workdays || 0) + ' hari kerja';
                if (absen) absen.textContent = (d.absen || 0) + ' hari × Rp 90.000 = ' + rupiah(d.pot_absen);
                if (late) late.textContent = rupiah(d.pot_telat);
                if (total) total.textContent = rupiah(d.potongan);
                var rows = d.late_days || [];
                if (body) body.innerHTML = rows.map(function (r) {
                    var jam = Math.ceil((r.min || 0) / 60);
                    return '<tr><td class="left">' + r.date + '</td><td class="num">' + r.in + '</td><td class="num">' + r.min + ' mnt (' + jam + ' jam)</td><td class="num">' + rupiah(r.pot) + '</td></tr>';
                }).join('');
                if (table) table.hidden = rows.length === 0;
                if (empty) empty.hidden = rows.length !== 0;
                showPanel('pot-detail');
                if (overlay) overlay.classList.add('active');
            }
            function closeDetail() { if (overlay) overlay.classList.remove('active'); }
            cards.forEach(function (card) {
                card.addEventListener('click', function (e) { e.preventDefault(); openDetail(card); });
            });
            potBtns.forEach(function (btn) {
                btn.addEventListener('click', function () { openPotDetail(btn); });
            });
            var closeBtn = document.getElementById('detailCloseBtn');
            if (closeBtn) closeBtn.addEventListener('click', closeDetail);
            if (overlay) overlay.addEventListener('click', function (e) { if (e.target === overlay) closeDetail(); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDetail(); });
        })();

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
