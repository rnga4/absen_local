# Rekap Card Detail Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Buat 4 card rekap bisa diklik membuka detail per departemen, plus legend cut-off 13.00 dan subtotal CSV per departemen.

**Architecture:** Satu modal `.emp-modal-overlay` di `rekap_bulanan.php` dipakai ulang untuk 4 card; panel detail disiapkan server-side dari `$subs`/`$grand` dan ditoggle pakai atribut `hidden`. Tidak ada query, endpoint, atau library baru. Semua data sudah dihitung sebelum HTML.

**Tech Stack:** PHP 8.3 server-rendered, CSS existing, JS vanilla, probe headless Chrome lokal.

**Spec:** `docs/superpowers/specs/2026-11-25-rekap-card-detail-design.md`

## Global Constraints

- Card memakai `<a href="#" class="card" data-detail="uang|potongan|hadir|count">`.
- Modal: id `detailOverlay`, judul id `detailTitle`, tombol tutup id `detailCloseBtn`, panel `[data-panel]`.
- Tabel detail memakai `.tbl rekap-detail`; dua override CSS scoped hanya untuk `.tbl.rekap-detail`.
- Legend ditambah item kelima persis: `Punch setelah 13.00 tidak dihitung hadir`.
- Baris Subtotal CSV: 6 field `['Subtotal <dept>', '', days, late, potongan, uang]`, sebelum GRAND TOTAL.
- Angka Nov tetap: total `76430000`, hadir `858`, potongan `790000`, 43 karyawan.

## Review Focus

- Card bisa diklik dengan mouse dan keyboard (link fokus) → Task 1 Step 1 probe click.
- Modal tertutup lewat tiga jalur: tombol ×, backdrop, Escape → Task 1 Step 1 probe.
- Panel yang tampil sesuai card yang diklik dan angkanya sesuai metrik → Task 1 Step 1 probe.
- Empty state (Des 2026) tidak menampilkan card/modal kosong → Task 1 Step 5 probe regresi `verify-uang-makan.sh`.
- Baris Subtotal CSV tidak ikut dijumlahkan sebagai karyawan → Task 3 Step 1 test.

---

### Task 1: Card bisa diklik, membuka detail per departemen

**Files:**
- Modify: `app/rekap_bulanan.php` (blok `.cards`, tambah modal sebelum `</main>`, tambah JS di blok script)
- Modify: `app/assets/style.css` (dua override `.tbl.rekap-detail`)
- Create: `/tmp/opencode/verify-card-detail.sh` (artefak lokal, tidak di-commit)
- Commit: perubahan aplikasi + spesifikasi desain

**Interfaces:**
- Consumes: `$groups`, `$subs`, `$grand`, `rupiah()` dari `rekap_bulanan.php`.
- Produces: elemen `.rekap-cards a.card[data-detail]`, `#detailOverlay`, `[data-panel]`.

- [ ] **Step 1: Tulis test yang gagal**

Buat `/tmp/opencode/verify-card-detail.sh` dengan pola `verify-uang-makan.sh`: curl halaman Nov pakai `/tmp/opencode/rekap-admin.cookie`, rewrite `href="assets/` ke URL absolut, sisipkan JS `load` yang:
- mengumpulkan `cardKeys` dari `.rekap-cards a.card[data-detail]`,
- klik card ke-0, simpan `{active, title, panels, text}` (panel tampil = yang `!hidden`),
- tutup via `#detailCloseBtn`, simpan `active`,
- klik card ke-2, tekan `Escape` via `document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}))`, simpan `active`,
- klik card ke-1, klik overlay, simpan `active`,
- tulis hasil ke `<pre id="probe">` lalu dump dengan `google-chrome --headless --no-sandbox --disable-gpu --virtual-time-budget=3000 --dump-dom`.

Python assert: `cardKeys == ['uang','potongan','hadir','count']`; klik pertama `active` true, `panels == ['uang']`, `text` mengandung `Rp 76.430.000`; klik ketiga `panels == ['hadir']`, `text` mengandung `858`; ketiga kondisi tutup `active` false.

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `bash /tmp/opencode/verify-card-detail.sh`
Expected: FAIL karena selector card tidak ada.

- [ ] **Step 3: Ubah card jadi link dan tambah markup modal**

Di `app/rekap_bulanan.php`:
- ganti 4 `<div class="card">` dengan `<a href="#" class="card" data-detail="uang|potongan|hadir|count">` (urutan: uang, potongan, hadir, count), tutup `</a>`.
- sisipkan sebelum `</main>`: `<div class="emp-modal-overlay" id="detailOverlay" role="dialog" aria-modal="true">` dengan `.emp-modal-box`, header berisi `<h3 class="emp-info-title" id="detailTitle">` dan `<button type="button" class="emp-modal-close-btn" id="detailCloseBtn" aria-label="Tutup">&times;</button>`, serta `.emp-modal-body` berisi 4 `<div data-panel="...">`.
- tiap panel: `<table class="tbl rekap-detail">` dengan header `Departemen` + label metrik, satu baris per `$groups` berisi `e($dept)` dan nilai dari `$subs[$dept]` (count pakai `count($groups[$dept])`), dan baris terakhir `TOTAL` dengan nilai `$grand`. Semua panel kecuali yang pertama memakai atribut `hidden`.

