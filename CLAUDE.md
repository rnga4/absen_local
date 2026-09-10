# CLAUDE.md — Absensi Monitor

Aplikasi web monitoring absensi ("Absensi Monitor"). PHP murni (tanpa framework/build step), dijalankan via Docker (nginx + php-fpm).

## Arsitektur & Stack

- Container: `absensi-nginx` (port **9790**) + `absensi-fpm` (php:8.3-fpm-alpine).
- Folder `app/` di-mount sebagai volume ke `/var/www/html` → **cukup edit file lalu reload browser, TIDAK perlu rebuild**.
- PHP alpine **tanpa GD/imagick/exif** → validasi gambar memakai `getimagesize()` (tanpa resize).
- Tema: token OKLCH di `app/assets/pl-komatsu-ui-template.css` (`--background`, `--card`, `--muted`, `--foreground`, `--border`, `--success`, `--destructive`, `--primary`, dsb). Ganti tema via class `body` (`dark`, `theme-sakura`, ...), persist di `localStorage('absensi-theme')`. Tombol ganti tema terletak 1x di topbar secara konsisten.
- Layout Width: Halaman publik (`public.php`), dashboard karyawan (`employee.php`), dan profil (`profile.php`) menggunakan lebar kontainer konsisten (`.container { max-width: 720px; }`).
- Animasi Transisi & Navigasi: Animasi halaman halus saat berpindah menu (`pageEnter` blur-fade scale-in & `pageExit` slide-fade out via handler `assets/toast.js` & CSS `@view-transition` / keyframes di `assets/style.css`), plus micro-interactions pada menu drawer (`translateX(4px)` & active scale compression).

## Database

- **MySQL** (remote `192.168.1.141:3306`, db `fingerprint_absensi`): data absen asli.
  - `personnel_employee` (emp_code, first_name, department_id), di-`LEFT JOIN` ke `personnel_department` (dept_code, dept_name).
  - `iclock_transaction_backup` (emp_code, punch_time) → data kehadiran.
  - Karyawan aktif = `department_id <> 1`.
- **SQLite** `app/data/users.sqlite`: akun login & data vote harian.
  - Tabel `users`: `id, username, password_hash, algo ('pbkdf2_sha256'|'bcrypt'), role ('admin'|'employee'), emp_code, name, photo, is_active, created_at`.
  - Tabel `votes`: `id, username, emp_code, vote_date, created_at` dengan unique index `idx_votes_u_e_d` `(username, emp_code, vote_date)` untuk single love toggle per hari.

## Login, Navigation & Modals

- `login.php`: cek SQLite `users` dulu (via `verify_user_password`), fallback konstan env `APP_USER`/`APP_PASS`. Set `$_SESSION['logged_in']`, `role`, `username`, `emp_code`.
  - Login sukses: admin → `index.php`, employee → `employee.php`, plus `flash_set('success', ...)` (toast).
- Guard: `require_login()` di `config.php`. `index.php` & `weekly.php` redirect employee ke `employee.php`. `api_history.php`: employee hanya riwayat dirinya sendiri.
- Breadcrumbs: Helper `breadcrumb(array $items)` di `config.php` merender navigasi breadcrumb konsisten pada `public.php`, `employee.php`, `index.php`, `weekly.php`, dan `profile.php`.
- Modal Profil & Vote:
  - Avatar topbar pada `public.php` membuka modal profil bila sudah login.
  - Modal profil berisi info user + tombol "Dashboard Karyawan" / "Dashboard Admin" (role-based).
  - Modal profil dan login vote dapat ditutup via backdrop click atau tombol `Escape`.

## Fitur Single Love Vote (❤️)

- `public.php` & `api_vote.php`: Fitur apresiasi karyawan belum absen menggunakan single love vote toggle (`action=vote`, tanpa type parameter).
- Setiap user (per hari) dapat memberikan atau menarik (toggle) 1 emoji ❤️ pada setiap karyawan yang belum absen.
- Response API: `{ success, state ('added'|'removed'), my_vote (bool), love_count (int) }`.
- Catatan: `api_vote.php` juga menangani `action=login` & `action=logout` (switch-case, lihat bagian API di bawah).

## API Endpoints (JSON — dipakai aplikasi Android)

Endpoint JSON (`Content-Type: application/json`). Sebagian besar dikonsumsi klien eksternal (Android); sebagian juga dipakai PHP web:

- **`api_login.php`** — login JSON. Terima form-encoded atau body JSON (`username`/`password`). Urutan cek: tabel SQLite `users` → fallback env `APP_USER`/`APP_PASS` (admin) → **jalur karyawan** via `personnel_employee.self_password` (PBKDF2, read-only, tidak mengubah DB). Set session & kembalikan `{ success, role, username, name, emp_code?, dept? }`. (Catatan: `login.php` web TIDAK punya jalur `self_password` ini — hanya `api_login.php`.)
- **`api_dashboard.php`** — data dashboard harian. `require_login()`. Role `employee` → hanya data dirinya (in/out/status/late via `emp_code` session). Role `admin` → semua karyawan per departemen, dukungan filter `?f=hadir|telat|belum` & tanggal `?date=YYYY-MM-DD`. Response `{ success, role, stat, departments }`.
- **`api_public.php`** — data halaman publik (tanpa login): karyawan yang **belum** absen hari ini + `love_count` & `my_vote` (bila sudah login). Response `{ date, time, all_present, total_not_absen, departments }`.
- **`api_vote.php`** — (POST) switch-case `login` / `logout` / `vote`. Vote memvalidasi `emp_code` ada di `active_employees()` lalu `toggle_love()`.
- **`api_history.php`** — riwayat absen per karyawan, paging 30 hari (`?offset=`), filter `?start_date=`/`?end_date=`. Role guard: employee hanya riwayat dirinya (`emp_code` session). Response `{ success, employee, history, has_more, next_offset, total_days }`. Dipakai `index.php`, `employee.php`, `weekly.php` untuk modal detail (infinite scroll).

