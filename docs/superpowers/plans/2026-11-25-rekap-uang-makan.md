# Rekap Uang Makan Bulanan Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ganti rekap bulanan dari data payroll `tax_app` menjadi rekap uang makan yang dihitung dari absen karyawan, dengan aturan Rp 90.000/hari, Senin–Sabtu, potong Rp 10.000 per jam telat mulai menit ke-1.

**Architecture:** Satu halaman server-rendered `app/rekap_bulanan.php` membaca punch bulanan dari `fingerprint_absensi` lewat `db()` yang sudah ada, mengagregasi per employee-day di PHP, lalu merender kartu, tabel per departemen dengan label vertikal, dan CSV dari satu set data yang sama. Dua fungsi murni di `app/uang_makan.php` menyimpan aturan uang agar bisa diuji tanpa database. Tidak ada tabel/dependency baru; tidak ada tulisan ke server MySQL.

**Tech Stack:** PHP 8.3 (php-fpm alpine), MySQL `fingerprint_absensi` via `db()`, SQLite lokal tetap untuk akun/vote, CSS existing, headless Chrome untuk assertion DOM.

**Spec:** `docs/superpowers/specs/2026-11-25-rekap-uang-makan-design.md`

## Global Constraints

- Tarif: **90000** per hari; potongan **10000** per jam telat yang dimulai; telat 1 menit = 10000.
- Jam masuk **08:00**, jam pulang **17:00**, tetapi pulang cepat **tidak dipotong** untuk sementara.
- Hari kerja: **Senin–Sabtu**; Minggu tidak dihitung. Nggak absen = Rp 0. Tidak ada daftar libur nasional.
- Karyawan: `active_employees()` (`department_id <> 1`), 43 orang saat ini.
- Dilarang membaca `tax_app` atau schema payroll lain. Semua query ke `fingerprint_absensi` bersifat **read-only**.
- Tidak ada tabel/migrasi baru, tidak ada dependency baru, tidak ada akses employee.
- CSV: BOM UTF-8 `ef bb bf`, angka polos tanpa `Rp ` dan tanpa desimal, field teks melalui `csv_text()`.
- Halaman khusus admin; employee dan session tanpa role admin di-redirect ke `employee.php`.

## Review Focus

- Punch ganda dalam satu hari (emp 13 punya 5 baris tanggal 2026-11-02) → tetap 1 hari, diuji di Task 2.
- Tanggal Minggu yang punya punch → tetap 0 hari, diuji di Task 1 lewat `is_hari_kerja('2026-11-01') === false`.
- Karyawan tanpa absen sama sekali (emp 48, Justin Liong, Nov 2026) → tetap tampil dengan Rp 0, diuji di Task 2.
- Bulan tanpa data absen (2026-12) → empty state, bukan kartu Rp 0, diuji di Task 2.
- CSV salah dibaca Excel Indonesia → angka bulat + BOM + escape `=+-@`, diuji di Task 2.
- Session non-admin atau query ke `tax_app` → redirect / tidak ada, diuji di Task 2.

---

### Task 1: Aturan uang sebagai fungsi murni

**Files:**
- Create: `app/uang_makan.php`
- Test: `/tmp/opencode/verify-uang-makan.sh` (artefak lokal, tidak di-commit)

**Interfaces:**
- Produces: `is_hari_kerja(string $tanggal): bool` — false untuk Minggu.
- Produces: `potongan_telat(string $jamMasuk): int` — 0 bila tidak telat, `ceil(menit/60) * 10000` bila telat.

- [ ] **Step 1: Buat skrip test yang gagal**

Tulis `/tmp/opencode/verify-uang-makan.sh` dengan bagian unit:

