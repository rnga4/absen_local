<?php
require __DIR__ . '/config.php';

$today = date('Y-m-d');
$employees = active_employees();

$st = db()->prepare("SELECT DISTINCT emp_code FROM iclock_transaction_backup WHERE punch_time >= :d AND punch_time < DATE_ADD(:d, INTERVAL 1 DAY)");
$st->execute([':d' => $today]);
$present = array_column($st->fetchAll(), 'emp_code', 'emp_code');

$notAbsen = [];
foreach ($employees as $emp) {
    if (!isset($present[$emp['emp_code']])) {
        $notAbsen[$emp['dept_name'] ?? '-'][] = $emp['first_name'];
    }
}
$allPresent = empty($notAbsen);
$total = array_sum(array_map('count', $notAbsen));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Belum Absen — <?= date('d F Y') ?></title>
    <link rel="stylesheet" href="assets/pl-komatsu-ui-template.css">
    <link rel="stylesheet" href="assets/style.css">
    <style>
        body { overscroll-behavior-y: contain; }
        .pub-name { font-size: 1.05rem; font-weight: 400; }
        .pub-no   { font-size: 0.9rem; color: var(--muted-foreground); }
        .floating-tema {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 99;
        }
        .floating-tema label {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 8px 16px;
            border-radius: 99px;
            background: var(--card);
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
            cursor: pointer;
            font-size: 0.82rem;
            font-weight: 600;
            border: 1px solid var(--border);
            color: var(--foreground);
            user-select: none;
        }
    </style>
