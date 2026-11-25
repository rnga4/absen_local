# Spesifikasi: Legenda Rekap Uang Makan

## Intent

Admin membuka rekap bulanan dan langsung paham cara baca angkanya, tanpa bertanya atau menebak. Masalahnya: kolom `Telat` berisi jumlah **hari** telat, sementara potongan dihitung per **jam yang dimulai** — mudah tertukar.

## Goals

- Penjelasan aturan uang makan terlihat di halaman `rekap_bulanan.php`, baik saat ada data maupun saat empty state.
- Menjelaskan secara eksplisit: arti kolom Telat, tarif harian, dan contoh potongan telat 1 menit / 61 menit.

## Non-goals / Anti-goals

- Mengubah angka, rumus, atau query yang sudah lolos verifikasi.
- Menambah teks ke file CSV — Excel harus tetap berisi angka polos.
- Menambah JavaScript, dependency, atau tombol `?`.

## Constraints

- Pakai class `.weekly-legend`, `.lg-label`, `.lg-item` yang sudah ada di `app/assets/style.css:411` (dipakai `weekly.php:322`).
- Ditempatkan tepat di bawah baris periode (`app/rekap_bulanan.php:222`), sebelum empty state / kartu.
- Tanpa CSS baru, tanpa JS baru, tanpa perubahan layout tabel.

## Approaches

- **A (dipilih):** satu baris legenda statis memakai class yang sudah ada. Selalu terbaca, nol JS, aman di layar sentuh. Biaya: memakan satu baris vertikal.
- **B (ditolak):** tombol `?` + popover. Lebih ringkas, tapi butuh JS, penanganan klik-di-luar/Esc, dan informasinya tersembunyi sampai user sadar ada tombolnya.
- **C (ditolak):** catatan di baris CSV. Membuat kolom teks ikut turun ke Excel dan mengganggu angka.

## Desain

Isi legenda (empat item, satu baris `lg-label`):

```
Ketentuan: Rp 90.000 per hari hadir (Senin–Sabtu) · Telat = jumlah hari masuk setelah 08.00 ·
Potong Rp 10.000 per jam yang dimulai (1 menit = Rp 10.000, 61 menit = Rp 20.000) ·
Minggu / tanpa punch = Rp 0
```

Keputusan yang gue ambil: contoh "1 jam pertama" disampaikan lewat frasa "per jam yang dimulai" plus dua contoh menit, supaya nggak salah diartikan sebagai angka di kolom Telat.
