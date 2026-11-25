# Spesifikasi: Hapus Kolom Kode dari Rekap Uang Makan

## Intent

Admin tidak lagi melihat `emp_code` di rekap bulanan — nama karyawan dianggap cukup. Kode juga tidak ikut turun ke file Excel supaya tampilan layar dan export konsisten.

## Goals

- Kolom `Kode` hilang dari tabel rekap dan dari CSV.
- Jumlah kolom, `colspan`, dan posisi angka tetap konsisten di header, baris karyawan, Subtotal, dan GRAND TOTAL.

## Non-goals / Anti-goals

- Mengubah nama, angka, atau aturan hitung uang makan.
- Mengubah halaman lain (`weekly.php`, `index.php`, dll).
- Menambah CSS/JS. Aturan `nth-child(2)` di `style.css:680,777` sudah otomatis kena kolom Nama setelah Kode dihapus.

## Constraints

- Tabel berubah dari 7 kolom jadi 6: `company-col`, Nama, Hadir, Telat, Potongan, Uang Makan.
- `brand-row` `colspan` 7 → 6.
- Baris Subtotal: `colspan="2"` → tanpa `colspan` (kolom company dipakai `<th rowspan>` grup).
- Baris GRAND TOTAL: `colspan="2"` → tanpa `colspan`.
- CSV: header dan tiap baris 6 field; baris `GRAND TOTAL` 6 field dengan nilai uang di index 5.

## Approaches

- **A (ditolak):** sembunyikan Kode di tabel saja, CSV tetap simpan kode. Export jadi beda dari layar dan kode tetap terlihat di Excel.
- **B (dipilih user):** hapus Kode dari tabel **dan** CSV. Layar dan export konsisten; biayanya identifikator numerik hilang dari export, jadi pencocokan ke data HR harus pakai nama.

## Desain

Hapus `<th>Kode</th>`, `<td><?= e($rec['code']) ?></td>`, dan field `Kode` di header CSV + tiap baris CSV. Field `'code'` di array `$recaps` ikut dihapus karena tidak dipakai lagi. Sesuaikan `colspan` seperti di Constraints. Test yang ada diperbarui: GRAND TOTAL CSV dibaca dari index 5, bukan 6.
