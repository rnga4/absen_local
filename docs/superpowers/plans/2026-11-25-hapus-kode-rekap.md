# Hapus Kolom Kode dari Rekap Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Hilangkan kolom `Kode` dari tabel rekap bulanan dan dari CSV, tanpa mengubah angka atau aturan hitung.

**Architecture:** Satu file `app/rekap_bulanan.php` dipangkas: header/baris Kode dihapus, `colspan` disesuaikan dari 7 kolom jadi 6, dan field `Kode` di CSV ikut dihapus. CSS tidak disentuh karena `nth-child(2)` otomatis tetap menunjuk kolom Nama.

**Tech Stack:** PHP 8.3 server-rendered, CSS existing, `/tmp/opencode/verify-uang-makan.sh` + 4 probe UI lokal.

**Spec:** `docs/superpowers/specs/2026-11-25-hapus-kode-rekap-design.md`

## Global Constraints

- Tabel 6 kolom: `company-col`, Nama, Hadir, Telat, Potongan, Uang Makan.
- `brand-row` `colspan="6"`.
- Subtotal: `colspan="2"` → tanpa `colspan`; GRAND TOTAL: `colspan="2"` → tanpa `colspan`.
- CSV 6 field per baris; GRAND TOTAL nilai uang di index 5.
- Nama, angka, perhitungan, dan halaman lain tidak berubah.

## Review Focus

- Detail row tetap 6 sel dan subtotal 5 sel → diuji Task 1 Step 1 (probe DOM).
- CSV GRAND TOTAL terbaca dari index 5, bukan 6 → diuji Task 1 Step 1.
- Tidak ada sisa teks `Kode` di halaman maupun CSV → diuji Task 1 Step 1.
- Probe UI lama yang mengasumsikan 7 sel detail diperbarui ke 6 → diuji Task 1 Step 4.
- Angka Nov tetap Rp 76.430.000 dan CSV sum `76430000` → diuji Task 1 Step 4.

---

### Task 1: Pangkas Kode dari tabel dan CSV

**Files:**
- Modify: `app/rekap_bulanan.php`
- Modify: `/tmp/opencode/verify-uang-makan.sh`
- Modify: `/tmp/opencode/verify-vertical.sh`
- Commit: spec + plan + perubahan

**Interfaces:**
- Consumes: `$groups`, `$subs`, `$grand`, `csv_text()`, `csv_num()` yang sudah ada.
- Produces: tabel 6 kolom dan CSV 6 field, angka sama persis.

- [ ] **Step 1: Ubah test dulu (RED)**

Di `/tmp/opencode/verify-uang-makan.sh`:
- ganti pembacaan GRAND TOTAL CSV dari `$r[6]` ke `$r[5]` (dua tempat: `echo` dan `$s +=`),
- tambah `! printf '%s' "$page" | grep -qF '<th>Kode</th>' || fail 'header Kode masih ada'`,
- tambah `head -1 /tmp/opencode/uang-makan.csv | grep -qF 'Departemen,Nama,Hadir,Telat,Potongan,Uang Makan' || fail 'header CSV salah'`,
- pertahankan probe subtotal yang sudah ada (`len(c) == 5`).

Di `/tmp/opencode/verify-vertical.sh`:
- `rowCells[2] == 6` (dulu 7),
- `rowCells[-1] == 5` (dulu 6).

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `bash /tmp/opencode/verify-uang-makan.sh`
Expected: FAIL di `header Kode masih ada`.

- [ ] **Step 3: Pangkas kode**

Di `app/rekap_bulanan.php`:
- hapus `'code' => $emp['emp_code'],` dari inisialisasi `$recaps`,
- hapus `<th>Kode</th>` dan `<td><?= e($rec['code']) ?></td>`,
- `colspan="7"` → `colspan="6"` di `brand-row`,
- hapus `colspan="2"` pada `<td class="left" colspan="2">Subtotal</td>` dan `<td class="left total" colspan="2">GRAND TOTAL</td>`,
- CSV: header jadi `['Departemen','Nama','Hadir','Telat','Potongan','Uang Makan']`, hapus `csv_text($rec['code'])` dari baris, baris GRAND TOTAL jadi 6 field (3 string kosong sebelum potongan).

- [ ] **Step 4: Verifikasi hijau + regresi UI**

Run: `bash /tmp/opencode/verify-uang-makan.sh && bash /tmp/opencode/verify-cards.sh | tail -1 && bash /tmp/opencode/verify-vertical.sh && bash /tmp/opencode/verify-sep.sh && bash /tmp/opencode/verify-brand-center.sh && docker exec absensi-fpm php -l /var/www/html/rekap_bulanan.php`
Expected: semua PASS; subtotal 5 sel; detail 6 sel; GRAND TOTAL 5 sel; CSV sum `76430000`.

- [ ] **Step 5: Commit dan push**

```bash
git add app/rekap_bulanan.php docs/superpowers/specs/2026-11-25-hapus-kode-rekap-design.md docs/superpowers/plans/2026-11-25-hapus-kode-rekap.md
git commit -m "refactor: remove employee code from meal recap and CSV"
git push origin master
```

Plan complete and saved to `docs/superpowers/plans/2026-11-25-hapus-kode-rekap.md`.