```php
require '/var/www/html/uang_makan.php';
foreach ([['08:00:00',0], ['07:59:00',0], ['08:01:00',10000],
          ['09:00:00',10000], ['09:01:00',20000], ['10:01:00',30000]] as [$in,$want]) {
    $got = potongan_telat($in);
    if ($got !== $want) { fwrite(STDERR, "potongan_telat($in)=$got, mau $want\n"); exit(1); }
}
foreach ([['2026-11-01',false], ['2026-11-02',true], ['2026-11-07',true]] as [$d,$want]) {
    $got = is_hari_kerja($d);
    if ($got !== $want) { fwrite(STDERR, "is_hari_kerja($d) salah\n"); exit(1); }
}
echo "PASS unit\n";
```

Jalankan lewat `docker exec absensi-fpm php -r '...'` di dalam skrip tersebut.

- [ ] **Step 2: Pastikan test gagal**

Run: `bash /tmp/opencode/verify-uang-makan.sh`
Expected: FAIL karena `app/uang_makan.php` belum ada.

- [ ] **Step 3: Implementasi dua fungsi**

Buat `app/uang_makan.php`:
- `is_hari_kerja(string $tanggal): bool` → `date('N', strtotime($tanggal)) !== 7`.
- `potongan_telat(string $jamMasuk): int` → selisih menit dari `08:00:00` memakai `strtotime('1970-01-01 ' . $jam)`; kembalikan 0 bila ≤ 0, sebaliknya `(int)ceil($menit / 60) * 10000`.

- [ ] **Step 4: Pastikan test lulus**

Run: `bash /tmp/opencode/verify-uang-makan.sh`
Expected: `PASS unit`.

- [ ] **Step 5: Commit**

```bash
git add app/uang_makan.php
git commit -m "feat: add meal allowance rate and late deduction helpers"
```

### Task 2: Rekap membaca absen dan menghitung uang makan

**Files:**
- Modify: `app/rekap_bulanan.php:1-108` (guard, query, agregat, helper lama, export)
- Modify: `app/rekap_bulanan.php:186-255` (kartu, tabel, empty state, sumber)
- Test: `/tmp/opencode/verify-uang-makan.sh` (tambah bagian integrasi)

**Interfaces:**
- Consumes: `active_employees(): array` dari `config.php` (emp_code, first_name, department_id, dept_name).
- Consumes: `is_hari_kerja()`, `potongan_telat()` dari Task 1.
- Consumes: `db()`, `require_login()`, `e()`, `breadcrumb()`, `rupiah()`, `csv_num()`, `csv_text()` yang sudah ada.
- Produces: `$groups` berisi baris per karyawan per departemen + subtotal; `$grand` berisi `count`, `days`, `late`, `potongan`, `uang`.

- [ ] **Step 1: Tambah assertion integrasi ke skrip test**

Assertion yang harus ditambah (selain bagian unit Task 1):

