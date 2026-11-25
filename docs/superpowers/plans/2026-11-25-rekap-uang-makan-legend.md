# Legenda Rekap Uang Makan Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tambah satu baris penjelasan aturan uang makan di halaman rekap bulanan tanpa mengubah angka, CSV, atau menambah JS.

**Architecture:** Satu blok HTML statis di `app/rekap_bulanan.php`, memakai class `.weekly-legend` yang sudah ada di `app/assets/style.css:411`. Ditempatkan di luar blok `$hasData` supaya tetap muncul saat empty state.

**Tech Stack:** PHP 8.3 server-rendered, CSS existing, `/tmp/opencode/verify-uang-makan.sh` untuk verifikasi.

**Spec:** `docs/superpowers/specs/2026-11-25-rekap-uang-makan-legend-design.md`

## Global Constraints

- Pakai `.weekly-legend`, `.lg-label`, `.lg-item` — **jangan** tambah CSS baru.
- Posisi: tepat di bawah `<p class="range-info">Periode: ...` (`app/rekap_bulanan.php:222`).
- Tampil baik saat ada data maupun saat `!$hasData`.
- CSV tetap angka polos: tanpa "Ketentuan", tanpa teks penjelasan.
- Nol JavaScript, nol dependency, nol perubahan query/rumus.

## Review Focus

- Empty state (2026-12) tetap punya legenda → diuji Task 1 Step 1.
- CSV tidak ikut kena teks legenda → diuji Task 1 Step 1.
- Kolom `Telat` dijelaskan sebagai jumlah hari, bukan jam → diuji Task 1 Step 1.
- Potongan "per jam yang dimulai" dengan contoh 1 menit / 61 menit → diuji Task 1 Step 1.
- Kartu, tabel, dan probe UI lama tidak berubah → diuji Task 1 Step 5.

---

### Task 1: Legenda statis di halaman rekap

**Files:**
- Modify: `app/rekap_bulanan.php` (sisipkan blok setelah baris periode)
- Test: `/tmp/opencode/verify-uang-makan.sh` (artefak lokal, tidak di-commit)
- Commit: spesifikasi desain yang sudah disetujui

**Interfaces:**
- Consumes: `$bulan`, `$hasData` yang sudah ada di `app/rekap_bulanan.php`.
- Produces: elemen `.weekly-legend` dengan empat `.lg-item` dan satu `.lg-label`.

- [ ] **Step 1: Tambah assertion yang gagal**

Tambahkan ke `/tmp/opencode/verify-uang-makan.sh`:

```bash
for text in 'Ketentuan:' 'Rp 90.000 per hari hadir' 'Telat = jumlah hari' \
            'Rp 10.000 per jam yang dimulai' '1 menit = Rp 10.000' \
            '61 menit = Rp 20.000' 'Minggu / tanpa punch = Rp 0'; do
  printf '%s' "$page" | grep -qF "$text" || fail "legenda hilang: $text"
done
printf '%s' "$dec" | grep -qF 'Ketentuan:' || fail 'legenda hilang di empty state'
! grep -q 'Ketentuan' /tmp/opencode/uang-makan.csv || fail 'legenda masuk ke CSV'
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `bash /tmp/opencode/verify-uang-makan.sh`
Expected: FAIL di `legenda hilang: Ketentuan:`.

- [ ] **Step 3: Sisipkan blok legenda**

Setelah `<p class="range-info">Periode: ...</p>` di `app/rekap_bulanan.php`, tambahkan:

```php
        <div class="weekly-legend">
            <span class="lg-label">Ketentuan:</span>
            <span class="lg-item">Rp 90.000 per hari hadir (Senin–Sabtu)</span>
            <span class="lg-item">Telat = jumlah hari masuk setelah 08.00</span>
            <span class="lg-item">Potong Rp 10.000 per jam yang dimulai (1 menit = Rp 10.000, 61 menit = Rp 20.000)</span>
            <span class="lg-item">Minggu / tanpa punch = Rp 0</span>
        </div>
```

Taruh **sebelum** `<?php if (!$hasData): ?>`. Jangan ubah baris lain.

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `bash /tmp/opencode/verify-uang-makan.sh`
Expected: `PASS unit` + `PASS subtotal columns` + `PASS integration` + `rows=43 sum=76430000`.

- [ ] **Step 5: Cek regresi UI**

Run: `bash /tmp/opencode/verify-cards.sh | tail -1 && bash /tmp/opencode/verify-vertical.sh && bash /tmp/opencode/verify-sep.sh && bash /tmp/opencode/verify-brand-center.sh`
Expected: keempat probe lulus. Lalu `docker exec absensi-fpm php -l /var/www/html/rekap_bulanan.php` → `No syntax errors`.

- [ ] **Step 6: Commit dan push**

```bash
git add app/rekap_bulanan.php docs/superpowers/specs/2026-11-25-rekap-uang-makan-legend-design.md docs/superpowers/plans/2026-11-25-rekap-uang-makan-legend.md
git commit -m "feat: explain meal allowance rules on monthly recap"
git push origin master
```

Plan complete and saved to `docs/superpowers/plans/2026-11-25-rekap-uang-makan-legend.md`.
