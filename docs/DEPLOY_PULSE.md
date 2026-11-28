# Deploy: Dashboard Monitoring Sistem (Laravel Pulse)

Dashboard di `/pulse` (menu **Monitoring Sistem** di sidebar admin, terbuka di tab baru). Hanya pemegang izin
`view_server_health` — bawaan hanya **super_admin**; role lain mendapat 403 walau alamatnya diketik manual.
Latar belakang & isi kartu: [PLAN_INSIDEN_PEMBAYARAN_DAN_MONITORING.md](PLAN_INSIDEN_PEMBAYARAN_DAN_MONITORING.md) Langkah 4.

**Urutan uji coba:** sandbox dulu beberapa hari → production.

## Yang ikut dalam kode
| Bagian | Keterangan |
|---|---|
| `laravel/pulse ^1.11` | +3 package (`laravel/pulse`, `laravel/sentinel`, `doctrine/sql-formatter`); package lama tidak berubah |
| Migration `create_pulse_tables` | 3 tabel baru: `pulse_values`, `pulse_entries`, `pulse_aggregates` (hanya menambah) |
| Migration `grant_server_health_permission` | Izin baru `view_server_health` → role super_admin |
| `config/pulse.php` | Cache dimatikan; alamat Midtrans/DOKU disamarkan; data disimpan 7 hari |
| Kartu Usage | 10 akun paling aktif (nama + role). Hanya aktivitas orang: link bio, TV signage, polling QR kasir, refresh jadwal, cek hak akses, sambungan realtime & cek status bayar customer tidak dihitung (`UsageRequestFilter`). Matikan: `PULSE_USER_REQUESTS_ENABLED=false` |
| Tugas `monitoring:snapshot` | Tiap 5 menit (scheduler) → `storage/app/monitoring/snapshot.json` untuk kartu Club 61 |
| Kartu Club 61 | Storage, Layanan, Pembayaran (bahasa Indonesia); kartu bawaan Pulse berbahasa Inggris |

## 1. Deploy kode
```bash
cd /var/www/club61-sandbox            # lalu production setelah uji coba
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate                   # 3 tabel Pulse + izin view_server_health
php artisan optimize                  # config, route, view cache
```

## 2. `.env`
```
PULSE_SERVER_NAME=club61-sandbox      # production: club61-production
```
Production & sandbox satu VPS — tanpa nama berbeda, kartu Servers keduanya tertukar. Setelah mengubah `.env`:
`php artisan config:cache`.

Opsional (biarkan kosong = bawaan):
- `PULSE_ENABLED=false` — mematikan pencatatan sementara (dashboard tetap bisa dibuka).
- `PULSE_SLOW_REQUESTS_THRESHOLD`, `PULSE_SLOW_QUERIES_THRESHOLD`, `PULSE_SLOW_OUTGOING_REQUESTS_THRESHOLD` (ms, bawaan 1000).

## 3. Supervisor — `/etc/supervisor/conf.d/club61-pulse-sandbox.conf`
Kartu **Servers** (CPU, RAM, disk) hanya terisi kalau proses ini berjalan terus.

```ini
[program:club61-pulse-sandbox]
command=php /var/www/club61-sandbox/artisan pulse:check
directory=/var/www/club61-sandbox
user=www-data
autostart=true
autorestart=true
stopwaitsecs=10
redirect_stderr=true
stdout_logfile=/var/www/club61-sandbox/storage/logs/pulse-check.log
```

Production: salin file ini, ganti nama program jadi `club61-pulse-production` dan semua path ke folder production.
Path `/var/www/...` dan user `www-data` di atas contoh — samakan dengan program Reverb yang sudah ada.

```bash
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status club61-pulse-sandbox
```

## 4. Isi data pertama (tidak perlu menunggu 5 menit)
```bash
php artisan monitoring:snapshot
```
Harus keluar `Snapshot tersimpan — disk terpakai ..%`. Selanjutnya scheduler mengulanginya tiap 5 menit.

## 5. Tiap deploy berikutnya
```bash
php artisan pulse:restart             # pulse:check memuat kode baru (Supervisor menyalakannya lagi)
```
Tambahkan ke langkah deploy yang biasa (bersama `php artisan reverb:restart` bila ada).

## 6. Cek setelah deploy
1. Login sebagai super admin → menu **Monitoring Sistem** → tab baru `/pulse` terbuka.
2. Kartu **Servers** menampilkan nama `club61-sandbox` (butuh langkah 3).
3. Kartu **Storage / Layanan / Pembayaran Club 61** terisi; "Tugas otomatis: Berjalan".
4. Login sebagai admin / kasir → menu tidak ada; buka `/pulse` manual → 403.

## Kalau Pulse tidak cocok (dibongkar)
1. Hentikan & hapus program Supervisor `club61-pulse-*`.
2. Di kode: buat **migration baru** yang `dropIfExists` ketiga tabel Pulse (migration lama tidak diubah), hapus file
   migration `create_pulse_tables` (bergantung pada class Pulse), `config/pulse.php`, `resources/views/vendor/pulse`,
   lalu `composer remove laravel/pulse`.
3. Logika data kartu (`app/Support/Monitoring`) bisa dipakai ulang untuk dashboard buatan sendiri.
