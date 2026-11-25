# Spesifikasi: Detail Card & Sisa Perbaikan Rekap Uang Makan

## Intent

Admin perlu bisa tracing angka ringkasan di 4 card rekap tanpa baca seluruh tabel. Card jadi bisa diklik dan membuka detail per departemen. Sekalian dua minor yang masih tertunda dari analisa: penjelasan cut-off 13.00 di legend, dan subtotal per departemen di CSV.

## Goals

- 4 card (Total Uang Makan, Total Potongan, Total Hari Hadir, Jumlah Karyawan) bisa diklik; klik membuka modal berisi tabel per departemen + total sesuai metrik card.
- Modal bisa ditutup via tombol ×, klik backdrop, dan Escape — mengikuti pola `.emp-modal-overlay` di `index.php`/`weekly.php`.
- Legend menyebut punch setelah 13.00 tidak dihitung hadir.
- CSV menyertakan baris Subtotal per departemen sebelum GRAND TOTAL.

## Non-goals / Anti-goals

- Query/API/endpoint baru. Semua angka sudah ada di `$groups`, `$subs`, `$grand`.
- Perubahan hitungan uang makan, format mata uang, atau urutan departemen.
- Detail per karyawan (opsi B yang ditolak) dan panel expand (opsi C yang ditolak).
- Perubahan halaman lain.

## Constraints

- Card memakai `<a class="card" data-detail="uang|potongan|hadir|count">` supaya memakai gaya `.cards a.card` yang sudah ada (hover, pointer, fokus keyboard) tanpa CSS baru.
- Satu modal overlay dipakai ulang untuk keempat card; isi panel ditoggle lewat atribut `hidden`, tanpa AJAX.
- Tabel detail di modal memakai `.tbl` dengan dua override scoped `.tbl.rekap-detail` (kolom pertama kiri, kolom terakhir kanan) karena aturan generik `.tbl` mengubah alignment.
- Subtotal CSV 6 field: `['Subtotal <dept>', '', days, late, potongan, uang]`. GRAND TOTAL tetap terakhir.
- Test `/tmp/opencode/verify-uang-makan.sh` harus diubah supaya tidak menjumlahkan baris Subtotal saat menghitung grand total.

## Approaches

- **A (dipilih user):** detail per departemen lewat modal. Data sudah di `$subs`, nol query baru; biayanya detail berhenti di level departemen.
- **B (ditolak):** detail per karyawan, lebih dalam tapi daftar 43 baris dan markup baru.
- **C (ditolak):** panel expand di bawah card, tabel terdorong ke bawah.

## Rincian

- Panel `uang`: `$subs[$dept]['uang']` + `$grand['uang']`.
- Panel `potongan`: `$subs[$dept]['potongan']` + `$grand['potongan']`.
- Panel `hadir`: `$subs[$dept]['days']` + `$grand['days']`.
- Panel `count`: `count($groups[$dept])` + `$grand['count']`.
- Judul modal mengikuti label card yang diklik.
- Legend ditambah item kelima: `Punch setelah 13.00 tidak dihitung hadir`.
