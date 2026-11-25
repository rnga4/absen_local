# Audit Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Menutup temuan audit yang masih berlaku tanpa mengubah dua perilaku yang disengaja user.

**Architecture:** Patch langsung di file PHP yang sudah ada; guard diletakkan di entry point (CLI vs HTTP, role, tipe input); verifikasi memakai shell/curl/headless Chrome seperti probe existing.

**Tech Stack:** PHP 8.3-fpm, SQLite, MySQL, nginx, bash + google-chrome headless.

**Spec:** `docs/superpowers/specs/2026-11-25-audit-fixes-design.md`

## Global Constraints

- Jangan menyentuh `app/seed_employee_users.php` (password karyawan disengaja).
- Jangan menambah filter tanggal di `votes_today_counts()` (❤️ akumulatif disengaja).
- Jangan menambah dependency, endpoint, migrasi, atau perubahan skema DB.
- Setiap task: TDD (RED terlihat dulu), `php -l` pada file yang diubah, `git diff --check`, commit.
- cookie login admin: `/tmp/opencode/rekap-admin.cookie`; cookie karyawan: `/tmp/opencode/abdul-audit.cookie`.

## Review Focus

- Karyawan mengulang `GET export.php` → harus 403, bukan daftar 43 orang (Task 1).
- Session lama tanpa key `role` → harus jatuh ke employee, bukan admin (Task 1).
- `?date=2026-02-30` dan `?d[]=...` → harus ditolak/fallback, bukan 500 atau data kosong (Task 2).
- Double-tap ❤️ bersamaan → harus JSON, bukan HTML 500 (Task 2).
- Upload foto gagal → avatar lama harus tetap ada (Task 3).

---

### Task 1: Guard akses & higiene session

**Files:**
- Modify: `app/notify.php`, `app/export.php`, `app/config.php`, `app/index.php`, `app/api_history.php`, `app/photo.php`
- Create: `/tmp/opencode/verify-audit-access.sh` (artefak lokal)

**Interfaces:**
- Consumes: `$_SESSION['role']`, `PHP_SAPI`, helper `e()`.
- Produces: 403 untuk non-CLI `notify.php` dan employee `export.php`; cookie `HttpOnly; SameSite=Lax`.

- [ ] **Step 1: Tulis test yang gagal** di `/tmp/opencode/verify-audit-access.sh`:
  - `GET export.php` dengan cookie abdul → status `403`.
  - `POST login.php` una → header `Set-Cookie` mengandung `HttpOnly` dan `SameSite=Lax`.
  - `GET photo.php?u=abdul` dengan cookie admin → header `Cache-Control: private, no-store`.
  - `grep -q "PHP_SAPI !== 'cli'" app/notify.php`.
  - `grep -q "\$roleNow = \$_SESSION\['role'\] ?? 'employee'" app/api_history.php` dan `app/photo.php`.
  - `grep -q "createElement('img')" app/index.php` dan `! grep -q "avatar.innerHTML = '<img src='" app/index.php`.
  - `grep -q "parse_mode" app/notify.php` harus TIDAK ada (dikerjakan di Task 3, jadi assertion ini ditambah di Task 3).

- [ ] **Step 2:** Jalankan script; pastikan RED pada export/cookie/cache/guard.
- [ ] **Step 3:** Implementasi:
  - `app/notify.php`: `if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }` sebelum `require` (guard sebelum query/Telegram).
  - `app/export.php`: setelah `require_login()`, tolak `role === 'employee'` dengan 403.
  - `app/config.php`: `session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])])` sebelum `session_start()`.
  - `app/index.php:625`: ganti `innerHTML` concat dengan `var im = document.createElement('img'); im.src = photo; im.alt = name || ''; ... avatar.replaceChildren(im);` (style inline tetap).
  - `app/api_history.php:15`, `app/photo.php:17`: `?? 'employee'`.
  - `app/photo.php:53`: `Cache-Control` = `public, max-age=86400` hanya bila `$public`, else `private, no-store`.
- [ ] **Step 4:** Jalankan `verify-audit-access.sh`, `verify-uang-makan.sh`, `verify-card-detail.sh`, `php -l` 6 file, `git diff --check`; semua PASS.
- [ ] **Step 5:** Commit `fix: close HTTP access gaps and harden session cookies`.

### Task 2: Input & output robustness

**Files:**
- Modify: `app/api_dashboard.php`, `app/export.php`, `app/api_leaderboard.php`, `app/api_history.php`, `app/login.php`, `app/photo.php`, `app/visitors.php`, `app/config.php`
- Create: `/tmp/opencode/verify-audit-input.sh`

**Interfaces:**
- Consumes: `$_GET`, `$_POST`, `csv_text()`/`csv_num()` pola export.
- Produces: tanggal valid, JSON 200 untuk parameter array, UPSERT vote; `export.php` meng-escape rumus spreadsheet.

