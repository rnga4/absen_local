# Audit fixes — design

**Intent:** Tutup temuan audit yang masih berlaku, kecuali dua yang disengaja oleh user: password karyawan bersama (`unico@123`) dan ❤️ yang akumulatif (tidak reset harian).

**Goals:** Akses HTTP tidak bisa dipakai untuk menjalankan script CLI atau membaca data lintas role; session cookie tidak bisa dibaca JS; input mustahil/array tidak bikin 500; angka dashboard dan navigasi mingguan benar; upload foto tidak merusak avatar lama saat gagal.

**Non-goals:** Migrasi DB, perubahan skema, endpoint baru, dependency baru, desain ulang UI, klien Android, rebuild image Docker. Tidak menyentuh `seed_employee_users.php`, tidak mengubah hitungan uang makan/rekap.

**Constraints:** PHP 8.3 + SQLite + MySQL remote, nginx `:9790`, file di-mount langsung ke container (edit = deploy). Semua perubahan harus lolos `php -l` di `absensi-fpm` dan probe lama (`verify-uang-makan.sh`, `verify-card-detail.sh`, 4 probe UI).

**Approaches:** (1) Patch di tempat, guard di batas kepercayaan — dipilih: diff terkecil, mengikuti pola `setup_users.php` dan `api_dashboard.php`. (2) Middleware/helper auth terpusat — ditolak: menyentuh semua entry point dan berisiko merusak perilaku session lama. (3) Perbaikan di nginx — ditolak: tidak menutup celah PHP-level (role, cookie, XSS).

**Decisions (call agent):** Testing memakai shell + curl + headless Chrome seperti probe yang sudah ada, bukan framework test baru. Item "CREATE TABLE tiap request" sengaja dilewati: `CREATE TABLE IF NOT EXISTS` + `INSERT OR IGNORE` adalah no-op murah pada beban ~50 karyawan; menghilangkannya berisiko counter hilang kalau file SQLite dibuat ulang. Duplikasi blok upload di `profile.php` juga dilewati: bug-nya (urutan hapus) diperbaiki di dua cabang, refactoring helper tidak mengubah perilaku.
