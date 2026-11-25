# Export Excel Rekap Bulanan — Design (short)

Tanggal: 2026-11-25

## Intent

Admin perlu membawa data rekap gaji bulanan ke Excel untuk diolah lebih lanjut. Angkanya harus sama persis dengan yang tampil di halaman rekap.

## Goals

- Tombol "Export Excel" di `rekap_bulanan.php` menghasilkan file CSV ber-BOM UTF-8 (pola yang sudah dipakai `export.php`) dari halaman dan query yang **sama persis** dengan tampilan rekap.
- File berisi detail per slip: `Perusahaan, Nama, Kode, Tanggal, Gaji Pokok, Pendapatan, Potongan, Dibayar`, lalu satu baris `GRAND TOTAL`.
- Angka ditulis sebagai angka mentah (titik desimal, tanpa pemisah ribuan dan tanpa awalan "Rp") agar bisa dijumlah di Excel.
- Akses admin-only, mengikuti guard halaman rekap (employee tetap redirect).

## Non-goals / Anti-goals

- Tidak membuat file `.xlsx` asli: tidak ada library/dependency di image PHP sekarang, dan CSV+BOM sudah jadi konvensi aplikasi ini (`export.php`).
- Tidak mengubah `export.php` (ekspor absensi harian) atau menambah tabel/migrasi.
- Tidak memformat angka jadi teks berbunga Rupiah — itu merusak kemampuan hitung di Excel.

## Constraints

- Satu jalur data: parameter `?bulan=` divalidasi dengan regex yang sama, filter SQL `deleted_at IS NULL`, agregasi yang sama. Tidak boleh ada query kedua yang bisa melenceng.
- Query ditambah kolom `date AS tanggal` hanya untuk detail; tidak mengubah agregat atau urutan.
- BOM UTF-8: `chr(0xEF).chr(0xBB).chr(0xBF)` sebelum `fputcsv`.
- Nama file: `rekap_gaji_YYYY-MM.csv`, header `Content-Type: text/csv; charset=UTF-8` dan `Content-Disposition: attachment`.
- Bulan tanpa data: file tetap dihasilkan dengan header saja (tanpa baris angka 0).

## Approaches

- **A (dipilih):** mode `?export=1` langsung di `rekap_bulanan.php` sebelum output HTML. Dijamin angka identik karena memakai variabel `$groups`/`$grand` yang sama; tanpa file baru atau dependency.
- **B (ditolak):** endpoint export terpisah yang mengulang query. Risiko drift filter/agregat, bertentangan dengan syarat "pastikan akurat".
- **C (ditolak):** `.xlsx` asli lewat PhpSpreadsheet perlu composer/vendor dan paket PHP tambahan; overkill untuk kebutuhan ini.

## Implementasi (call writer)

- Di `app/rekap_bulanan.php`, tangani `export=1` segera setelah data siap dan sebelum `<!DOCTYPE html>`: keluarkan BOM + header CSV + baris detail + baris grand total, lalu `exit`.
- Tambah `date AS tanggal` ke SELECT yang sudah ada; tampilkan `date('Y-m-d', strtotime(...))` di kolom Tanggal.
- Untuk tiap slip di `$groups`, tulis satu baris; gunakan `number_format((float)$v, 2, '.', '')` untuk 4 kolom angka.
- Tombol `<a class="btn-export" href="rekap_bulanan.php?bulan=<?= e($bulan) ?>&export=1">Export Excel</a>` di samping `<h1>`, memakai kelas yang sudah ada di `index.php`.
- Verifikasi: `curl` admin `?bulan=2026-11&export=1` → ada BOM, 48 baris detail + 1 grand total, dan jumlah kolom `Dibayar` = 273837000; employee → 302; `?bulan=2026-10&export=1` → hanya header.
