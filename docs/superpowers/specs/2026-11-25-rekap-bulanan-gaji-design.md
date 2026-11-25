# Rekap Bulanan Gaji — Design (short)

Tanggal: 2026-11-25

## Intent

Admin perlu melihat **total gaji yang dibayarkan per bulan** tanpa membuka sistem payroll. Saat ini aplikasi hanya punya laporan absensi mingguan (`weekly.php`, berisi penalty), bukan rekap gaji.

## Goals

- Halaman `rekap_bulanan.php`, **khusus role admin** (employee di-redirect ke `employee.php`, mengikuti pola `weekly.php`).
- Pilih bulan lewat input `type="month"` + tombol bulan lalu/berikut; default bulan berjalan.
- Ringkasan bulan: jumlah slip/karyawan, total gaji pokok, total pendapatan, total potongan, **total gaji dibayar (`take_home_pay`)**.
- Tabel per `company_name`: baris karyawan (`employee_name`, `code`, `basic_salary`, `total_income`, `total_deduction`, `take_home_pay`), subtotal per perusahaan, grand total.
- Bulan tanpa data menampilkan pesan "Belum ada data gaji untuk bulan ini", bukan angka Rp 0.

## Non-goals / Anti-goals

- Tidak menggabungkan penalty absensi dari `weekly.php` (kolom `penalty` di slip bernilai 0; potongan sudah tercatat di `total_deduction`). Menampilkan angka gaji yang berbeda dari payroll resmi = gagal.
- Tidak ada akses employee, tidak ada edit data gaji, tidak ada export/API (penggunanya beberapa admin saja).
- Tidak memakai `unicoapps.slip_gaji` (data berhenti April 2026, `kode` tidak cocok dengan `emp_code`).

## Constraints

- Sumber data: `tax_app.salary_slips` (database lain di server MySQL yang sama, sudah bisa diakses lewat `db()`). Filter wajib: `deleted_at IS NULL`, `date` dalam bulan terpilih.
- `department` dan `slip_gaji_type` tidak dipakai; `company_name` terisi dan jadi pengelompokan. `company_name` NULL → grup "Lainnya".
- Tidak ada tabel/migrasi baru, tidak ada dependency baru. Semua query read-only.

## Approaches

- **A (dipilih):** halaman server-rendered yang membaca slip payroll yang sudah ada. Angka langsung valid, tanpa sinkronisasi data gaji. Risiko: bulan yang belum diisi payroll tampil kosong — sesuai kondisi data, bukan bug.
- **B (ditolak):** tabel gaji pokok baru di SQLite + hitung sendiri dari absensi. Perlu input 48 karyawan dan rawan selisih dengan payroll resmi.
- **C (ditolak):** `unicoapps.slip_gaji` — data stale sejak April 2026 dan tidak bisa di-join ke absensi.

## Implementasi (call writer)

- File baru `app/rekap_bulanan.php`: ikuti struktur `weekly.php` (config + `require_login()`, guard employee, breadcrumb, CSS `pl-komatsu-ui-template.css` + `style.css`, theme switcher, drawer, favicon + `theme-color`, `toast_js()`).
- Query agregat per perusahaan + query detail karyawan untuk satu bulan; hindari N+1 dengan satu query detail diurutkan `company_name, employee_name`.
- Tambah tautan drawer "Rekap Bulanan" di `index.php`, `weekly.php`, `visitors.php`, `profile.php`; tandai `class="active"` di halaman rekap.
- Format rupiah memakai `number_format($n, 0, ',', '.')` seperti halaman lain.
- Verifikasi: `php -l` lewat container `absensi-fpm`, lalu buka halaman sebagai admin dan cek total Nov 2026 = Rp 273.837.000 (48 slip) serta bulan Okt 2026 menampilkan empty state.