- [ ] **Step 1: Tulis test yang gagal** di `/tmp/opencode/verify-audit-input.sh`:
  - admin `api_dashboard.php?date=2026-02-30` → field `date` sama dengan `date('Y-m-d')`.
  - admin `export.php?d=2026-02-30` → `Content-Disposition` memuat `absensi_<today>.csv`.
  - admin `visitors.php?date=2026-02-30` → halaman 200, tidak menampilkan `2026-02-30`.
  - `api_leaderboard.php?year=2030` → field `year` == tahun sekarang dan `workdays > 0`.
  - `api_history.php?code[]=38`, `api_leaderboard.php?year[]=2026`, `photo.php?u[]=abdul` → status bukan 500.
  - `curl -d 'password[]=x' login.php` → status 200 dan badan mengandung `Username atau password salah`.
  - `grep -q 'ON CONFLICT(username, emp_code, vote_date) DO NOTHING' app/config.php`.
  - `grep -q` ekspresi escape rumus (`^[=+\-@]`) di `app/export.php`.

- [ ] **Step 2:** Jalankan; pastikan RED.
- [ ] **Step 3:** Implementasi:
  - validasi tanggal: setelah `preg_match`, `checkdate((int)m[2],(int)m[3],(int)m[1])` else fallback; pola sama di 3 file.
  - `api_leaderboard.php`: `$year = min($year, (int)date('Y'));` setelah validasi regex.
  - `is_string($_GET[...] ?? null) ? ... : ''` / `?? ''` sebelum `trim`/`preg_match`/`hash_equals` di `api_history.php`, `api_leaderboard.php`, `export.php`, `photo.php`, `login.php`.
  - `config.php:237`: `INSERT ... ON CONFLICT(username, emp_code, vote_date) DO NOTHING`; `state` dari `$st->rowCount()` (`added` bila 1, else `removed` tetap dari cabang DELETE).
  - `export.php`: prefix `'` untuk nilai teks yang diawali `=`, `+`, `-`, `@`, tab, CR.
- [ ] **Step 4:** Jalankan `verify-audit-input.sh` + probe existing + `php -l` + `git diff --check`; semua PASS.
- [ ] **Step 5:** Commit `fix: validate dates, reject array params, and harden CSV/vote writes`.

### Task 3: Perilaku salah & cleanup

**Files:**
- Modify: `app/api_dashboard.php`, `app/weekly.php`, `app/setup_users.php`, `app/profile.php`, `app/notify.php`, `Dockerfile`
- Create: `/tmp/opencode/verify-audit-behavior.sh`

**Interfaces:**
- Consumes: `$stat`, `week_from_date()`, `move_uploaded_file()`, seed admin.
- Produces: stat mirror unfiltered, tanggal lokal, warna denda tampil, seed tidak menimpa password.

- [ ] **Step 1: Tulis test yang gagal** di `/tmp/opencode/verify-audit-behavior.sh`:
  - admin `api_dashboard.php?f=hadir` → `stat == ['total'=>43,'hadir'=>40,'telat'=>2,'belum'=>1]` (sama dengan tanpa filter; angka Nov 2026).
  - DOM `weekly.php?tanggal=2026-11-30`, panggil `move(7)` → `input[name=tanggal].value == '2026-12-07'`.
  - `grep` baris `global` di `compute_row()` mengandung `$IN_COLORS, $OUT_COLORS`.
  - `grep setup_users.php`: ada `INSERT OR IGNORE`, tidak ada `ON CONFLICT(username) DO UPDATE`.
  - `profile.php`: di kedua cabang, `move_uploaded_file` sebelum `unlink`.
  - `grep notify.php`: tidak ada `parse_mode`.
  - `grep Dockerfile`: tidak ada `mysqli`.
- [ ] **Step 2:** Jalankan; pastikan RED.
- [ ] **Step 3:** Implementasi:
  - `api_dashboard.php`: pindahkan `$stat['total']++` / `$stat[$status]++` ke atas `if ($filter !== '' && ...) continue;`.
  - `weekly.php:379`: format lokal `t.getFullYear()+'-'+String(t.getMonth()+1).padStart(2,'0')+'-'+String(t.getDate()).padStart(2,'0')`.
  - `weekly.php:125`: tambahkan `$IN_COLORS, $OUT_COLORS` ke `global`.
  - `setup_users.php:117`: ganti `ON CONFLICT(username) DO UPDATE SET ...` menjadi `INSERT OR IGNORE` (hapus klausul update).
  - `profile.php:92-98` dan `210-216`: pindahkan blok unlink ke dalam cabang `else` setelah `move_uploaded_file()` sukses.
  - `notify.php:10`: hapus `'parse_mode' => 'Markdown'` dari payload.
  - `Dockerfile:3`: `RUN docker-php-ext-install pdo_mysql`.
- [ ] **Step 4:** Jalankan `verify-audit-behavior.sh`, semua probe existing, `php -l` semua file, `git diff --check`; semua PASS.
- [ ] **Step 5:** Commit `fix: correct filtered stats, weekly nav, and safe seed/upload`, lalu `git push origin master`.

### Task 4: Review akhir

- [ ] Fresh-context reviewer atas range `HEAD~3..HEAD`; tangani hanya Critical/Important.
- [ ] Verifikasi `git status --short --branch` (biarkan `notify.log` untracked).