## Fitur Lain

- **`employee.php`** — dashboard karyawan personal (role `employee`): data absen "hari ini" (in/out/status/telat), avatar & foto profil, riwayat collapsible (lazy-load) via `api_history.php`. Guard: non-employee → redirect `index.php`; tanpa `emp_code` → `logout.php`. Layout `.container { max-width: 720px; }`.
  - **Auto-refresh 60 detik**: polling `api_dashboard.php` tiap 60s untuk update Masuk/Keluar/Status badge tanpa full reload (elemen ber-id `todayIn`, `todayOut`, `todayStatus`, indikator `syncIndicator`).
  - **Avatar + camera badge overlay**: avatar di pojok kanan bawah punya badge kamera (`avatar-cam-badge`) yang merujuk ke `profile.php` (setara tap avatar → `ProfileSettingsActivity` di Android).
- **`public.php`** — halaman publik: daftar karyawan belum absen per departemen + vote ❤️ + **search filter real-time** (`#pubSearch`, debounce 120ms). Filter menyembunyikan baris tanpa match, menyembunyikan section departemen kosong, dan menampilkan jumlah hasil / pesan "Tidak ada karyawan yang cocok" (`#pubSearchCount`, `#noResultsMsg`).
- **`profile.php`** — selain upload foto & ganti password, punya: **avatar + camera badge override** (`camera-badge-wrap`, klik avatar = file picker) dan **section "Tentang & Lisensi"** (`about-section`) berisi nama app, versi 1.0.0, hak cipta © 2026 rnga4, lisensi MIT, dan daftar pustaka pihak ketiga (PHP, Nginx, SQLite, MySQL).
- **`export.php`** — export CSV harian (butuh login), BOM UTF-8 agar Excel aman, tanggal opsional `?d=YYYY-MM-DD`. Kolom: No, Nama, Departemen, Masuk, Keluar, Status, Telat (m). Dipanggil dari tombol "Export CSV" di `index.php`.
- **`notify.php`** — script CLI untuk notifikasi Telegram (dipanggil cron). Kirim daftar karyawan belum absen; skip & exit bila semua sudah absen. Pakai env `TELEGRAM_BOT_TOKEN`/`TELEGRAM_CHAT_ID`, timeout 3s. Cron di progress.md: `08:05` & `12:00` Senin–Jumat.

## Profil & Foto

- `profile.php`: upload foto (max 2MB, jpg/png/webp) + ganti password.
- **JSON API mode** (dipakai aplikasi Android): aktif bila `?format=json`, header `Accept: application/json`, atau `X-Requested-With: XMLHttpRequest`.
- Foto disimpan di `app/data/photos/{username}.{ext}`. `nginx.conf` **men-deny `/data/`** → foto hanya bisa didapat via `photo.php`.
- `photo.php` menerima `?emp=<emp_code>&pub=1` (mode publik) hanya melayani foto role `employee` — foto admin tetap 403.
- Setup: `setup_users.php` (schema + migrasi idempotent untuk `users` & `votes`) dan `seed_employee_users.php` (CLI seed).

## Helper di `app/config.php`

- `db()` (MySQL), `local_db()`/`local_user()` (SQLite), `active_employees()`, `e()` (escape HTML), `require_login()`.
- `verify_pbkdf2()` / `verify_user_password()`.
- `photo_dir()` / `photo_path($username)` / `photo_user_for_emp($empCode)` / `emp_photo_url($empCode, $public=false)`.
- Vote helpers: `votes_today_counts()`, `my_votes_today($username)`, `toggle_love($username, $empCode)`.
- Navigasi: `breadcrumb($items)`.
- Flash/toast: `flash_set($type, $text)`, `flash_out()`, dan **`toast_js()`**.

## Sistem Toast

- `app/assets/toast.js`: `AppToast.success/error/info/warning(title, desc?)`. Toast Sonner-style (kanan atas, auto-dismiss 4.5s).
- Termuat sinkron sebelum `<?= toast_js() ?>` di akhir `<body>`.

## Catatan Deploy & Perintah Kerja

- `.env` berisi credential — **tracked oleh `.gitignore`, jangan pernah commit**.
- Docker: `docker compose up -d`.
- Linting di FPM container:
  ```bash
  docker exec absensi-fpm php -l /var/www/html/public.php
  ```
- Migrasi DB:
  ```bash
  docker exec -u www-data absensi-fpm php /var/www/html/setup_users.php
  ```

## Akun Test

| Role | Username | Password | Note |
|---|---|---|---|
| Admin | `UwU` | `anjir445566` | via konstan env (fallback) & SQLite |
| Employee | `abdul` | `unico@123` | emp_code 38 |
| Employee | `nur` | `unico@123` | emp_code 8 |