Di `app/assets/style.css` setelah aturan `.tbl.rekap td.num:nth-child(2)`, tambahkan:
- `.tbl.rekap-detail td:first-child, .tbl.rekap-detail th:first-child { width: auto; text-align: left; }`
- `.tbl.rekap-detail td:last-child, .tbl.rekap-detail th:last-child { text-align: right !important; }`

- [ ] **Step 4: Tambah JS buka/tutup modal**

Di blok `<script>` `rekap_bulanan.php`, sebelum IIFE tema, tambah IIFE: kumpulkan card, panel, overlay, judul; `openDetail(key)` isi judul dari `.lbl` card yang diklik, sembunyikan semua panel, tampilkan panel `key`, tambah `.active`; `closeDetail()` hapus `.active`. Pasang `click` tiap card dengan `preventDefault()`, `click` tombol tutup, `click` overlay hanya bila `e.target === overlay`, dan `keydown` Escape di `document`.

- [ ] **Step 5: Verifikasi hijau + regresi**

Run: `bash /tmp/opencode/verify-card-detail.sh && bash /tmp/opencode/verify-uang-makan.sh && bash /tmp/opencode/verify-vertical.sh && bash /tmp/opencode/verify-cards.sh | tail -1 && bash /tmp/opencode/verify-sep.sh && bash /tmp/opencode/verify-brand-center.sh && docker exec absensi-fpm php -l /var/www/html/rekap_bulanan.php && git diff --check`
Expected: semua PASS; probe kartu lama (`verify-cards.sh`) tetap PASS.

- [ ] **Step 6: Commit**

```bash
git add app/rekap_bulanan.php app/assets/style.css docs/superpowers/specs/2026-11-25-rekap-card-detail-design.md
git commit -m "feat: add per-department detail to monthly recap summary cards"
```

---

### Task 2: Legend menyebut cut-off 13.00

**Files:**
- Modify: `app/rekap_bulanan.php` (blok `.weekly-legend`)
- Modify: `/tmp/opencode/verify-uang-makan.sh`

**Interfaces:**
- Consumes: blok `.weekly-legend` yang sudah ada.
- Produces: satu `.lg-item` tambahan.

- [ ] **Step 1: Tambah assertion yang gagal**

Di loop pengecekan legend `/tmp/opencode/verify-uang-makan.sh`, tambah `'Punch setelah 13.00 tidak dihitung hadir'` ke daftar teks.

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `bash /tmp/opencode/verify-uang-makan.sh`
Expected: FAIL di `legenda hilang: Punch setelah 13.00 tidak dihitung hadir`.

- [ ] **Step 3: Tambah item legend**

Di `app/rekap_bulanan.php`, setelah item `Minggu / tanpa punch = Rp 0`, tambah `<span class="lg-item">Punch setelah 13.00 tidak dihitung hadir</span>`.

- [ ] **Step 4: Verifikasi**

Run: `bash /tmp/opencode/verify-uang-makan.sh`
Expected: PASS integration, angka tidak berubah.

- [ ] **Step 5: Commit**

```bash
git commit -am "docs: explain 13:00 punch cut-off in recap legend"
```

---

### Task 3: Subtotal per departemen di CSV

**Files:**
- Modify: `app/rekap_bulanan.php` (blok `export`)
- Modify: `/tmp/opencode/verify-uang-makan.sh`

**Interfaces:**
- Consumes: `$groups`, `$subs`, `csv_num()`, `csv_text()`.
- Produces: 3 baris `Subtotal <dept>` sebelum GRAND TOTAL.

- [ ] **Step 1: Ubah test dulu (RED)**

Di `/tmp/opencode/verify-uang-makan.sh`:
- pada baris pembacaan CSV, skip baris yang `$r[0]` diawali `Subtotal ` sebelum `$n++`/`$s +=`.
- tambah pengecekan via PHP: jumlah baris yang `$r[0]` diawali `Subtotal ` tepat 3; baris pertama assert 6 field dengan `$r[0] === 'Subtotal KPS'`, `$r[1] === ''`, `$r[2] === '145'`, `$r[3] === '14'`, `$r[4] === '150000'`, `$r[5] === '12900000'`.
- grand total tetap `76430000` dan jumlah baris karyawan tetap 43.

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `bash /tmp/opencode/verify-uang-makan.sh`
Expected: FAIL di pengecekan jumlah baris Subtotal.

- [ ] **Step 3: Tulis baris Subtotal di export**

Di blok `export` `app/rekap_bulanan.php`, setelah loop `$rows` per departemen dan sebelum `fputcsv` GRAND TOTAL, tambah `fputcsv($out, ['Subtotal ' . csv_text($dept), '', $subs[$dept]['days'], $subs[$dept]['late'], csv_num($subs[$dept]['potongan']), csv_num($subs[$dept]['uang'])])`.

- [ ] **Step 4: Verifikasi**

Run: `bash /tmp/opencode/verify-uang-makan.sh && bash /tmp/opencode/verify-card-detail.sh && docker exec absensi-fpm php -l /var/www/html/rekap_bulanan.php && git diff --check`
Expected: semua PASS.

- [ ] **Step 5: Commit dan push**

```bash
git commit -am "feat: include department subtotals in meal recap CSV"
git push origin master
```

Plan complete and saved to `docs/superpowers/plans/2026-11-25-rekap-card-detail.md`.
