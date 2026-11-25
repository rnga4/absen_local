# Export Excel Rekap Bulanan Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tombol Export Excel di halaman rekap bulanan menghasilkan CSV+BOM berisi detail slip payroll dengan angka identik ke halaman.

**Architecture:** Mode `?export=1` ditangani di `app/rekap_bulanan.php` itu sendiri, segera setelah query/agregat yang sama dengan tampilan halaman dibuat, sebelum output HTML. Tidak ada endpoint lain, tidak ada query kedua, tidak ada dependency baru.

**Tech Stack:** PHP 8.3 (`php:8.3-fpm-alpine`), MySQL via `db()` (`app/config.php`), `fputcsv` + BOM UTF-8 seperti `app/export.php`.

**Spec:** `docs/superpowers/specs/2026-11-25-export-rekap-bulanan-design.md`

## Global Constraints

- Admin-only;employee kena `header('Location: employee.php'); exit;` seperti halaman rekap.
- Satu jalur data: `?bulan=` divalidasi `/^\d{4}-(0[1-9]|1[0-2])$/`, filter `deleted_at IS NULL`, agregasi `$groups`/`$grand` yang sama dengan halaman.
- Satu query saja; kolom `date AS tanggal` ditambahkan hanya untuk detail, tidak mengubah agregat.
- CSV+BOM UTF-8: `chr(0xEF).chr(0xBB).chr(0xBF)` sebelum `fputcsv`.
- Angka memakai `number_format((float)$v, 0, '.', '')` (tanpa desimal agar tidak salah dibaca Excel id-ID) — tanpa pemisah ribuan, tanpa "Rp ".
- Nama file `rekap_gaji_YYYY-MM.csv`; header `Content-Type: text/csv; charset=UTF-8` + `Content-Disposition: attachment`.
- Bulan tanpa data: hanya baris header, tanpa baris angka 0 / grand total.

## Review Focus

- Employee menekan export → harus 302 ke `employee.php`, bukan file gaji → Task 1 verifikasi guard.
- Slip terhapus ikut masuk → total CSV meleset; Nov 2026 harus 48 detail dan kolom `Dibayar` berjumlah 273837000 → Task 1 verifikasi jumlah & total.
- `?bulan=2026-99&export=1` → filename jadi `rekap_gaji_2026-11.csv`, tanpa `SQLSTATE` → Task 1 verifikasi invalid month.
- `?bulan=2026-10&export=1` → hanya satu baris header, bukan baris `Rp 0` → Task 1 verifikasi empty month.
- Angka kebawa sebagai teks Rupiah → Excel tidak bisa menjumlahkan → Task 1 verifikasi tidak ada `Rp ` di file dan semua kolom angka parse sebagai numerik.

---

### Task 1: Mode export CSV di halaman rekap

**Files:**
- Modify: `app/rekap_bulanan.php` (SELECT, blok export sebelum HTML, tombol di `<main>`)
- Modify: `docs/superpowers/specs/2026-11-25-export-rekap-bulanan-design.md` (spec ikut di-commit)

**Interfaces:**
- Consumes: `$bulan`, `$start`, `$end`, `$groups`, `$grand`, `rupiah()` yang sudah ada di `rekap_bulanan.php`.
- Produces: `GET rekap_bulanan.php?bulan=YYYY-MM&export=1` → file CSV; tautan `?export=1` dari tombol di halaman rekap.

- [ ] **Step 1: Tulis test gagal (RED)**

Sebelum mengubah kode, jalankan:

```bash
curl -s -D /tmp/opencode/rekap-export.headers -o /tmp/opencode/rekap-export.csv 'http://localhost:9790/rekap_bulanan.php?bulan=2026-11&export=1'
head -c 3 /tmp/opencode/rekap-export.csv | xxd -p
grep -i 'content-disposition' /tmp/opencode/rekap-export.headers
```

Expected: file berisi HTML (`<!DOCTYPE html>`), 3 byte pertama bukan `efbbbf`, dan tidak ada header `Content-Disposition: attachment`.

- [ ] **Step 2: Tambah kolom tanggal ke query**

Di SELECT `tax_app.salary_slips` yang sudah ada, tambahkan `date AS tanggal` setelah `code`. Urutan `ORDER BY` dan klausa `WHERE` tidak diubah.