```bash
admin=$(curl -s -b /tmp/opencode/rekap-admin.cookie -o /dev/null -w '%{http_code}' \
  'http://localhost:9790/rekap_bulanan.php?bulan=2026-11')
[ "$admin" = 200 ] || fail 'admin bukan 200'
emp=$(curl -s -b /tmp/opencode/rekap-emp.cookie -o /dev/null -w '%{http_code} %{redirect_url}' \
  'http://localhost:9790/rekap_bulanan.php')
case "$emp" in 302*employee.php*) ;; *) fail "employee guard: $emp";; esac

page=$(curl -s -b /tmp/opencode/rekap-admin.cookie \
  'http://localhost:9790/rekap_bulanan.php?bulan=2026-11')
printf '%s' "$page" | grep -qF 'Rekap Uang Makan Bulanan' || fail 'judul belum diganti'
printf '%s' "$page" | grep -qF 'Rp 76.430.000' || fail 'total Nov salah'
printf '%s' "$page" | grep -qF 'Rp 790.000' || fail 'potongan Nov salah'
printf '%s' "$page" | grep -qF '>858<' || fail 'hari hadir Nov salah'
! printf '%s' "$page" | grep -qi 'tax_app' || fail 'masih baca tax_app'
printf '%s' "$page" | grep -qF 'Justin Liong' || fail 'karyawan tanpa absen hilang'

oct=$(curl -s -b /tmp/opencode/rekap-admin.cookie \
  'http://localhost:9790/rekap_bulanan.php?bulan=2026-10')
printf '%s' "$oct" | grep -qF 'Rp 98.460.000' || fail 'total Okt salah'

dec=$(curl -s -b /tmp/opencode/rekap-admin.cookie \
  'http://localhost:9790/rekap_bulanan.php?bulan=2026-12')
printf '%s' "$dec" | grep -qF 'Belum ada data absen untuk bulan ini.' || fail 'empty state hilang'

curl -s -b /tmp/opencode/rekap-admin.cookie \
  'http://localhost:9790/rekap_bulanan.php?bulan=2026-11&export=1' -o /tmp/opencode/uang-makan.csv
[ "$(head -c 3 /tmp/opencode/uang-makan.csv | od -An -tx1 | tr -d ' \n')" = efbbbf ] || fail 'BOM hilang'
head -1 /tmp/opencode/uang-makan.csv | grep -qF 'Uang Makan' || fail 'header CSV salah'
! grep -q '\.00\|Rp ' /tmp/opencode/uang-makan.csv || fail 'format angka CSV salah'
sum=$(docker exec -i absensi-fpm php -r '$h=fopen("php://stdin","r");fgetcsv($h);$s=0;$n=0;while(($r=fgetcsv($h))!==false){if($r[0]==="GRAND TOTAL"){echo $r[6]."\n";continue;}$n++;$s+=(float)$r[6];}fwrite(STDERR,"rows=$n sum=$s\n");' < /tmp/opencode/uang-makan.csv)
[ "$sum" = 76430000 ] || fail "grand CSV=$sum"

php_rows=$(docker exec absensi-fpm php -r '
require "/var/www/html/config.php";
$s=db()->prepare("SELECT COUNT(DISTINCT DATE(punch_time)) n FROM iclock_transaction_backup WHERE emp_code=13 AND punch_time BETWEEN :s AND :e");
$s->execute([":s"=>"2026-11-01 00:00:00",":e"=>"2026-11-30 23:59:59"]);
echo $s->fetchColumn();')
printf '%s' "$page" | grep -qF ">$php_rows<" || fail 'punch ganda dihitung lebih dari 1 hari'

docker exec absensi-fpm php -l /var/www/html/rekap_bulanan.php | grep -q 'No syntax errors' || fail 'lint'
```

- [ ] **Step 2: Pastikan test integrasi gagal**

Run: `bash /tmp/opencode/verify-uang-makan.sh`
Expected: FAIL di `judul belum diganti` (bagian unit sudah PASS).

- [ ] **Step 3: Ganti sumber data dan hitungan**

Di `app/rekap_bulanan.php`:
- `require __DIR__ . '/uang_makan.php';` setelah `config.php`; pertahankan guard admin dan validasi `$bulan`/`$start`/`$end`.
- Hapus query `tax_app.salary_slips` dan `$grand` lama; pertahankan `rupiah()` dan `csv_text()`, dan ubah `csv_num()` jadi format bulat di langkah ini.
- Ambil `active_employees()`, inisialisasi `$recaps[$emp_code]` dengan `hadir=0, late=0, potongan=0`; satu query `SELECT emp_code, punch_time FROM iclock_transaction_backup WHERE punch_time BETWEEN :s AND :e ORDER BY emp_code, punch_time`.
- Untuk tiap baris punch: `$d = substr($punch_time, 0, 10)`; lewati bila `isset($seen[$code][$d])` atau `!is_hari_kerja($d)`. Tandai `$seen[$code][$d] = true`. Jam masuk = `substr($punch_time, 11, 8)` dari punch pertama hari itu; hitung `hadir++`, `potongan += potongan_telat($jam)`, dan `late++` bila potongan > 0.
- Bentuk `$uang = $hadir * 90000 - $potongan`; kelompokkan baris ke `$groups[$dept_name]` memakai urutan `active_employees()`. `$grand` menjumlahkan `count` (karyawan), `days`, `late`, `potongan`, `uang`.
- `$hasData` = ada minimal satu employee-day; bila false tampilkan empty state "Belum ada data absen untuk bulan ini." dan jangan render kartu/tabel.
- Export CSV: header `Departemen, Nama, Kode, Hadir, Telat, Potongan, Uang Makan`; satu baris per karyawan aktif; baris `GRAND TOTAL` dengan `$grand['uang']`; angka lewat `csv_num()` bulat; teks lewat `csv_text()`.
- `csv_num($v)` → `number_format((float)$v, 0, '.', '')`.

