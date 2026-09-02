# Absensi Monitor — Progress Log

## Stack
- **PHP 8.3-FPM** (Alpine) + **Nginx** (Alpine)
- **MariaDB** di `192.168.1.141` (serverunico), DB: `fingerprint_absensi`
- Docker Compose, port `9790`
- Domain lokal: `absen.local`

## Struktur File
```
absensi-web/
├── docker-compose.yml
├── Dockerfile
├── nginx.conf
└── app/
    ├── config.php       # DB + App + Telegram config (via env)
    ├── index.php        # Dashboard utama (admin, perlu login)
    ├── login.php        # Halaman login
    ├── logout.php       # Handler logout
    ├── weekly.php       # Laporan mingguan
    ├── public.php       # Halaman publik (tanpa login)
    ├── export.php       # Export CSV harian
    ├── notify.php       # Script notif Telegram (dipanggil cron)
    └── assets/
        ├── style.css
        └── pl-komatsu-ui-template.css
```

## Environment Variables (docker-compose.yml)
| Var | Nilai |
|-----|-------|
| `DB_HOST` | 192.168.1.141 |
| `DB_PORT` | 3306 |
| `DB_USER` | root |
| `DB_NAME` | fingerprint_absensi |
| `APP_USER` | UwU |
| `TELEGRAM_BOT_TOKEN` | `8638300252:AAG...` |
| `TELEGRAM_CHAT_ID` | `-5286727622` |

## Tabel DB yang Dipakai
| Tabel | Kegunaan |
|-------|----------|
| `personnel_employee` | Data karyawan aktif |
| `personnel_department` | Data departemen |
| `iclock_transaction_backup` | Data punch/absensi |

## Nginx
- Default route (`/`) → `public.php`
- Admin akses via `http://absen.local/index.php`

## Cron Jobs
```
5  8  * * 1-5  docker exec absensi-fpm php /var/www/html/notify.php >> /home/orion3/absensi-web/notify.log 2>&1
0  12 * * 1-5  docker exec absensi-fpm php /var/www/html/notify.php >> /home/orion3/absensi-web/notify.log 2>&1
```

## Fitur Selesai ✅

### Admin (index.php)
- [x] Login/logout dengan session
- [x] Dashboard kehadiran hari ini per departemen
- [x] Filter: Hadir / Telat / Belum
- [x] Badge status berwarna + ukuran seragam
- [x] Durasi telat di badge ("+23 m")
- [x] Kartu statistik (Total, Hadir, Telat, Belum)
- [x] Theme switcher (8 tema)
- [x] Navbar glassmorphism + floating capsule saat scroll
- [x] Tombol Export CSV harian
- [x] Link ke halaman publik (buka tab baru)
- [x] Secret shortcut: klik judul 5x → login admin

### Public (public.php)
- [x] Tanpa login, tampil karyawan belum absen
- [x] Auto-refresh 2 menit + countdown
- [x] Jam realtime di navbar
- [x] Pull-to-refresh (swipe down)
- [x] Green screen kalau semua sudah absen
- [x] Floating tombol Tema (kanan bawah)
- [x] Secret shortcut: klik h1 5x → redirect ke index.php
- [x] Navbar ikut tema (glassmorphism)

### Export (export.php)
- [x] Download CSV harian dengan BOM UTF-8 (Excel-safe)
- [x] Support date custom via `?d=YYYY-MM-DD`
- [x] Kolom: No, Nama, Departemen, Masuk, Keluar, Status, Telat (m)

### Notifikasi Telegram
- [x] `notify.php` terpisah dari halaman web
- [x] Cron jam 08:05 dan 12:00 Senin–Jumat
- [x] Log di `/home/orion3/absensi-web/notify.log`

### Optimasi
- [x] Query dioptimasi: range `>= :d AND < :d+1` (bukan `DATE()`)
- [x] Index `idx_punch_time` sudah ada (`uniq_absen`)
- [x] Telegram timeout 3s (non-blocking relatif)
- [x] CSS compact tabel, badge seragam
### Laporan Mingguan & Modal Detail
- [x] Perbaikan syntax error pada `weekly.php` (PHP & JS unclosed tags)
- [x] Modal detail karyawan dengan infinite scroll & filter rentang tanggal
- [x] Tampilan nama karyawan mobile yang responsif (word-wrap 2 baris alami tanpa terpotong)

### Header & UI iOS Liquid Glass
- [x] Header topbar dengan efek iOS/visionOS Liquid Glass (translucent gradient, blur 28px–32px, saturation 210%, dual rim light)
- [x] Search bar Uiverse (garerim design) diletakkan di tengah header (centered) dengan animasi expand
- [x] Tombol pilih tema diletakkan di header kanan (sekali klik)
- [x] Responsive layout header mobile (fluid search, 36px circular glass icons, floating pill dock)
- [x] Tombol melayang Back to Top diubah menjadi iOS Liquid Glass Floating Orb dengan efek liquid glow hover

## Yang Belum / Bisa Ditambah 🔲
- [ ] Rekap bulanan per karyawan (hadir/telat/absen per bulan)
- [ ] Export CSV mingguan dari `weekly.php`
- [ ] Role-based access (admin vs viewer)
- [ ] Auto-refresh dashboard index (polling tiap N menit)
- [ ] Notif Telegram hanya kirim jika ada yang belum (sudah) — cek flag agar tidak dobel jika cron dijalankan manual
