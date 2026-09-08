<?php
require __DIR__ . '/config.php';
require_login();

$username = $_SESSION['username'] ?? '';
$roleNow  = $_SESSION['role'] ?? '';
if ($username === '') {
    header('Location: logout.php');
    exit;
}

$user = local_user($username);
if ($user === null) {
    header('Location: logout.php');
    exit;
}

$empCode = $user['emp_code'] ?? '';
$name    = $user['name'] ?? $user['username'];

if ($empCode !== '') {
    $stEmp = db()->prepare(
        "SELECT a.emp_code, a.first_name, b.dept_name
         FROM personnel_employee a
         LEFT JOIN personnel_department b ON b.dept_code = a.department_id
         WHERE a.emp_code = :code LIMIT 1"
    );
    $stEmp->execute([':code' => $empCode]);
    $emp = $stEmp->fetch();
    if ($emp) {
        $name   = $emp['first_name'];
        $dept   = $emp['dept_name'] ?? '-';
        $empId  = $emp['emp_code'];
    }
} else {
    $dept  = '-';
    $empId = '-';
}

$hasPhoto = photo_path($username) !== null;
$photoUrl = $hasPhoto ? ('photo.php?u=' . rawurlencode($username)) : '';

// --- JSON API MODE ---
// Request dari Android (atau header Accept: application/json) akan diberi respons JSON.
$isApi = false;
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    $isApi = true;
} elseif (($_SERVER['HTTP_ACCEPT'] ?? '') && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
    $isApi = true;
} elseif (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
    $isApi = true;
}

