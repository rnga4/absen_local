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