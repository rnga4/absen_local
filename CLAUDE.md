# CLAUDE.md — Absensi Monitor

Aplikasi web monitoring absensi ("Absensi Monitor"). PHP murni (tanpa framework/build step), dijalankan via Docker (nginx + php-fpm).

## Arsitektur & Stack

- Container: `absensi-nginx` (port **9790**) + `absensi-fpm` (php:8.3-fpm-alpine).
- Folder `app/` di-mount sebagai volume ke `/var/www/html` → **cukup edit file lalu reload browser, TIDAK perlu rebuild**.
- PHP alpine **tanpa GD/imagick/exif** → validasi gambar memakai `getimagesize()` (tanpa resize).
- Tema: token OKLCH di `app/assets/pl-komatsu-ui-template.css` (`--background`, `--card`, `--muted`, `--foreground`, `--border`, `--success`, `--destructive`, `--primary`, dsb). Ganti tema via class `body` (`dark`, `theme-sakura`, ...), persist di `localStorage('absensi-theme')`.

## Database

- **MySQL** (remote `192.168.1.141:3306`, db `fingerprint_absensi`): data absen asli.
  - `personnel_employee` (emp_code, first_name, department_id), di-`LEFT JOIN` ke `personnel_department` (dept_code, dept_name).
  - `iclock_transaction_backup` (emp_code, punch_time) → data kehadiran.
  - Karyawan aktif = `department_id <> 1`.
- **SQLite** `app/data/users.sqlite` (tabel `users`): akun login aplikasi.
  - Kolom: `id, username, password_hash, algo ('pbkdf2_sha256'|'bcrypt'), role ('admin'|'employee'), emp_code, name, photo, is_active, created_at`.
  - 1 admin (UwU) + 43 employee (di-seed dari `personnel_employee`; username = kata pertama `first_name` lowercase, duplikat disuffix `2`,`3`; password default `unico@123`).
  - `emp_code` menghubungkan akun login ↔ karyawan MySQL.

## Login & Access Control

- `login.php`: cek SQLite `users` dulu (via `verify_user_password`), fallback konstan env `APP_USER`/`APP_PASS`. Set `$_SESSION['logged_in']`, `role`, `username`, `emp_code`.
  - Login sukses: admin → `index.php`, employee → `employee.php`, plus `flash_set('success', ...)` (toast).
- Guard: `require_login()` di `config.php`. `index.php` & `weekly.php` redirect employee ke `employee.php`. `api_history.php`: employee hanya riwayat dirinya sendiri (dibanding `$_SESSION['emp_code']`).
- `photo.php`: wajib login; employee hanya bisa lihat fotonya sendiri, admin semua. **Mode publik**: `photo.php?pub=1` hanya melayani foto ber-role `employee` (dipakai `public.php`, dashboard tanpa login) — foto admin tetap 403.

## Profil & Foto

- `profile.php`: upload foto (max 2MB, jpg/png/webp, dimensi ≤4000px) + ganti password (verifikasi password lama, min 6 karakter, konfirmasi, `session_regenerate_id(true)` — **ingat: sesi cookie berubah setelah ganti password**).
- **JSON API mode** (dipakai aplikasi Android): aktif bila `?format=json`, header `Accept: application/json`, atau `X-Requested-With: XMLHttpRequest`.
  - `GET profile.php?format=json` → `{ success, name, username, emp_code, dept, role, has_photo, photo_url }`.
  - `POST profile.php?format=json` `action=password` → validasi sama seperti web, return `{ success, message }` (error → HTTP 400).
  - `POST profile.php?format=json` multipart `action=photo` + file `photo` → validasi & simpan, return `{ success, message, photo_url }`.
  - Mode HTML/browser tetap jalan tidak berubah.
- Foto disimpan di `app/data/photos/{username}.{ext}`. `nginx.conf` **men-deny `/data/`** → foto hanya bisa didapat via `photo.php`.
- `photo.php` juga menerima **`?emp=<emp_code>&pub=1`** (mode publik): mencari username lewat `photo_user_for_emp()`, hanya melayani akun role `employee` — dipakai dashboard publik & aplikasi Android by emp_code.
- Foto tampil di: `employee.php` (avatar), `index.php` (tabel + modal riwayat), `public.php`, `profile.php`, dan aplikasi Android (halaman profil, index publik, dashboard admin).
- Setup: `setup_users.php` (schema + migrasi idempotent, termasuk kolom `photo`) dan `seed_employee_users.php` (CLI seed) — keduanya di-`deny` nginx, jalan via `docker exec`.

## Helper di `app/config.php`

- `db()` (MySQL), `local_db()`/`local_user()` (SQLite), `active_employees()`, `e()` (escape HTML), `require_login()`.
- `verify_pbkdf2()` / `verify_user_password()` — algoritma hash di kolom `algo`.
- `photo_dir()` / `photo_path($username)` / `photo_user_for_emp($empCode)` / `emp_photo_url($empCode, $public=false)`.
- Flash/toast: `flash_set($type, $text)` (type: success/error/info/warning), `flash_out()`, dan **`toast_js()`** yang merender flash jadi toast di akhir `<body>`.

## Sistem Toast

- `app/assets/toast.js`: `AppToast.success/error/info/warning(title, desc?)`. Toast Sonner-style (kanan atas, rounded, animation, auto-dismiss 4.5s, tombol close).
- Termuat sinkron **sebelum** `<?= toast_js() ?>` di akhir `<body>`. Pola halaman:
  ```html
  <script src="assets/toast.js"></script>
  <?= toast_js() ?>
  ```
- Terpasang di: `login.php` (sukses/gagal & info logout), `logout.php` (set flash sebelum redirect), `profile.php` (foto & password), `index.php` & `employee.php` (welcome + toast ganti tema). **Jangan** pakai `flash-banner` lagi.

## Catatan Deploy

- `.env` berisi credential (MySQL, `APP_USER`/`APP_PASS`, Telegram bot) — **tracked oleh `.gitignore`, jangan pernah commit**. Gunakan `.env.example` sebagai templat.
- Docker: `docker compose up -d` (bukan via sudo). Cek status: `docker ps --format "table {{.Names}}\t{{.Status}}"`.
- Env `TELEGRAM_BOT_TOKEN` + `TELEGRAM_CHAT_ID` dipakai oleh `notify.php` (notifikasi Telegram). Sekarang prosesnya (implosif).

## Perintah Kerja Sehari-hari

```bash
# lint satu file di dalam container
docker exec absensi-fpm php -l /var/www/html/app/<file>.php   # catatan: root web = /var/www/html (isi app/)

# lint semua (bila di mount sebagai /var/www/html)
docker exec absensi-fpm sh -c 'for f in /var/www/html/*.php; do php -l "$f"; done'

# test login via API
curl -s -X POST http://localhost:9790/api_login.php -d "username=abdul&password=unico@123"

# seeding akun employee (sekali jalan)
docker exec absensi-fpm php /var/www/html/seed_employee_users.php
```

## Akun Test

| Role | Username | Password | Note |
|---|---|---|---|
| Admin | `UwU` | `anjir445566` | via konstan env (fallback) & SQLite |
| Employee | `abdul` | `unico@123` | emp_code 38 |
| Employee | `nur` | `unico@123` | emp_code 8 |