if ($isApi) {
    header('Content-Type: application/json; charset=utf-8');

    $deptName = $dept ?? '-';
    $empId    = $empId ?? '-';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        $err    = null;
        $ok     = null;

        if ($action === 'photo') {
            if (empty($_FILES['photo']) || ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $err = 'Pilih file foto terlebih dahulu.';
            } else {
                $f = $_FILES['photo'];
                if ($f['size'] > 2 * 1024 * 1024) {
                    $err = 'Ukuran foto maksimal 2 MB.';
                } else {
                    $info = @getimagesize($f['tmp_name']);
                    if ($info === false) {
                        $err = 'File bukan gambar yang valid.';
                    } elseif ($info[0] > 4000 || $info[1] > 4000) {
                        $err = 'Dimensi gambar terlalu besar (maks 4000x4000 px).';
                    } else {
                        $mimeMap = [
                            'image/jpeg' => 'jpg',
                            'image/png'  => 'png',
                            'image/webp' => 'webp',
                        ];
                        $mime = $info['mime'];
                        if (!isset($mimeMap[$mime])) {
                            $err = 'Format foto harus JPG, PNG, atau WEBP.';
                        } else {
                            $ext = $mimeMap[$mime];
                            $safeUser = preg_replace('/[^A-Za-z0-9_\-]/', '_', $username);
                            $dest = photo_dir() . '/' . $safeUser . '.' . $ext;

                            $oldPhoto = $user['photo'] ?? '';
                            if ($oldPhoto !== '' && $oldPhoto !== basename($dest)) {
                                $old = photo_dir() . '/' . basename($oldPhoto);
                                if (is_file($old)) {
                                    @unlink($old);
                                }
                            }

                            if (!move_uploaded_file($f['tmp_name'], $dest)) {
                                $err = 'Gagal menyimpan foto.';
                            } else {
                                @chmod($dest, 0660);
                                $db = local_db();
                                $st = $db->prepare("UPDATE users SET photo = :p WHERE username = :u");
                                $st->execute([':p' => basename($dest), ':u' => $username]);
                                $ok = 'Foto profil berhasil diperbarui.';
                            }
                        }
                    }
                }
            }
            if ($err !== null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => $err], JSON_UNESCAPED_UNICODE);
                exit;
            }
            echo json_encode([
                'success'   => true,
                'message'   => $ok,
                'photo_url' => 'photo.php?u=' . rawurlencode($username),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'password') {
            $cur     = $_POST['current_password'] ?? '';
            $new     = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            if ($cur === '' || $new === '' || $confirm === '') {
                $err = 'Semua kolom password wajib diisi.';
            } elseif (!verify_user_password($cur, $user['password_hash'], $user['algo'])) {
                $err = 'Password lama salah.';
            } elseif (strlen($new) < 6) {
                $err = 'Password baru minimal 6 karakter.';
            } elseif ($new !== $confirm) {
                $err = 'Password baru dan konfirmasi tidak cocok.';
            } elseif (hash_equals($cur, $new)) {
                $err = 'Password baru tidak boleh sama dengan password lama.';
            } else {
                $db = local_db();
                $st = $db->prepare("UPDATE users SET password_hash = :h, algo = 'bcrypt' WHERE username = :u");
                $st->execute([':h' => password_hash($new, PASSWORD_DEFAULT), ':u' => $username]);
                session_regenerate_id(true);
                $ok = 'Password berhasil diganti.';
            }
            if ($err !== null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => $err], JSON_UNESCAPED_UNICODE);
                exit;
            }
            echo json_encode(['success' => true, 'message' => $ok], JSON_UNESCAPED_UNICODE);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenal.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // GET → profil
    echo json_encode([
        'success'    => true,
        'name'       => $name,
        'username'   => $username,
        'emp_code'   => $empId,
        'dept'       => $deptName,
        'role'       => $roleNow,
        'has_photo'  => $hasPhoto,
        'photo_url'  => $hasPhoto ? $photoUrl : null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- HANDLE POST (HTML/redireksi, untuk browser web) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'photo') {
        $err = null;
        if (empty($_FILES['photo']) || ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = 'Pilih file foto terlebih dahulu.';
        } else {
            $f = $_FILES['photo'];
            if ($f['size'] > 2 * 1024 * 1024) {
                $err = 'Ukuran foto maksimal 2 MB.';
            } else {
                $info = @getimagesize($f['tmp_name']);
                if ($info === false) {
                    $err = 'File bukan gambar yang valid.';
                } elseif ($info[0] > 4000 || $info[1] > 4000) {
                    $err = 'Dimensi gambar terlalu besar (maks 4000x4000 px).';
                } else {
                    $mimeMap = [
                        'image/jpeg' => 'jpg',
                        'image/png'  => 'png',
                        'image/webp' => 'webp',
                    ];
                    $mime = $info['mime'];
                    if (!isset($mimeMap[$mime])) {
                        $err = 'Format foto harus JPG, PNG, atau WEBP.';
                    } else {
                        $ext = $mimeMap[$mime];
                        $safeUser = preg_replace('/[^A-Za-z0-9_\-]/', '_', $username);
                        $dest = photo_dir() . '/' . $safeUser . '.' . $ext;

                        $oldPhoto = $user['photo'] ?? '';
                        if ($oldPhoto !== '' && $oldPhoto !== basename($dest)) {
                            $old = photo_dir() . '/' . basename($oldPhoto);
                            if (is_file($old)) {
                                @unlink($old);
                            }
                        }

                        if (!move_uploaded_file($f['tmp_name'], $dest)) {
                            $err = 'Gagal menyimpan foto.';
                        } else {
                            @chmod($dest, 0660);
                            $db = local_db();
                            $st = $db->prepare("UPDATE users SET photo = :p WHERE username = :u");
                            $st->execute([':p' => basename($dest), ':u' => $username]);
                            flash_set('success', 'Foto profil berhasil diperbarui.');
                            header('Location: profile.php');
                            exit;
                        }
                    }
                }
            }
        }
        if ($err) {
            flash_set('error', $err);
        }
    }

    if ($action === 'password') {
        $cur      = $_POST['current_password'] ?? '';
        $new      = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if ($cur === '' || $new === '' || $confirm === '') {
            flash_set('error', 'Semua kolom password wajib diisi.');
        } elseif (!verify_user_password($cur, $user['password_hash'], $user['algo'])) {
            flash_set('error', 'Password lama salah.');
        } elseif (strlen($new) < 6) {
            flash_set('error', 'Password baru minimal 6 karakter.');
        } elseif ($new !== $confirm) {
            flash_set('error', 'Password baru dan konfirmasi tidak cocok.');
        } elseif (hash_equals($cur, $new)) {
            flash_set('error', 'Password baru tidak boleh sama dengan password lama.');
        } else {
            $db = local_db();
            $st = $db->prepare("UPDATE users SET password_hash = :h, algo = 'bcrypt' WHERE username = :u");
            $st->execute([':h' => password_hash($new, PASSWORD_DEFAULT), ':u' => $username]);
            session_regenerate_id(true);
            flash_set('success', 'Password berhasil diganti.');
        }
        header('Location: profile.php');
        exit;
    }

    if (isset($err)) {
        header('Location: profile.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil - <?= e($name) ?></title>
    <link rel="stylesheet" href="assets/pl-komatsu-ui-template.css">
    <link rel="stylesheet" href="assets/style.css">
    <style>
        html { scroll-behavior: smooth; scrollbar-gutter: stable; overflow-y: scroll; }
        .container { max-width: 720px; }

        .profile-header {
            text-align: center;
            padding: 8px 0 26px;
        }
        .avatar-wrap { position: relative; display: inline-block; }
        .profile-avatar {
            width: 104px; height: 104px;
            margin: 0 auto 16px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), color-mix(in oklch, var(--primary) 70%, #fff));
            color: var(--primary-foreground);
            font-family: var(--font-display);
            font-size: 2.6rem; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
            box-shadow: 0 8px 24px -6px color-mix(in oklch, var(--primary) 40%, transparent),
                        inset 0 1.5px 1.5px 0 color-mix(in oklch, #fff 40%, transparent);
            border: 1px solid color-mix(in oklch, #fff 30%, var(--primary));
        }
        .profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .profile-name { font-family: var(--font-display); font-size: 1.5rem; font-weight: 700; margin: 0; color: var(--foreground); }
        .profile-meta { color: var(--muted-foreground); font-size: 0.9rem; margin-top: 6px; }

        .settings-box {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            padding: 22px;
            margin-bottom: 22px;
        }
        .settings-box h2 { margin: 0 0 16px; font-size: 1.05rem; color: var(--foreground); }

        .form-group { margin-bottom: 14px; }
        .form-group label {
            display: block;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--muted-foreground);
            margin-bottom: 6px;
        }
        .form-group input[type=text],
        .form-group input[type=password],
        .form-group input[type=file] {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            background: var(--background);
            color: var(--foreground);
            font-family: var(--font-sans);
            font-size: 0.92rem;
            outline: none;
            box-sizing: border-box;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-group input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px color-mix(in oklch, var(--primary) 20%, transparent);
        }
        .form-group .hint { font-size: 0.75rem; color: var(--muted-foreground); margin-top: 6px; }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 22px;
            background: var(--primary);
            color: var(--primary-foreground);
            border: none;
            border-radius: 99px;
            font-family: var(--font-sans);
            font-size: 0.88rem;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 12px color-mix(in oklch, var(--primary) 22%, transparent);
            transition: opacity 0.2s, transform 0.1s;
        }
        .btn-primary:hover { opacity: 0.92; transform: translateY(-1px); }
        .btn-primary:active { transform: translateY(0) scale(0.98); }
        .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }

        .divider-box { height: 1px; background: var(--border); margin: 4px 0 20px; }
    </style>
</head>
<body>
    <input type="checkbox" id="drawer-toggle" class="drawer-checkbox">
    <nav class="topbar">
        <span class="brand">Profil - <?= e($name) ?></span>
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
            <?php if ($roleNow === 'employee'): ?>
            <a href="employee.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span>Absensi Saya</span>
            </a>
            <?php else: ?>
            <a href="index.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span>Dashboard</span>
            </a>
            <a href="weekly.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                <span>Mingguan</span>
            </a>
            <?php endif; ?>
            <a class="active" href="profile.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <span>Profil & Pengaturan</span>
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
            <div class="avatar-wrap">
                <div class="profile-avatar" id="avatarBox">
                    <?php if ($hasPhoto): ?>
                        <img src="<?= e($photoUrl) ?>" alt="<?= e($name) ?>">
                    <?php else: ?>
                        <?= strtoupper(mb_substr($name, 0, 1)) ?>
                    <?php endif; ?>
                </div>
            </div>
            <h1 class="profile-name"><?= e($name) ?></h1>
            <div class="profile-meta"><?= e($dept) ?> · ID: <?= e($empId) ?> · <span style="text-transform:capitalize"><?= e($roleNow) ?></span></div>
        </div>

        <div class="settings-box">
            <h2>Foto Profil</h2>
            <form method="post" enctype="multipart/form-data" onsubmit="return confirmUpload()">
                <input type="hidden" name="action" value="photo">
                <div class="form-group">
                    <label for="photo">Pilih foto (JPG / PNG / WEBP, maks 2 MB)</label>
                    <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp" required>
                </div>
                <button type="submit" class="btn-primary" id="photoBtn">Simpan Foto</button>
                <?php if ($hasPhoto): ?>
                    <span style="margin-left:10px;font-size:0.8rem;color:var(--muted-foreground)">Foto aktif sudah tersimpan.</span>
                <?php endif; ?>
            </form>
        </div>

        <div class="divider-box"></div>

        <div class="settings-box">
            <h2>Ganti Password</h2>
            <form method="post">
                <input type="hidden" name="action" value="password">
                <div class="form-group">
                    <label for="current_password">Password lama</label>
                    <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                </div>
                <div class="form-group">
                    <label for="new_password">Password baru</label>
                    <input type="password" id="new_password" name="new_password" required autocomplete="new-password">
                    <div class="hint">Minimal 6 karakter.</div>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Konfirmasi password baru</label>
                    <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn-primary">Ganti Password</button>
            </form>
        </div>
    </main>

    <script>
    function confirmUpload() {
        var box = document.getElementById('avatarBox');
        return true;
    }
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

    // Live preview foto sebelum upload
    (function() {
        var input = document.getElementById('photo');
        var box = document.getElementById('avatarBox');
        var btn = document.getElementById('photoBtn');
        if (!input || !box) return;
        input.addEventListener('change', function() {
            var file = this.files && this.files[0];
            if (!file) return;
            if (file.size > 2 * 1024 * 1024) { alert('Ukuran maksimal 2 MB.'); this.value = ''; return; }
            if (!/image\/(jpeg|png|webp)/.test(file.type)) { alert('Format harus JPG/PNG/WEBP.'); this.value = ''; return; }
            var reader = new FileReader();
            reader.onload = function(e) {
                box.innerHTML = '<img src="' + e.target.result + '" alt="Preview">';
            };
            reader.readAsDataURL(file);
            if (btn) btn.textContent = 'Simpan Foto (baru terpilih)';
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
    </script>
    <script src="assets/toast.js"></script>
    <?= toast_js() ?>
</body>
</html>