</head>
<body<?= $allPresent ? ' class="all-present"' : '' ?>>
    <div id="ptr-indicator" style="text-align:center;font-size:0.8rem;color:var(--muted-foreground);height:0;overflow:hidden;transition:height 0.2s">↓ Lepas untuk refresh</div>

    <nav class="topbar" style="justify-content:space-between">
        <span id="clock" style="font-family:var(--font-mono);font-weight:700;font-size:1rem"></span>
        <span id="countdown" style="font-size:0.78rem;color:var(--muted-foreground)"></span>
    </nav>

    <!-- FLOATING TEMA -->
    <div id="floatingTema" style="position:fixed;bottom:24px;right:24px;z-index:999">
        <div id="temaDropdown" style="display:none;position:absolute;bottom:48px;right:0;background:var(--card);border:1px solid var(--border);border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,0.15);padding:6px;min-width:140px">
            <ul style="list-style:none;margin:0;padding:0" id="themeList">
                <label data-theme="" style="display:block;padding:8px 12px;border-radius:8px;cursor:pointer;font-size:0.85rem"><span>Light</span></label>
                <label data-theme="dark" style="display:block;padding:8px 12px;border-radius:8px;cursor:pointer;font-size:0.85rem"><span>Dark</span></label>
                <label data-theme="theme-sakura" style="display:block;padding:8px 12px;border-radius:8px;cursor:pointer;font-size:0.85rem"><span>Sakura</span></label>
                <label data-theme="theme-bamboo" style="display:block;padding:8px 12px;border-radius:8px;cursor:pointer;font-size:0.85rem"><span>Bamboo</span></label>
                <label data-theme="theme-cyberpunk" style="display:block;padding:8px 12px;border-radius:8px;cursor:pointer;font-size:0.85rem"><span>Cyberpunk</span></label>
                <label data-theme="theme-mingyu" style="display:block;padding:8px 12px;border-radius:8px;cursor:pointer;font-size:0.85rem"><span>Mingyu</span></label>
                <label data-theme="theme-ocean" style="display:block;padding:8px 12px;border-radius:8px;cursor:pointer;font-size:0.85rem"><span>Ocean</span></label>
                <label data-theme="theme-retrolight" style="display:block;padding:8px 12px;border-radius:8px;cursor:pointer;font-size:0.85rem"><span>Retrolight</span></label>
            </ul>
        </div>
        <button id="temaBtnFloat" style="padding:8px 16px;border-radius:99px;background:var(--card);box-shadow:0 4px 16px rgba(0,0,0,0.15);cursor:pointer;font-size:0.82rem;font-weight:600;border:1px solid var(--border);color:var(--foreground)">Tema ▾</button>
    </div>

    <main class="container">
        <h1>Belum Absen — <?= date('d F Y') ?></h1>

        <?php if ($allPresent): ?>
        <div style="text-align:center; padding:80px 0">
            <div style="font-size:4rem">✅</div>
            <div style="font-size:1.4rem; font-weight:700; margin-top:16px; color:var(--success)">
                Semua karyawan sudah absen!
            </div>
            <div style="color:var(--muted-foreground); margin-top:8px; font-size:0.9rem">
                Per pukul <?= date('H:i') ?>
            </div>
        </div>
        <?php else: ?>
        <p class="range-info"><strong><?= $total ?> orang</strong> belum absen · Per <?= date('H:i') ?></p>
        <?php foreach ($notAbsen as $dept => $names): ?>
        <h2 class="dept-title"><?= e($dept) ?></h2>
        <table class="tbl">
            <thead>
                <tr><th class="pub-no">No</th><th>Nama</th></tr>
            </thead>
            <tbody>
                <?php foreach ($names as $i => $name): ?>
                <tr>
                    <td style="text-align:center;width:48px" class="pub-no"><?= $i + 1 ?></td>
                    <td class="pub-name"><?= e($name) ?></td>
                </tr>
                <?php endforeach ?>
            </tbody>
        </table>
        <?php endforeach ?>
        <?php endif ?>
    </main>

    <script>
    (function () {
        // JAM REALTIME
        function updateClock() {
            var now = new Date();
            var h = String(now.getHours()).padStart(2, '0');
            var m = String(now.getMinutes()).padStart(2, '0');
            var s = String(now.getSeconds()).padStart(2, '0');
            document.getElementById('clock').textContent = h + ':' + m + ':' + s;
        }
        updateClock();
        setInterval(updateClock, 1000);

        // COUNTDOWN
        var remaining = 120;
        function updateCountdown() {
            var m = Math.floor(remaining / 60);
            var s = remaining % 60;
            document.getElementById('countdown').textContent = 'Refresh ' + m + ':' + String(s).padStart(2, '0');
            if (remaining <= 0) location.reload();
            remaining--;
        }
        updateCountdown();
        setInterval(updateCountdown, 1000);

        // PULL TO REFRESH
        var startY = 0, pulling = false;
        var ptr = document.getElementById('ptr-indicator');
        document.addEventListener('touchstart', function(e) {
            if (window.scrollY === 0) { startY = e.touches[0].clientY; pulling = true; }
        }, { passive: true });
        document.addEventListener('touchmove', function(e) {
            if (!pulling) return;
            if (e.touches[0].clientY - startY > 40) ptr.style.height = '36px';
        }, { passive: true });
        document.addEventListener('touchend', function(e) {
            if (!pulling) return;
            if (e.changedTouches[0].clientY - startY > 80) location.reload();
            else ptr.style.height = '0';
            pulling = false;
        }, { passive: true });

        // SECRET ADMIN SHORTCUT (klik h1 5x)
        var clicks = 0, timer = null;
        var title = document.querySelector('h1');
        if (title) {
            title.style.userSelect = 'none';
            title.addEventListener('click', function() {
                clicks++;
                clearTimeout(timer);
                if (clicks >= 5) { clicks = 0; window.location.href = 'index.php'; }
                timer = setTimeout(function() { clicks = 0; }, 2000);
            });
        }

        // THEME SWITCHER
        var themeList = document.getElementById('themeList');
        var temaBtn = document.getElementById('temaBtnFloat');
        var temaDropdown = document.getElementById('temaDropdown');
        var savedTheme = localStorage.getItem('absensi-theme') || '';
        function applyTheme(t) {
            document.body.classList.remove('dark','theme-sakura','theme-bamboo','theme-cyberpunk','theme-mingyu','theme-ocean','theme-retrolight');
            if (t) document.body.classList.add(t);
        }
        applyTheme(savedTheme);
        if (temaBtn) {
            temaBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                temaDropdown.style.display = temaDropdown.style.display === 'none' ? 'block' : 'none';
            });
        }
        document.addEventListener('click', function() {
            if (temaDropdown) temaDropdown.style.display = 'none';
        });
        if (themeList) {
            themeList.querySelectorAll('label').forEach(function(lbl) {
                if (String(lbl.dataset.theme) === savedTheme) lbl.style.background = 'var(--muted)';
                lbl.addEventListener('mouseenter', function() { lbl.style.background = 'var(--muted)'; });
                lbl.addEventListener('mouseleave', function() { lbl.style.background = String(lbl.dataset.theme) === localStorage.getItem('absensi-theme') ? 'var(--muted)' : ''; });
            });
            themeList.addEventListener('click', function(e) {
                var lbl = e.target.closest('label');
                if (!lbl) return;
                var t = lbl.dataset.theme;
                localStorage.setItem('absensi-theme', t);
                applyTheme(t);
                temaDropdown.style.display = 'none';
                themeList.querySelectorAll('label').forEach(function(l) { l.style.background = ''; });
                lbl.style.background = 'var(--muted)';
            });
        }
    })();
    </script>
</body>
</html>
