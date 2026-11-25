# Rekap Bulanan Gaji Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Halaman admin-only `rekap_bulanan.php` yang menampilkan total gaji dibayar per bulan dari `tax_app.salary_slips`, dikelompokkan per perusahaan.

**Architecture:** Satu halaman PHP server-rendered yang membaca slip payroll yang sudah ada (read-only, lintas database di server MySQL yang sama), memvalidasi parameter bulan dari query string, lalu meng-agregasi baris di PHP menjadi grup `company_name` + subtotal + grand total. Tidak ada tabel/migrasi/API baru; UI memakai kelas CSS dan pola drawer/tema yang sudah ada di `weekly.php`.

**Tech Stack:** PHP 8.3 (php-fpm alpine), MySQL (`db()` PDO di `app/config.php`), CSS existing (`pl-komatsu-ui-template.css`, `style.css`), JavaScript vanilla.

**Spec:** `docs/superpowers/specs/2026-11-25-rekap-bulanan-gaji-design.md`

## Global Constraints

- Admin-only: employee di-redirect ke `employee.php`, mengikuti pola `weekly.php`.
- Sumber data wajib: `tax_app.salary_slips` dengan `deleted_at IS NULL` dan tanggal di bulan terpilih.
- Tidak ada join ke penalty absensi `weekly.php`, tidak ada tabel/migrasi/dependency baru, semua query read-only.
- `company_name` NULL/kosong → grup `"Lainnya"`.
- Bulan tanpa data → teks "Belum ada data gaji untuk bulan ini", bukan Rp 0.
- Format angka: `number_format($n, 0, ',', '.')`, awalan `Rp `.

## Review Focus

- Employee membuka halaman → harus redirect ke `employee.php`, bukan melihat angka gaji → Task 1 langkah verifikasi (curl akun `abdul`).
- Slip terhapus ikut terhitung → total gaji jadi terlalu besar → Task 1 langkah verifikasi: Nov 2026 harus 48 slip dan `273.837.000`.
- `?bulan=2026-99` / bulan invalid → jangan error SQL atau menampilkan tanggal 1900 → Task 1 langkah verifikasi (fallback ke bulan berjalan).
- `?bulan=2026-10` (tidak ada data) → empty state, bukan tabel Rp 0 → Task 1 langkah verifikasi.
- `company_name` NULL → baris grup tanpa nama / header kosong → Task 1 langkah verifikasi: Agustus 2026 punya 48 slip tanpa perusahaan, halaman harus menampilkan grup `Lainnya`.

---

### Task 1: Halaman rekap bulanan

**Files:**
- Create: `app/rekap_bulanan.php`

**Interfaces:**
- Consumes: `require_login()` dan `db()` dari `app/config.php`; `$_SESSION['role']`; kelas CSS `.container`, `.cards`, `.card`, `.tbl`, `.week-form`, `.weekly-summary`, `.range-info`.
- Produces: `GET rekap_bulanan.php?bulan=YYYY-MM` — halaman HTML untuk admin; tidak ada fungsi/endpoint yang dipakai file lain.

- [ ] **Step 1: Kerangka PHP + validasi bulan + query data**

Buat `app/rekap_bulanan.php`:

```php
require __DIR__ . '/config.php';
require_login();
if (($_SESSION['role'] ?? '') === 'employee') { header('Location: employee.php'); exit; }

$bulan = $_GET['bulan'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bulan)) $bulan = date('Y-m');
$start = $bulan . '-01';
$end   = date('Y-m-t', strtotime($start));
```

Ambil semua slip bulan itu dalam **satu** query (tanpa N+1):

```sql
SELECT COALESCE(NULLIF(TRIM(company_name), ''), 'Lainnya') AS company,
       employee_name, code, basic_salary, total_income, total_deduction, take_home_pay
FROM tax_app.salary_slips
WHERE deleted_at IS NULL AND date BETWEEN :s AND :e
ORDER BY company, employee_name
```

Bind `:s` = `$start`, `:e` = `$end`. Kelompokkan hasil ke `$groups[$company]` berisi `rows` dan lima angka agregat (`count`, `basic`, `income`, `deduction`, `pay`); hitung juga `$grand` dengan kunci sama. Semua angka dibaca sebagai `float` saat dijumlahkan.

- [ ] **Step 2: Markup halaman**

Ikuti struktur `weekly.php` (head + favicon + `theme-color`, topbar dengan theme switcher & hamburger, drawer overlay/panel, popup tema, `<main class="container">`) tanpa modal riwayat, tanpa back-to-top. Breadcrumb: `[['label'=>'Dashboard','href'=>'index.php'],['label'=>'Rekap Gaji Bulanan']]`.