- [ ] **Step 4: Ganti tampilan ke uang makan**

Di bagian HTML: title, breadcrumb, `<h1>`, brand row, empty state, card labels (`Total Uang Makan`, `Total Potongan`, `Total Hari Hadir`, `Jumlah Karyawan`), header tabel (`Nama`, `Kode`, `Hadir`, `Telat`, `Potongan`, `Uang Makan`), subtitle sumber `Sumber: iclock_transaction_backup & personnel_employee`. Pertahankan class `rekap-cards`, `company-col`, `company-label`, `company-start`, `dept-total`, `tbl rekap`, dan tombol `moveMonth()` apa adanya.

- [ ] **Step 5: Pastikan test lulus**

Run: `bash /tmp/opencode/verify-uang-makan.sh`
Expected: `PASS unit` + semua assertion integrasi lulus; `php -l` bersih.

- [ ] **Step 6: Commit**

```bash
git add app/rekap_bulanan.php
git commit -m "feat: compute monthly meal allowance from attendance"
```

### Task 3: Label navigasi dan verifikasi akhir

**Files:**
- Modify: `app/index.php:229`, `app/weekly.php:215`, `app/visitors.php:118`, `app/profile.php:440`
- Modify: `app/rekap_bulanan.php:147` (label drawer)

**Interfaces:**
- Consumes: Task 2 selesai; halaman sudah menampilkan "Rekap Uang Makan Bulanan".

- [ ] **Step 1: Tambah assertion label**

Tambahkan ke skrip test:

```bash
for f in index.php weekly.php visitors.php profile.php rekap_bulanan.php; do
  curl -s -b /tmp/opencode/rekap-admin.cookie "http://localhost:9790/$f" \
    | grep -qF 'Rekap Uang Makan' || fail "label $f belum diganti"
done
! grep -rq 'Rekap Gaji Bulanan' app/ || fail 'masih ada label lama'
```

- [ ] **Step 2: Pastikan test gagal**

Run: `bash /tmp/opencode/verify-uang-makan.sh`
Expected: FAIL di `label index.php belum diganti`.

- [ ] **Step 3: Ganti lima label**

Ganti teks `<span>Rekap Bulanan</span>` di empat file dan `<span>Rekap Bulanan</span>` di drawer `rekap_bulanan.php` menjadi `<span>Rekap Uang Makan</span>`. Jangan mengubah `href`.

- [ ] **Step 4: Verifikasi akhir**

Run: `bash /tmp/opencode/verify-uang-makan.sh && git status --short --branch`
Expected: semua PASS; hanya `notify.log` yang untracked; `HEAD` tidak otomatis berubah.

- [ ] **Step 5: Commit dan push sesuai kebiasaan user**

```bash
git add app/index.php app/weekly.php app/visitors.php app/profile.php app/rekap_bulanan.php
git commit -m "chore: rename monthly recap navigation to meal allowance"
git push origin master
```

Plan complete and saved to `docs/superpowers/plans/2026-11-25-rekap-uang-makan.md`.
