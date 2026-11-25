# Rekap Uang Makan Bulanan — Design (short)

Tanggal: 2026-11-25

## Intent

Admin perlu rekap **uang makan** bulanan yang dihitung murni dari absen karyawan, lepas dari data payroll. Desain ini menggantikan `2026-11-25-rekap-bulanan-gaji-design.md`: sumber `tax_app.salary_slips` tidak dipakai lagi (Oktober 2026 kosong di payroll, dan angkanya bukan uang makan).

## Goals

- Halaman yang sama (`app/rekap_bulanan.php`) tetap **khusus admin**; employee/non-admin di-redirect ke `employee.php`.
- Input bulan `type="month"` + tombol bulan lalu/berikut; default bulan berjalan.
- Hitung per karyawan aktif: jumlah hari hadir, jumlah hari telat, total potongan, total uang makan.
- Kelompokkan per **departemen** dengan label vertikal di kiri (pakai class CSS yang sudah ada), subtotal per departemen, dan GRAND TOTAL.
- Card ringkasan: total uang makan, total potongan, total hari hadir, jumlah karyawan.
- Export CSV (`?bulan=YYYY-MM&export=1`, BOM UTF-8) memakai data dan hitungan yang sama dengan layar.
- Aturan sederhana dari user: **nggak absen = nggak dapet uang makan**.

## Non-goals / Anti-goals

- Tidak membaca `tax_app` atau schema payroll lain lagi.
- Tidak ada daftar/libur nasional: hari tanpa punch = Rp 0; tidak ada pengecualian tanggal merah.
- Tidak memotong karyawan yang pulang sebelum 17:00 (untuk sementara).
- Tidak ada tabel/migrasi baru, tidak ada dependency baru, tidak ada akses employee, tidak ada edit data.
- Tidak mengubah aplikasi payroll atau menulis ke server MySQL luar; semua query **read-only**.

## Constraints

- Sumber: `fingerprint_absensi` di `192.168.1.141` lewat `db()` yang sudah dipakai seluruh aplikasi. Tabel: `personnel_employee`, `personnel_department`, `iclock_transaction_backup`.
- Daftar karyawan: `active_employees()` (`department_id <> 1`), 43 orang saat ini.
- `punch_time` bertipe datetime; satu karyawan bisa punya beberapa punch berdekatan → hitung per **employee-day**, bukan per baris punch.
- Jam masuk 08:00; jam pulang 17:00. Punch pertama sebelum 13:00 dianggap masuk.
- Senin–Sabtu dihitung; Minggu dilewati. Hari tanpa punch masuk = tidak dibayar.

## Perhitungan

Untuk tiap employee-day dengan punch masuk pada Senin–Sabtu:

- `hadir += 1`
- `telat_menit = max(0, jam_masuk - 08:00)`
- `potongan += ceil(telat_menit / 60) * 10000`
- `uang_makan = hadir * 90000 - potongan`

Contoh: telat 1 menit → potong Rp 10.000; telat 61 menit → potong Rp 20.000. Karyawan 21 hari hadir dengan 3 hari telat 1 menit → `21 × 90.000 − 3 × 10.000` = **Rp 1.860.000**.

## Approaches

- **A (dipilih):** hitung langsung dari absensi di `fingerprint_absensi` setiap halaman dibuka. Tidak perlu sinkronisasi, tidak ada data ganda, dan tetap memakai koneksi yang sudah ada. Risiko: angka berubah bila punch lama diedit — diterima.
- **B (ditolak):** salinan rekap bulanan di SQLite lokal. Butuh proses sync dari server absensi juga, jadi tidak benar-benar lepas dari server itu.
- **C (ditolak):** tetap pakai `tax_app.salary_slips` — Oktober 2026 kosong dan angkanya gaji pokok, bukan uang makan.

## Implementasi (call writer)

- Ganti query di `rekap_bulanan.php` dengan satu query punch bulan terpilih, lalu agregat di PHP mengikuti pola `active_employees()` + logika `in` di `api_dashboard.php`.
- Pertahankan struktur halaman, guard, breadcrumb, kartu 2×2, tabel label vertikal, separator antar grup, dan CSS yang sudah ada; ganti teks "Gaji" menjadi "Uang Makan", grup dari company menjadi departemen.
- Tampilkan semua karyawan aktif (termasuk yang 0 hari) bila bulan itu ada data absen; bila tidak ada absen sama sekali, tampilkan empty state "Belum ada data absen untuk bulan ini."
- CSV: `Departemen, Nama, Kode, Hadir, Telat, Potongan, Uang Makan` + baris `GRAND TOTAL`, angka polos tanpa "Rp ", tetap pakai `csv_text()` untuk field teks.
- Update label "Rekap Gaji Bulanan" di drawer `index.php`, `weekly.php`, `visitors.php`, `profile.php` dan judul halaman.
- Verifikasi: `php -l`; admin 200 / employee 302; tidak ada query ke `tax_app`; cek tanggal Minggu tidak masuk hitungan; cek contoh telat 1 menit dan 61 menit; cek jumlah CSV sama dengan GRAND TOTAL.