- [ ] **Step 3: Helper format angka CSV**

Tambahkan fungsi `csv_num($v): string` di dekat `rupiah()` yang mengembalikan `number_format((float)$v, 0, '.', '')` (tanpa desimal agar tidak salah dibaca Excel id-ID).

- [ ] **Step 4: Blok export sebelum output HTML**

Segera setelah definisi `rupiah()` dan sebelum `<!DOCTYPE html>`:

```php
if (($_GET['export'] ?? '') === '1') { ... }
```

Isi blok: kirim tiga header di atas, buka `php://output`, tulis BOM, tulis baris header `['Perusahaan','Nama','Kode','Tanggal','Gaji Pokok','Pendapatan','Potongan','Dibayar']`. Jika `$grand['count'] > 0`: untuk tiap `$groups[$company]['rows']` tulis satu baris (tanggal via `date('Y-m-d', strtotime($r['tanggal']))`, empat angka via `csv_num()`), lalu satu baris `['GRAND TOTAL','','','', csv_num(...empat agregat...)]`. Tutup stream lalu `exit`. Jika kosong, langsung tutup & `exit` setelah header.

- [ ] **Step 5: Tombol Export Excel**

Di `<main class="container">`, ganti `<h1>Rekap Gaji Bulanan</h1>` dengan wrapper flex seperti `index.php:266`: h1 + `<a class="btn-export" href="rekap_bulanan.php?bulan=<?= e($bulan) ?>&export=1">Export Excel</a>`.

- [ ] **Step 6: Lint**

```bash
docker exec absensi-fpm php -l /var/www/html/rekap_bulanan.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 7: Verifikasi konten CSV (GREEN)**

```bash
curl -s -D /tmp/opencode/rekap-export.headers -o /tmp/opencode/rekap-export.csv -b /tmp/opencode/rekap-admin.cookie 'http://localhost:9790/rekap_bulanan.php?bulan=2026-11&export=1'
head -c 3 /tmp/opencode/rekap-export.csv | xxd -p
grep -i 'content-disposition' /tmp/opencode/rekap-export.headers
head -1 /tmp/opencode/rekap-export.csv
php -r '$h=fopen("/tmp/opencode/rekap-export.csv","r");fgetcsv($h);$n=0;$sum=0;while(($r=fgetcsv($h))!==false){if($r[0]==="GRAND TOTAL"){echo "grand=".$r[7]."\n";continue;}$n++;$sum+=(float)$r[7];}echo "rows=$n sum=$sum\n";'
```

Expected: `efbbbf`; header `Content-Disposition: attachment; filename="rekap_gaji_2026-11.csv"`; baris pertama persis `Perusahaan,Nama,Kode,Tanggal,Gaji Pokok,Pendapatan,Potongan,Dibayar`; `rows=48 sum=273837000`; `grand=273837000.00`; tidak ada `Rp ` di file.

- [ ] **Step 8: Verifikasi guard, invalid month, empty month**

```bash
curl -s -b /tmp/opencode/rekap-emp.cookie -o /dev/null -w '%{http_code} %{redirect_url}\n' 'http://localhost:9790/rekap_bulanan.php?bulan=2026-11&export=1'
curl -s -D /tmp/opencode/rekap-invalid.headers -o /dev/null -b /tmp/opencode/rekap-admin.cookie 'http://localhost:9790/rekap_bulanan.php?bulan=2026-99&export=1'
grep -i 'content-disposition' /tmp/opencode/rekap-invalid.headers
curl -s -b /tmp/opencode/rekap-admin.cookie 'http://localhost:9790/rekap_bulanan.php?bulan=2026-10&export=1' | wc -l
curl -s -b /tmp/opencode/rekap-admin.cookie 'http://localhost:9790/rekap_bulanan.php?bulan=2026-10&export=1' | grep -o 'SQLSTATE' || true
```

Expected: `302 employee.php`; filename `rekap_gaji_2026-11.csv`; empty month tepat 1 baris; tanpa `SQLSTATE`.

- [ ] **Step 9: Commit**

```bash
git add app/rekap_bulanan.php docs/superpowers/specs/2026-11-25-export-rekap-bulanan-design.md
git commit -m "feat: export monthly salary recap as Excel-safe CSV"
```