Isi `<main>`:
- `<h1>Rekap Gaji Bulanan</h1>`
- `<form method="get" class="week-form">` berisi `<input type="month" name="bulan" value="<?= e($bulan) ?>">`, tombol `Bulan Lalu` (`moveMonth(-1)`), `Bulan Berikutnya` (`moveMonth(1)`), dan submit `Tampilkan`.
- `<p class="range-info">` periode `1 <nama bulan> <tahun>` s/d `<tanggal akhir>`.
- Empat `<div class="card">` memakai pola `.num`/`.lbl` dari `index.php`: Total Dibayar, Gaji Pokok, Total Potongan, Jumlah Karyawan.
- Jika `$grand['count'] === 0`: tampilkan `<p class="range-info">Belum ada data gaji untuk bulan ini.</p>` dan **jangan** render tabel.
- Jika ada data: `<div class="tbl-wrap"><table class="tbl">` dengan header `Nama | Kode | Gaji Pokok | Pendapatan | Potongan | Dibayar`. Untuk tiap grup: baris `<tr class="dept-row">` berisi nama perusahaan (colspan 6), baris karyawan (4 kolom angka memakai `td.num`), baris subtotal memakai `td.total`. CSS untuk `.dept-row`/`.dept-total` hanya aktif di `.tbl.weekly`, jadi beri inline style (`background:var(--muted);font-weight:700;text-align:left`) pada kedua jenis baris itu di tabel ini. Tambahkan baris grand total terakhir dengan kelima angka. Sembunyikan angka `0` bukanlah keharusan — tampilkan apa adanya.
- Sumber data dicantumkan kecil di bawah tabel: `Sumber: tax_app.salary_slips`.

JS inline minimal (tanpa library): `moveMonth(n)` memakai `new Date(value + '-01T12:00:00')`, `setMonth`, lalu format `YYYY-MM` lokal (hindari `toISOString()` karena pergeseran UTC); blok theme switcher dan drawer close dari `weekly.php` (copy pola `localStorage('absensi-theme')`, `drawer-toggle`, `drawerOverlay`, `drawerCloseBtn`).

- [ ] **Step 3: Lint**

Run: `docker exec absensi-fpm php -l /var/www/html/rekap_bulanan.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Verifikasi end-to-end via HTTP**

Login admin dan cek angka Nov 2026:

```bash
curl -s -c /tmp/opencode/rekap-admin.cookie -d 'username=UwU&password=anjir445566' -o /dev/null http://localhost:9790/login.php
curl -s -b /tmp/opencode/rekap-admin.cookie 'http://localhost:9790/rekap_bulanan.php?bulan=2026-11' | grep -o '273\.837\.000\|48\|PT\. UNICO TRACTORS INDONESIA' | sort -u
```

Expected: ketiga nilai muncul.

Bulan kosong & input invalid:

```bash
curl -s -b /tmp/opencode/rekap-admin.cookie 'http://localhost:9790/rekap_bulanan.php?bulan=2026-10' | grep -o 'Belum ada data gaji untuk bulan ini'
curl -s -b /tmp/opencode/rekap-admin.cookie 'http://localhost:9790/rekap_bulanan.php?bulan=2026-99' | grep -o 'value="2026-11"'
curl -s -b /tmp/opencode/rekap-admin.cookie 'http://localhost:9790/rekap_bulanan.php?bulan=2026-08' | grep -o 'Lainnya'
```

Expected: empty state muncul; input invalid jatuh ke `2026-11` dan tidak ada teks `SQLSTATE`; Agustus 2026 menampilkan grup `Lainnya`.

Guard employee:

```bash
curl -s -c /tmp/opencode/rekap-emp.cookie -d 'username=abdul&password=unico@123' -o /dev/null http://localhost:9790/login.php
curl -s -b /tmp/opencode/rekap-emp.cookie -o /dev/null -w '%{http_code} %{redirect_url}\n' http://localhost:9790/rekap_bulanan.php
```

Expected: `302 employee.php`.

- [ ] **Step 5: Commit**

```bash
git add app/rekap_bulanan.php
git commit -m "feat: add admin monthly salary recap page"
```

### Task 2: Tautan drawer "Rekap Bulanan"

**Files:**
- Modify: `app/index.php` (setelah tautan `weekly.php`, ~baris 223–226)
- Modify: `app/weekly.php` (setelah tautan `weekly.php`, ~baris 209–212)
- Modify: `app/visitors.php` (setelah tautan `weekly.php`, ~baris 112–115)
- Modify: `app/profile.php` (di dalam cabang `else` admin, setelah tautan `weekly.php`, ~baris 434–437)

**Interfaces:**
- Consumes: halaman dari Task 1 (`rekap_bulanan.php`).
- Produces: navigasi ke halaman tersebut; tidak ada kontrak kode baru.

- [ ] **Step 1: Salin blok `<a>` tautan `weekly.php` di keempat file, ganti `href` menjadi `rekap_bulanan.php`, label `Rekap Bulanan`, dan ikon kalender (memakai SVG yang sama dengan yang sudah dipakai untuk `Mingguan`). Jangan menambah tautan di cabang employee `profile.php`.**

- [ ] **Step 2: Di `app/rekap_bulanan.php`, beri `class="active"` pada tautan `Rekap Bulanan`, dan pastikan tautan `Mingguan` tidak aktif di halaman itu.**

- [ ] **Step 3: Verifikasi lint + tautan**

```bash
for f in index weekly visitors profile rekap_bulanan; do docker exec absensi-fpm php -l /var/www/html/$f.php; done
curl -s -b /tmp/opencode/rekap-admin.cookie http://localhost:9790/index.php | grep -c 'href="rekap_bulanan.php"'
curl -s -b /tmp/opencode/rekap-admin.cookie http://localhost:9790/rekap_bulanan.php | grep -o 'class="active" href="rekap_bulanan.php"'
```

Expected: lima kali `No syntax errors detected`; `1` tautan di `index.php`; satu kecocokan `class="active"` di halaman rekap.

- [ ] **Step 4: Commit**

```bash
git add app/index.php app/weekly.php app/visitors.php app/profile.php app/rekap_bulanan.php
git commit -m "feat: link monthly salary recap from admin drawer"
```
