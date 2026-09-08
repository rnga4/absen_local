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
        $notAbsen[$emp['dept_name'] ?? '-'][] = [
            'emp_code' => $emp['emp_code'],
            'name'     => $emp['first_name'],
            'photo'    => emp_photo_url($emp['emp_code'], true),
        ];
    }
}
$allPresent = empty($notAbsen);
$total = array_sum(array_map('count', $notAbsen));

$voteCounts = votes_today_counts();
$myVotes    = my_votes_today($_SESSION['username'] ?? null);

$loggedIn     = !empty($_SESSION['logged_in']);
$userRole     = $_SESSION['role'] ?? '';
$username     = $_SESSION['username'] ?? '';
$locUser      = $loggedIn && $username !== '' ? local_user($username) : null;
$userName     = $loggedIn && $locUser ? (($locUser['name'] ?? '') ?: $username) : '';
$userHasPhoto = $loggedIn && $locUser && (photo_path($locUser['username']) !== null);
$userPhotoUrl = $userHasPhoto ? ('photo.php?u=' . rawurlencode($locUser['username'])) : '';
$userInitial  = $userName !== '' ? strtoupper(mb_substr($userName, 0, 1)) : '?';

$openProfile = !empty($_SESSION['open_profile']);
unset($_SESSION['open_profile']);
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
        html { scroll-behavior: smooth; scrollbar-gutter: stable; overflow-y: scroll; }
        .container { max-width: 720px; }
        body { overscroll-behavior-y: contain; }
        .pub-name { font-size: 1.05rem; font-weight: 400; display: inline-flex; align-items: center; gap: 8px; }
        .pub-no   { font-size: 0.9rem; color: var(--muted-foreground); }
        .vote-btn {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 4px 10px; margin-right: 6px;
            border-radius: 99px; border: 1px solid var(--border);
            background: var(--card); color: var(--muted-foreground);
            font-family: var(--font-mono); font-size: 0.8rem; font-weight: 600;
            cursor: pointer; user-select: none; transition: all 0.15s;
        }
        .vote-btn:hover { border-color: var(--destructive); color: var(--foreground); }
        .vote-btn .vote-n { min-width: 14px; text-align: center; }
        .vote-btn.active { border-color: var(--destructive); color: var(--destructive); background: color-mix(in oklch, var(--destructive) 12%, transparent); }
        .pub-emp-btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 14px; border-radius: 99px; cursor: pointer;
            border: 1px solid var(--border); background: var(--card); color: var(--foreground);
            font-size: 0.82rem; font-weight: 600; user-select: none;
            transition: all 0.15s;
        }
        .pub-emp-btn:hover { border-color: var(--primary); color: var(--primary); }
        .pub-avatar-btn {
            width: 36px; height: 36px; padding: 0;
            border-radius: 50%;
            border: 2px solid color-mix(in oklch, var(--foreground) 12%, transparent);
            background: var(--card); color: var(--muted-foreground);
            display: inline-flex; align-items: center; justify-content: center;
            overflow: hidden; cursor: pointer; flex-shrink: 0;
            transition: border-color 0.2s, transform 0.2s;
        }
        .pub-avatar-btn:hover { border-color: var(--primary); transform: translateY(-1px); }
        .pub-avatar-btn img { width: 100%; height: 100%; object-fit: cover; }
        .pub-avatar-btn .pub-avatar-fallback {
            width: 100%; height: 100%;
            display: flex; align-items: center; justify-content: center;
            font-family: var(--font-display); font-weight: 700; font-size: 0.95rem;
            color: var(--primary-foreground);
            background: linear-gradient(135deg, var(--primary), color-mix(in oklch, var(--primary) 70%, #fff));
        }
        .pub-profile-avatar {
            width: 88px; height: 88px; margin: 0 auto 14px;
            border-radius: 50%; overflow: hidden;
            display: flex; align-items: center; justify-content: center;
            font-family: var(--font-display); font-weight: 700; font-size: 2.2rem;
            color: var(--primary-foreground);
            background: linear-gradient(135deg, var(--primary), color-mix(in oklch, var(--primary) 70%, #fff));
            border: 2px solid color-mix(in oklch, var(--primary) 35%, transparent);
        }
        .pub-profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .pub-profile-meta { font-size: 0.85rem; color: var(--muted-foreground); margin: 2px 0 18px; }
        .pub-profile-actions { display: flex; flex-direction: column; gap: 8px; }
        .pub-profile-actions a { text-decoration: none; }
        .pub-profile-btn {
            display: flex; align-items: center; justify-content: center; gap: 6px;
            padding: 10px; border-radius: var(--radius-md);
            border: 1px solid var(--border); color: var(--foreground); background: transparent;
            font-size: 0.9rem; font-weight: 600; cursor: pointer;
            transition: all 0.15s;
        }
        .pub-profile-btn:hover { background: var(--muted); }
        .pub-profile-logout {
            display: flex; align-items: center; justify-content: center; gap: 6px;
            padding: 10px; border-radius: var(--radius-md);
            border: 1px solid var(--destructive); color: var(--destructive); background: transparent;
            font-size: 0.9rem; font-weight: 600; cursor: pointer; transition: all 0.15s;
        }
        .pub-profile-logout:hover { background: color-mix(in oklch, var(--destructive) 10%, transparent); }
        #voteLoginError { margin-top: 10px; font-size: 0.85rem; color: var(--destructive); min-height: 0; }
        @media (max-width: 600px) {
            #clock { display: none; }
            .pub-emp-btn .pub-emp-label { display: none; }
            .pub-emp-btn { padding: 6px 10px; }
        }
    </style>
</head>
<body<?= $allPresent ? ' class="all-present"' : '' ?>>
    <div id="ptr-indicator" style="text-align:center;font-size:0.8rem;color:var(--muted-foreground);height:0;overflow:hidden;transition:height 0.2s">↓ Lepas untuk refresh</div>

    <input type="checkbox" id="drawer-toggle" class="drawer-checkbox">
    <nav class="topbar">
        <div style="display:flex;align-items:center;gap:12px;min-width:0">
            <span class="brand">Dashboard Publik</span>
            <span id="countdown" style="font-size:0.78rem;color:var(--muted-foreground);white-space:nowrap">Refresh</span>
        </div>
        <span id="clock" style="flex:1;text-align:center;font-family:var(--font-mono);font-weight:700;font-size:1.05rem"></span>
        <div class="topbar-right" style="display:flex;align-items:center;gap:10px">
            <label class="theme-switch-btn" for="theme-popup-checkbox" title="Pilih Tema">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
                <span class="theme-btn-label">Tema</span>
            </label>
            <button type="button" class="pub-avatar-btn" id="pubAvatarBtn" title="<?= $loggedIn ? 'Profil' : 'Login' ?>">
                <?php if ($loggedIn): ?>
                    <?php if ($userHasPhoto): ?>
                        <img src="<?= e($userPhotoUrl) ?>" alt="<?= e($userName) ?>">
                    <?php else: ?>
                        <span class="pub-avatar-fallback"><?= e($userInitial) ?></span>
                    <?php endif; ?>
                <?php else: ?>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <?php endif; ?>
            </button>
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
            <a class="active" href="public.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>
                <span>Dashboard Publik</span>
            </a>
            <a href="employee.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <span>Dashboard Karyawan</span>
            </a>
            <?php if ($loggedIn): ?>
            <a href="profile.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <span>Profil & Pengaturan</span>
            </a>
            <a href="#" class="logout-btn" id="drawerLogoutBtn">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
                <span>Keluar</span>
            </a>
            <?php endif; ?>
            <div class="drawer-divider"></div>
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
        <?php breadcrumb([['label' => 'Dashboard Publik']]); ?>
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
                <tr><th class="pub-no">No</th><th>Nama</th><th class="pub-no" style="text-align:right">Vote</th></tr>
            </thead>
            <tbody>
                <?php foreach ($names as $i => $emp): ?>
                <?php $loveCount = $voteCounts[$emp['emp_code']] ?? 0; $hasLoved = !empty($myVotes[$emp['emp_code']]); ?>
                <tr data-emp="<?= e($emp['emp_code']) ?>">
                    <td style="text-align:center;width:48px" class="pub-no"><?= $i + 1 ?></td>
                    <td class="pub-name">
                        <?php if (!empty($emp['photo'])): ?>
                            <span class="emp-thumb"><img src="<?= e($emp['photo']) ?>" alt=""></span>
                        <?php else: ?>
                            <span class="emp-thumb emp-thumb-fallback"><?= strtoupper(mb_substr($emp['name'], 0, 1)) ?></span>
                        <?php endif; ?>
                        <?= e($emp['name']) ?>
                    </td>
                    <td style="text-align:right;white-space:nowrap">
                        <button type="button" class="vote-btn<?= $hasLoved ? ' active' : '' ?>" title="Suka / Love">❤️ <span class="vote-n"><?= (int) $loveCount ?></span></button>
                    </td>
                </tr>
                <?php endforeach ?>
            </tbody>
        </table>
        <?php endforeach ?>
        <?php endif ?>
    </main>

    <!-- LOGIN MODAL UNTUK VOTE -->
    <div id="voteLoginModal" class="modal-backdrop">
        <div class="modal-box" style="max-width:340px">
            <h2>Login untuk vote</h2>
            <p style="font-size:0.85rem;color:var(--muted-foreground);margin-top:-8px;margin-bottom:16px">Gunakan akun absensi kamu untuk memberi like/dislike.</p>
            <form id="voteLoginForm" autocomplete="off">
                <label>Username
                    <input type="text" name="username" required autocomplete="username">
                </label>
                <label style="margin-top:10px">Password
                    <input type="password" name="password" required autocomplete="current-password">
                </label>
                <div style="display:flex;gap:8px;margin-top:16px">
                    <button type="button" id="voteLoginCancel" style="flex:1;background:transparent;border:1px solid var(--border);color:var(--foreground)">Batal</button>
                    <button type="submit" style="flex:1">Masuk</button>
                </div>
                <div id="voteLoginError"></div>
            </form>
        </div>
    </div>

    <!-- PROFIL MODAL (khusus yang sudah login) -->
    <?php if ($loggedIn): ?>
    <div id="pubProfileModal" class="modal-backdrop<?= $openProfile ? ' show' : '' ?>">
        <div class="modal-box" style="max-width:360px;text-align:center">
            <div class="pub-profile-avatar">
                <?php if ($userHasPhoto): ?>
                    <img src="<?= e($userPhotoUrl) ?>" alt="<?= e($userName) ?>">
                <?php else: ?>
                    <span><?= e($userInitial) ?></span>
                <?php endif; ?>
            </div>
            <h2 style="margin:0"><?= e($userName) ?></h2>
            <p class="pub-profile-meta"><?= e($username) ?> · <?= e($userRole) ?></p>
            <div class="pub-profile-actions">
                <a href="<?= $userRole === 'employee' ? 'employee.php' : 'index.php' ?>" class="pub-profile-btn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <span><?= $userRole === 'employee' ? 'Dashboard Karyawan' : 'Dashboard Admin' ?></span>
                </a>
                <a href="profile.php" class="pub-profile-btn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <span>Profil & Pengaturan</span>
                </a>
                <button type="button" class="pub-profile-logout" id="pubLogoutBtn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
                    <span>Logout</span>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="assets/toast.js"></script>
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

        // THEME SWITCHER (gaya navbar employee.php)
        var themeList = document.getElementById('themeList');
        var themeCheck = document.getElementById('theme-popup-checkbox');
        var savedTheme = localStorage.getItem('absensi-theme') || '';
        function applyTheme(t) {
            document.body.classList.remove('dark','theme-sakura','theme-bamboo','theme-cyberpunk','theme-mingyu','theme-ocean','theme-retrolight');
            if (t) document.body.classList.add(t);
        }
        applyTheme(savedTheme);
        if (themeList) {
            themeList.querySelectorAll('label').forEach(function(lbl) {
                if (String(lbl.dataset.theme) === savedTheme) lbl.style.outline = '2px solid var(--primary)';
            });
            themeList.addEventListener('click', function(e) {
                var lbl = e.target.closest('label');
                if (!lbl) return;
                var t = lbl.dataset.theme;
                localStorage.setItem('absensi-theme', t);
                applyTheme(t);
                if (themeCheck) themeCheck.checked = false;
                themeList.querySelectorAll('label').forEach(function(l) { l.style.outline = ''; });
                lbl.style.outline = '2px solid var(--primary)';
            });
        }

        // NAVBAR FLOAT ON SCROLL
        (function() {
            var nav = document.querySelector('.topbar');
            if (!nav) return;
            var ticking = false;
            var isScrolled = false;
            window.addEventListener('scroll', function () {
                if (!ticking) {
                    window.requestAnimationFrame(function() {
                        var y = window.scrollY || window.pageYOffset;
                        if (!isScrolled && y > 90) { nav.classList.add('scrolled'); isScrolled = true; }
                        else if (isScrolled && y < 30) { nav.classList.remove('scrolled'); isScrolled = false; }
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
    })();

    // ---- LIKE/DISLIKE VOTE ----
    // (terpisah dari IIFE biar mudah dibaca; pakai var global AppToast dari toast.js)
    (function () {
        var LOGGED_IN = <?= json_encode($loggedIn) ?>;
        var CURRENT_ROLE = <?= json_encode($userRole, JSON_UNESCAPED_UNICODE) ?>;
        var GO_ACTION = null; // 'profile' -> buka modal profil setelah login
        var pendingVote = null;
        var loginModal = document.getElementById('voteLoginModal');
        var loginForm = document.getElementById('voteLoginForm');
        var loginError = document.getElementById('voteLoginError');

        function updateRow(empCode, d) {
            var row = document.querySelector('tr[data-emp="' + empCode + '"]');
            if (!row) return;
            row.querySelector('.vote-n').textContent = d.love_count;
            var btn = row.querySelector('.vote-btn');
            if (btn) btn.classList.toggle('active', !!d.my_vote);
        }

        function submitVote(empCode) {
            var fd = new FormData();
            fd.append('action', 'vote');
            fd.append('emp_code', empCode);
            fetch('api_vote.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (!d.success) {
                        if (d.message) AppToast.error(d.message);
                        return;
                    }
                    updateRow(empCode, d);
                    if (d.state === 'removed') AppToast.info('Love kamu dihapus.');
                    else if (d.state === 'added') AppToast.info('Love kamu ditambahkan ❤️');
                })
                .catch(function() { AppToast.error('Gagal menghubungi server.'); });
        }

        function doVote(empCode) {
            if (!LOGGED_IN) {
                pendingVote = { empCode: empCode };
                openVoteLogin();
                return;
            }
            submitVote(empCode);
        }

        function openVoteLogin() {
            loginError.textContent = '';
            loginModal.classList.add('show');
            var u = loginForm.querySelector('input[name=username]');
            setTimeout(function() { u.focus(); }, 50);
        }

        function closeVoteLogin() {
            loginModal.classList.remove('show');
            loginForm.reset();
            loginError.textContent = '';
            pendingVote = null;
            GO_ACTION = null;
        }

        function doLogout() {
            var fd = new FormData();
            fd.append('action', 'logout');
            fetch('api_vote.php', { method: 'POST', body: fd })
                .then(function() {
                    LOGGED_IN = false;
                    document.querySelectorAll('.vote-btn.active').forEach(function(b) { b.classList.remove('active'); });
                    AppToast.success('Berhasil logout.');
                    window.location.reload();
                })
                .catch(function() { AppToast.error('Gagal logout.'); });
        }

        // PROFIL MODAL
        var pubProfileModal = document.getElementById('pubProfileModal');
        function pubOpenProfile() { if (pubProfileModal) pubProfileModal.classList.add('show'); }
        function pubCloseProfile() { if (pubProfileModal) pubProfileModal.classList.remove('show'); }

        // Klik di area luar modal (backdrop) -> tutup.
        if (pubProfileModal) {
            pubProfileModal.addEventListener('click', function(e) {
                if (e.target === pubProfileModal) pubCloseProfile();
            });
        }

        // Close modal-via-Esc: modal profil, modal login, dan drawer.
        document.addEventListener('keydown', function(e) {
            if (e.key !== 'Escape') return;
            pubCloseProfile();
            closeVoteLogin();
            var t = document.getElementById('drawer-toggle');
            if (t) t.checked = false;
        });

        var pubLogoutBtn = document.getElementById('pubLogoutBtn');
        if (pubLogoutBtn) pubLogoutBtn.addEventListener('click', doLogout);
        var drawerLogoutBtn = document.getElementById('drawerLogoutBtn');
        if (drawerLogoutBtn) drawerLogoutBtn.addEventListener('click', function(e) { e.preventDefault(); doLogout(); });

        var avatarBtn = document.getElementById('pubAvatarBtn');
        if (avatarBtn) {
            avatarBtn.addEventListener('click', function() {
                if (LOGGED_IN) { pubOpenProfile(); return; }
                GO_ACTION = 'profile';
                openVoteLogin();
            });
        }

        document.querySelectorAll('.vote-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var row = btn.closest('tr');
                if (row) doVote(row.dataset.emp);
            });
        });

        if (loginForm) {
            loginModal.addEventListener('click', function(e) {
                if (e.target === loginModal) closeVoteLogin();
            });
            loginForm.addEventListener('submit', function(e) {
                e.preventDefault();
                var fd = new FormData(loginForm);
                fd.append('action', 'login');
                fd.append('intent', GO_ACTION || '');
                loginError.textContent = '';
                fetch('api_vote.php', { method: 'POST', body: fd })
                    .then(function(r) {
                        return r.json().then(function(d) { return { status: r.status, body: d }; });
                    })
                    .then(function(res) {
                        var d = res.body;
                        if (!d.success) {
                            loginError.textContent = d.message || 'Login gagal.';
                            return;
                        }
                        LOGGED_IN = true;
                        loginModal.classList.remove('show');
                        loginForm.reset();
                        GO_ACTION = null;
                        if (pendingVote) {
                            var pv = pendingVote;
                            pendingVote = null;
                            submitVote(pv.empCode);
                        }
                        window.location.reload();
                    })
                    .catch(function() { loginError.textContent = 'Gagal menghubungi server.'; });
            });
            var cancel = document.getElementById('voteLoginCancel');
            if (cancel) cancel.addEventListener('click', closeVoteLogin);
        }
    })();
    </script>
</body>
</html>
