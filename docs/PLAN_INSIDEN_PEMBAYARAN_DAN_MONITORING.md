# Plan: Insiden Pembayaran Gateway & Dashboard Monitoring

## Keputusan terbaru — 23 Nov 2026 (menggantikan isi di bawah bila bertentangan)
- **Urutan baru:** Langkah 4 (Pulse) dikerjakan **lebih dulu**; perbaikan pembayaran menyusul.
- **Langkah 4 — SUDAH DIBANGUN (23 Nov, belum di-commit).** Panduan server: [DEPLOY_PULSE.md](DEPLOY_PULSE.md).
  Tambahan dari rencana: peringatan `[ALERT]` di file log ikut tercatat ke Pulse (`LogAlertRecorder`, tanpa menyentuh
  kode pembayaran) dan tampil di kartu Pembayaran Club 61, termasuk "SUCCESS dibantah API".
- **Uang telat masuk → pembayaran, bukan refund** (user ingin Antrian Refund seminimal mungkin). Batas bayar tidak
  ditambah; slot tetap dilepas tepat waktu. Saat uang telat masuk: slot masih kosong → booking hidup lagi & LUNAS;
  slot sudah terisi → uang tercatat sebagai pembayaran lalu **otomatis jadi voucher saldo** customer (perlu konfirmasi
  akhir user); refund tunai hanya bila customer meminta. Booking yang memakai kuota member / voucher → jalur voucher saldo.
  Notifikasi SUCCESS yang dibantah API (2.0) mengikuti aturan ini, tidak lagi diabaikan.
- **Dibatalkan:** link pembayaran tanpa login (1a — halaman bayar tetap wajib login, QR boleh di-download), petunjuk
  scan / tutup QR kedaluwarsa / teks "berlaku s.d." (1b–1d). Langkah 1 tinggal `MIN_RETRY_MINUTES` 2 → 5.
- **Kasir input manual (kasus 31 Okt):** bukan tercatat dua kali, tetapi **salah kanal settlement** — dicatat QRIS
  Static (merchant BCA) padahal uang masuk lewat DOKU. ORD-PAD-EUDJWDH8PP dicatat di rekap finance (koreksi metode tidak
  bisa: shift sudah tutup). Setelah perbaikan 2.0, pembayaran DOKU telat tercatat otomatis sebagai QR Online (DOKU);
  kasir diberi tanda "sudah tercatat otomatis, jangan diinput ulang". Item 2.3 (deteksi RRN ganda) tidak dikerjakan.

> **Revisi 2 — 22 Nov 2026.** Revisi 1 (22 Nov pagi) diperbarui dengan hasil investigasi log server dan jawaban user.
> Status: **rencana, belum dikerjakan.** Nomor baris bisa bergeser.
> Urutan kerja: **Langkah 0 → 1 → 2 → 4 (Pulse) → 3**. Tiap langkah dikerjakan setelah ada "gas" dari user, dan tidak
> di-commit oleh Claude.

## Ringkasan perubahan revisi 2
- **Temuan kritis (terbukti di kode):** notifikasi DOKU **SUCCESS** yang dibantah API status DOKU (`CLOSED`) **diabaikan**
  (`IGNORED` + `[ALERT]` di log), tidak dicatat sebagai late settlement. Ini menjadi **item pertama Langkah 2**.
- **Temuan baru (terbukti di kode):** booking walk-in yang dibayar lewat **QR Online di kasir** berstatus `LOCKED`, dan
  slot `LOCKED` dilepas **tanpa bertanya ke gateway**, lalu sesi QR-nya ditutup di DOKU. Ini kemungkinan penyebab
  "API status: CLOSED" pada notifikasi yang datang belakangan.
- Kasus "QR tidak valid" 18 Nov kemungkinan besar **bukan** karena kedaluwarsa. Langkah 1 ditambah **link pembayaran
  tanpa login** dan petunjuk scan.
- Pencatatan ulang manual oleh kasir (RRN sama) wajib diperhitungkan supaya tidak dihitung dua kali.
- Jawaban user untuk pertanyaan terbuka sudah dicatat (bagian 6). `composer audit` sudah dijalankan (bagian 7).

## 1. Latar belakang & kasus nyata

| Kasus | Order | Yang terjadi (dari log server) |
|---|---|---|
| Sudah bayar, booking lepas | **ORD-PAD-24Y3BXH8RT** (31 Okt) | DOKU mengirim notifikasi SUCCESS **50 menit terlambat**. Log: `Notifikasi DOKU #42 [...] SUCCESS → ignored (API status: CLOSED)`. Kasir lalu membuat **order manual** `ORD-PAD-EUDJWDH8PP` dengan **RRN yang sama**. |
| "QR tidak valid" | **ORD-PAD-UEQOTGIOTD** (18 Nov) | QR dibuat 11:37:06, berlaku s.d. ±11:47, error 11:44 (masih dalam masa berlaku). Link invoice dibuka di **perangkat kedua** 11:38:41 dan **dialihkan ke login (302)**, jadi QR tidak tampil di sana. QR kemungkinan di-scan dari **foto layar / gambar WhatsApp**. Booking hangus **tepat** 11:47:24 dan QR langsung dibatalkan. |

Permintaan tambahan user: dashboard untuk memantau **storage server** dan **API** (termasuk Midtrans / DOKU). Backup
otomatis, restore, dan traffic pengunjung **ditunda**.

## 2. Cara kerja pembayaran saat ini (hasil cek kode)

### 2.1 Webhook vs polling
Keduanya berjalan **bersamaan**. Mana pun yang tiba lebih dulu yang melunasi; yang belakangan melihat status sudah lunas.
Keduanya lewat `PaymentOrchestratorService` dengan `lockForUpdate`, sehingga tidak ada pelunasan ganda.

| Jalur | Kapan | Catatan |
|---|---|---|
| Webhook (utama) | Begitu gateway mengirim notifikasi | Paling cepat |
| Polling layar kasir | Tiap 3 detik selama popup QR terbuka | Tanya gateway maks. 1x / 5 detik |
| Polling invoice customer | Saat halaman invoice dicek (`verify_payment`) | Tanya gateway maks. 1x / 10 detik per order |
| Rekonsiliasi Midtrans | Tiap 5 menit | Tagihan PENDING > 2 menit, 24 jam terakhir, maks. 100 order |
| Rekonsiliasi DOKU | Tiap 5 menit | Sama, plus proses ulang notifikasi (maks. 30x / 48 jam → `[ALERT]`) |

### 2.2 Saat batas waktu lewat — `releaseExpiredLocks()` punya dua jalur
| Status booking | Contoh | Cek gateway sebelum dilepas? |
|---|---|---|
| `PENDING_PAYMENT` / `PENDING` | Checkout online customer | **Ya** (`withoutBookingsPaidAtGateway`): lunas → dilunasi; pending / tidak terjangkau → ditahan maks. 15 menit setelah batas bayar; expired / tertutup → langsung dilepas (tanpa menunggu jeda 3 menit) |
| `LOCKED` | Slot ditahan; **walk-in kasir yang sedang dibayar lewat QR Online** (status tidak berubah dari `LOCKED`, hanya `expires_at` diperpanjang ke batas bayar) | **Tidak.** Dilepas begitu `expires_at` lewat, kecuali sudah ada pembayaran SUCCESS. Order dibatalkan dan sesi gateway ditutup (`cancelOrderSessions`) |

`releaseExpiredLocks()` dipanggil dari banyak tempat (tugas tiap menit, halaman jadwal, hold slot, invoice customer,
check-in, import). Semua jalur memakai fungsi yang sama, jadi perilaku di atas berlaku di mana pun pemicunya.

Untuk kasus 18 Nov (hangus tepat di batas waktu): cocok dengan cabang "DOKU menjawab expired/tertutup → langsung
dilepas", **atau** dengan jalur `LOCKED` kalau order itu dibuat dari kasir. Kanalnya dipastikan di Langkah 0.

### 2.3 Notifikasi DOKU SUCCESS (`DokuNotificationProcessor::handleSuccess`)
1. Tagihan pemilik invoice dicari; tidak ketemu → `UNMATCHED` (dicoba lagi).
2. Nominal notifikasi ≠ tagihan → `REJECTED` + `[ALERT]`.
3. Dikonfirmasi ke API status DOKU:
   - `PAID` → dilunasi (atau late settlement bila order sudah batal);
   - `PENDING` / `ERROR` / `NOT_FOUND` → `RETRY`;
   - **selain itu (`CLOSED`, `EXPIRED`, …) → `contradicted()` → `IGNORED` + `[ALERT]` "cek manual". Tidak ada Refund
     PENDING, tidak ada log KRITIS di Log Aktivitas, uang tidak tercatat.**

Dugaan rantai kejadian 31 Okt: slot dilepas → sistem menutup QR di DOKU → customer sebenarnya sudah membayar → DOKU
mengirim SUCCESS terlambat → API status melaporkan `CLOSED` (karena kita yang menutup) → notifikasi diabaikan.

### 2.4 Late settlement yang tertangkap
`PaymentOrchestratorService` mencatat pembayaran, membuat **Refund PENDING** dan log KRITIS
(`payment.late_settlement_refund`). Slot tidak dikembalikan. Tidak ada refund otomatis ke gateway.

### 2.5 Masa berlaku & tampilan QR
- QR DOKU berlaku **sisa waktu bayar** (`DokuSnapService::createQris`). Bayar ulang tidak memperpanjang; sisa minimal
  untuk QR baru = **2 menit** (`MIN_RETRY_MINUTES`).
- Halaman bayar customer **wajib login**; dibuka di perangkat lain → dialihkan ke login.
- Ada tombol **"Simpan QR"**, dan QR **tetap tampil** saat hitung mundur 00:00.

## 3. Analisis

### 3.1 Dua kemungkinan "sudah bayar, booking lepas"
| | A. Gagal di DOKU | B. Sukses di DOKU, sistem telat tahu |
|---|---|---|
| Status di DOKU | Expired / gagal | **SUCCESS** |
| Uang | Dikembalikan bank / e-wallet customer (auto reversal) | **Masuk ke Club 61**, tidak kembali sendiri |
| Sistem kita (seharusnya) | Melepas slot, tanpa refund | Refund PENDING + log KRITIS |
| Sistem kita (kenyataan 31 Okt) | — | **Diabaikan** karena API status bertentangan |

### 3.2 Penyebab "QR tidak valid" (urut dari yang paling mungkin)
1. QR di-scan dari **foto layar / gambar WhatsApp** (kompresi gambar), karena link invoice tidak bisa dibuka tanpa login.
2. QR yang disimpan dibayar setelah kedaluwarsa (tombol Simpan QR; QR tetap tampil setelah 00:00).
3. QR hasil bayar ulang hanya berlaku sisa waktu (bisa 2 menit).
4. Jam server meleset (`timedatectl` belum dicek).

### 3.3 Celah
1. **Notifikasi SUCCESS yang dibantah API diabaikan** (uang bisa masuk tanpa tercatat).
2. **Slot `LOCKED` dengan QR kasir yang masih menunggu dilepas tanpa cek gateway**, lalu QR ditutup.
3. Customer & staf tidak dikabari saat late settlement; customer hanya melihat "Expired".
4. Pencatatan ulang manual (RRN sama) tidak terdeteksi → risiko uang dihitung / dikembalikan dua kali.
5. Pelepasan slot dalam kondisi gateway tidak pasti tidak tercatat.
6. Tombol "Cek Status Pembayaran ke Gateway" hanya untuk booking yang masih menunggu bayar.
7. Tidak ada pengaman refund ganda.
8. Booking tidak dipulihkan walaupun slot masih kosong.

## 4. Langkah kerja

### Langkah 0 — Investigasi (sebagian sudah dilakukan user)
Sudah: log DOKU untuk ORD-PAD-24Y3BXH8RT dan ORD-PAD-UEQOTGIOTD (lihat bagian 1).

Sisa (dijalankan user di server, tanpa perubahan kode):
- `timedatectl` → `System clock synchronized: yes`.
- Kanal kedua order: dibuat dari **checkout online** atau **kasir (QR Online)**? (menentukan jalur 2.2 yang berlaku).
- Dashboard DOKU: status invoice ORD-PAD-24Y3BXH8RT (SUCCESS / refunded).
- Log Aktivitas & Antrian Refund: tidak boleh ada refund untuk ORD-PAD-24Y3BXH8RT (sudah dicatat lewat order manual).

### Langkah 1 — Cegah "QR tidak valid"
| # | Perubahan | File |
|---|---|---|
| 1a | **Link pembayaran tanpa login** (signed URL khusus satu order, berlaku sampai batas bayar). Isinya hanya: nominal, QR / nomor VA, hitung mundur, status lunas. Tanpa data pribadi lain dan tanpa aksi apa pun selain melihat. Tombol "Bagikan link pembayaran" di halaman invoice | route + controller baru, view panel bayar, `invoice-scripts.blade.php` |
| 1b | Petunjuk di dekat QR: **scan langsung dari layar ini; jangan dari foto layar atau gambar WhatsApp**. Bila bayar dari HP yang sama, pakai link 1a | `doku-snap-panel.blade.php`, `lang/id|en` |
| 1c | Saat hitung mundur 00:00, QR ditutup lapisan "QR sudah kedaluwarsa — jangan dibayar"; tombol Simpan QR disembunyikan | `doku-snap-panel.blade.php`, `invoice-scripts.blade.php` |
| 1d | Gambar QR yang disimpan diberi teks **"Berlaku s.d. HH:MM WIB"** | `invoice-scripts.blade.php` (`downloadSnapQr`) |
| 1e | `MIN_RETRY_MINUTES` 2 → **5** (disetujui user) | `BookingTimeService` |

Keamanan 1a: tanda tangan URL Laravel (`URL::temporarySignedRoute`), hanya order online yang masih menunggu bayar, rate
limit, tidak bisa dipakai untuk membuat / mengganti sesi pembayaran (hanya menampilkan sesi yang sudah ada).

Di luar cakupan: popup Snap Midtrans (milik Midtrans). Popup QR kasir sudah tertutup otomatis saat expired.

**Test:** link bertanda tangan valid / kedaluwarsa / dimanipulasi, isi halaman tanpa data pribadi, tampilan QR expired,
teks peringatan, batas 5 menit pada bayar ulang (API & web).

### Langkah 2 — Pembayaran telat, pelepasan slot, dan pengaman refund

**2.0 — PRIORITAS PERTAMA: notifikasi SUCCESS tidak boleh diabaikan**
- Di `DokuNotificationProcessor::handleSuccess`: bila invoice milik kita, nominal cocok, dan **signature terverifikasi
  penuh**, tetapi API status bilang `CLOSED` / `EXPIRED` → **jangan `IGNORED`**. Catat sebagai **late settlement**
  (Refund PENDING + log KRITIS) dengan tanda "API status bertentangan — verifikasi di dashboard DOKU".
- Notifikasi yang signature-nya **belum ditegakkan** (VA SNAP masih mode pantau) → tidak dicatat ke buku; masuk daftar
  **"Pembayaran perlu verifikasi manual"** (super admin konfirmasi setelah cek dashboard DOKU).
- Sebelum mencatat, jalankan pengecekan duplikat 2.3. Duplikat → tidak membuat refund; kedua order ditautkan di log.
- *Diverifikasi saat membangun:* jenis notifikasi mana yang signature-nya sudah ditegakkan (QRIS non-SNAP HMAC, VA SNAP).

**2.1 — Slot `LOCKED` dengan QR kasir yang masih menunggu**
- Booking `LOCKED` yang order-nya punya tagihan gateway `PENDING` diperlakukan sama dengan `PENDING_PAYMENT`: lewat
  `withoutBookingsPaidAtGateway` (cek gateway; pending / tidak terjangkau → ditahan maks. 15 menit setelah batas bayar).
- Slot `LOCKED` biasa (tanpa tagihan gateway) tidak berubah.
- Catat ke Log Aktivitas setiap slot yang dilepas setelah masa tahan **tanpa kepastian** gateway
  ("dilepas dalam kondisi tidak pasti"). Keputusan lepas/tahan di luar kasus `LOCKED`+gateway tidak diubah.

**2.2 — Kabari staf dan customer**
- Badge jumlah di menu **Antrian Refund** + banner di Dashboard admin: "Pembayaran masuk setelah booking batal: nama, HP
  (4 digit terakhir), nominal, jadwal". (Panel admin belum memakai notifikasi lonceng Filament.)
- Halaman invoice customer untuk booking batal dengan late settlement: "Pembayaran kamu diterima setelah batas waktu. Tim
  kami akan menghubungi untuk pengembalian atau penjadwalan ulang".

**2.3 — Deteksi pembayaran dicatat dua kali**
- Saat late settlement / pemulihan: cari pembayaran lain dengan **RRN / kode referensi sama**, atau customer + jadwal +
  nominal sama (mis. order manual kasir). Ketemu → tidak membuat refund, tampilkan tautan kedua order.
- Saat kasir mengetik RRN di QRIS Static: peringatan bila RRN itu sudah tercatat di pembayaran lain.
- *Diverifikasi saat membangun:* field RRN / referensi apa yang dikirim DOKU di notifikasi & status QRIS.

**2.4 — Pengaman refund ganda (Antrian Refund)**
- Refund dari pembayaran DOKU / Midtrans dicek ke gateway sebelum **Proses**:
  - masih SUCCESS → boleh diproses;
  - REFUNDED / reversal → diblok dengan keterangan;
  - gateway tidak bisa dihubungi, atau API status bertentangan (kasus 2.0) → **diblok untuk staf; super admin boleh
    lanjut dengan konfirmasi + catatan wajib** (disetujui user).
- Hasil cek & keputusan dicatat di Log Aktivitas.

**2.5 — Bisa ditelusuri dari admin**
- Tombol **Cek Status Pembayaran ke Gateway** di Kelola Pemesanan juga untuk booking **Expired / Batal** yang pernah
  dibayar lewat gateway (maks. 30 hari). SUCCESS → late settlement lewat jalur 2.0 (termasuk cek duplikat).
  - *Diverifikasi saat membangun:* rekonsiliasi saat ini berpusat pada tagihan PENDING; tagihan yang sudah FAILED
    mungkin perlu jalur cek ulang tersendiri.

**Test:** 2.0 semua cabang (signature ditegakkan / mode pantau / duplikat), 2.1 `LOCKED`+gateway vs `LOCKED` biasa,
badge & banner, pesan invoice, peringatan RRN ganda, blok refund (staf vs super admin), tombol cek booking batal.

### Langkah 3 — Pulihkan booking otomatis bila slot masih kosong (dikerjakan terakhir)
Saat late settlement masuk, booking dihidupkan kembali sebagai LUNAS (tanpa refund) **hanya jika semua syarat terpenuhi**:
- semua jam booking itu masih kosong (jam berurutan tidak dipecah);
- jam main belum lewat;
- tanggal / jam tidak sedang ditutup (`BookingWindow`);
- booking asli tidak memakai kuota membership, voucher jam corporate, atau voucher promo;
- nominal yang masuk sama dengan tagihan;
- **customer belum punya booking / order manual lain untuk jadwal yang sama** (kasus EUDJWDH8PP) — memakai pengecekan 2.3;
- bukan kasus "API status bertentangan" yang belum diverifikasi.

Setiap pemulihan: **customer dan staf dikabari** (halaman invoice + banner admin + Log Aktivitas).
Syarat tidak terpenuhi → alur Refund PENDING.

**Test:** semua cabang syarat, duplikat order manual, pembayaran ganda, ledger satu baris, slot bentrok bersamaan (lock).

### Langkah 4 — Dashboard monitoring dengan Laravel Pulse (uji coba di sandbox — disetujui user)
Kalau user tidak cocok → dibongkar dan diganti versi buatan sendiri. RAM server lega (±7 GB tersedia).

**Pemasangan**
- `laravel/pulse ^1.11` (Livewire 4 & Laravel 12). `composer require --dry-run`: +3 package (`laravel/pulse`,
  `laravel/sentinel`, `doctrine/sql-formatter`), tidak ada package lama yang berubah.
- 3 tabel baru Pulse (`pulse_values`, `pulse_entries`, `pulse_aggregates`) — hanya menambah.

**Kartu bawaan & setelan ringan**
| Kartu | Setelan |
|---|---|
| Servers (CPU, RAM, disk) | `PULSE_SERVER_NAME` beda untuk production & sandbox (satu VPS) |
| Slow Requests | Ambang 1 detik |
| Exceptions | Bawaan |
| Slow Queries | Ambang 1 detik; hanya teks query |
| Slow Outgoing Requests | Midtrans/DOKU; URL dikelompokkan agar nomor order tidak tersimpan |
| Queues / Slow Jobs | Bawaan |
| Usage, Cache | **Dimatikan** |

Data disimpan 7 hari. Tanpa Redis.

**Kartu buatan Club 61 (bahasa Indonesia)** — dihitung tugas terjadwal, halaman hanya membaca hasil:
1. **Storage Club 61:** disk + perkiraan penuh, ukuran database + 10 tabel terbesar, ukuran folder, grafik disk 30 hari.
2. **Layanan Club 61:** scheduler terakhir jalan, antrian (menunggu / gagal), Reverb hidup/mati, peringatan `APP_DEBUG`
   atau `DOKU_SNAP_DEBUG_INBOUND` menyala di production.
3. **Pembayaran Club 61:**
   - lunas via webhook vs via polling (24 jam / 7 hari);
   - pembayaran telat masuk + status refund;
   - **notifikasi SUCCESS yang bertentangan dengan API status / perlu verifikasi manual** (dari 2.0);
   - tagihan menggantung (lewat batas, gateway pending / tidak menjawab);
   - booking dilepas dalam kondisi tidak pasti (dari 2.1);
   - dugaan pembayaran ganda (dari 2.3);
   - notifikasi DOKU retry / unmatched / exhausted, notifikasi terakhir per gateway;
   - refund dari gateway yang belum diproses.
   Nama customer tampil, HP hanya 4 digit terakhir.

**Akses & menu:** izin baru `view_server_health` (bawaan hanya super admin; lainnya 403). Menu **Monitoring Sistem** di
sidebar membuka `/pulse` di tab baru (tampilan Pulse bahasa Inggris, login sama).

**Di server:** `composer install` → `php artisan migrate` → `php artisan config:cache`; program Supervisor
`php artisan pulse:check`; `PULSE_SERVER_NAME` di `.env`; tiap deploy `php artisan pulse:restart`.

**Uji coba:** lokal → sandbox (beberapa hari) → production.

**Membalik:** `composer remove laravel/pulse`, hapus file, migration baru untuk drop 3 tabel Pulse, matikan Supervisor.
Logika kartu Club 61 dipakai ulang untuk versi buatan sendiri.

**Test:** akses super admin vs role lain, kartu tampil, tugas penghitung; query diuji read-only ke MySQL lokal.

### Langkah 4b — Kartu "Keamanan Server Club 61" (MENUNGGU KONFIRMASI PM, belum dikerjakan)
Latar: *Laporan Temuan Keamanan Server Club 61 (mandau-163)*, 28 Nov 2026 — brute force SSH ±193 ribu percobaan / bulan ke
root, login root dengan password diizinkan, akun root dipakai bersama, update otomatis tidak jalan, production & sandbox
satu user PHP-FPM. Kartu ini **hanya memantau** (tidak memblokir); pengamanan tetap lewat langkah di laporan itu
(password root kuat, fail2ban, SSH key, akun per orang, unattended-upgrades, pisah sandbox/production).

| Isi kartu | Temuan | Sumber data |
|---|---|---|
| Percobaan login SSH gagal (24 jam / 7 hari) + 5 IP penyerang teratas | T-01 | skrip root |
| Login SSH berhasil (IP, user, jam); IP di luar daftar dikenal ditandai merah | T-01, T-02 | skrip root |
| Status sshd: login root / login password boleh atau tidak | T-01 | skrip root |
| fail2ban jalan + jumlah IP diblokir | T-01 | skrip root |
| Update keamanan Ubuntu tertunda + perlu restart | T-03, T-05 | langsung |
| unattended-upgrades aktif | T-03 | langsung |
| Versi PHP / nginx / Laravel / Livewire + `composer audit` | T-04, T-06, T-09 | langsung |
| Login gagal ke aplikasi Club 61 | — | langsung |

- Log SSH & `sshd -T` hanya bisa dibaca root → skrip kecil dijalankan cron root tiap 5 menit, menulis **ringkasan** ke satu
  file JSON yang dibaca kartu. Aplikasi tetap tanpa akses root. Skrip belum dipasang → bagian SSH tampil "Belum dipasang".
- Daftar IP dikenal (kantor / tim) di `.env`.
- Batasan: tidak ada notifikasi (perlu email/WA terpisah); kalau server sudah dibobol root, file ringkasan bisa dipalsukan.
- Cek kode 28 Nov: `#[Authorize]` Livewire tidak dipakai di `app/`, `resources/`, `routes/` maupun `vendor/` (termasuk
  Filament) → T-09 tidak bisa dieksploitasi; update Livewire 4.4.8 tetap disarankan (perlu persetujuan, naik versi kecil).

## 5. Aturan yang berlaku untuk semua langkah
- Tidak membaca `.env` / rahasia; larangan ini ditulis di setiap prompt subagent.
- Test hanya di SQLite `:memory:`; query GROUP BY / `information_schema` dicek read-only ke MySQL lokal.
- Migration hanya menambah; dibahas dulu.
- Tidak di-commit oleh Claude.
- Teks admin bahasa Indonesia formal; pesan customer sederhana.

## 6. Keputusan user (22 Nov)
| # | Pertanyaan | Jawaban |
|---|---|---|
| 1 | Nomor order | Kasus 1: ORD-PAD-24Y3BXH8RT (31 Okt) + order manual ORD-PAD-EUDJWDH8PP. Kasus 2: ORD-PAD-UEQOTGIOTD (18 Nov) |
| 2 | Uang kasus 1 | Sudah dicatat lewat order manual EUDJWDH8PP — **jangan direfund lagi** |
| 3 | `MIN_RETRY_MINUTES` 5 menit | Setuju |
| 4 | Gateway tidak terjangkau saat refund | Diblok untuk staf; super admin boleh lanjut dengan konfirmasi + catatan wajib |
| 5 | Pemulihan otomatis | Setuju, + syarat tidak ada booking / order manual lain untuk jadwal sama, + kabari customer & staf |
| 6 | Pulse di sandbox | Setuju |

**Masih terbuka:**
- 2.0: notifikasi SUCCESS bertanda tangan tapi dibantah API — usulan di atas (dicatat sebagai late settlement bila
  signature ditegakkan; selain itu verifikasi manual). Setuju?
- Kanal ORD-PAD-UEQOTGIOTD dan ORD-PAD-24Y3BXH8RT (online atau kasir) — dari Langkah 0.

## 7. Operasional server & dependency (di luar kode pembayaran)

**Server (dikerjakan user)**
| Item | Catatan |
|---|---|
| Matikan `DOKU_SNAP_DEBUG_INBOUND` di production | Masih menyala. Kartu Layanan (Langkah 4) akan memberi peringatan bila menyala |
| `memory_limit` PHP-FPM kembali ke 128M | PDF Riwayat Transaksi sudah dibuat **di browser (tinypdf)** sejak commit `4460631`, tidak lagi memakai memori server. **Cpdf tidak diperlukan** — catatan "export PDF pakai Cpdf" sudah digantikan keputusan 14 Nov. Kembalikan setelah `4460631` ter-deploy dan export PDF dites sekali |
| Batas memori CLI `-1` → 1024M | Supaya tugas terjadwal yang bocor memori tidak menghabiskan RAM VPS |
| Aktifkan satu VA sebagai cadangan QRIS | Lewat menu Metode Pembayaran Online (tanpa kode) |
| Batas bayar 15 menit | Lewat Pengaturan Waktu Booking (tanpa kode); berlaku untuk booking baru |
| `timedatectl` | Lihat Langkah 0 |

**`composer audit` (dijalankan 22 Nov) — 9 package, tidak ada yang kritis untuk project ini**
| Package (terpasang) | Masalah | Dampak ke project | Perbaikan |
|---|---|---|---|
| filament/filament 5.7.8 | Aksi MFA tanpa konfirmasi password (medium) | MFA tidak dipakai | ≥ 5.8.3 |
| livewire/livewire 4.4.3 | Bypass `#[Authorize]` / `#[Json]` (medium) | Atribut tidak dipakai | ≥ 4.4.8 |
| laravel/framework 12.69.3 | Output injection di Concurrency (low) | Kecil | ≥ 12.71 |
| symfony/http-foundation, http-kernel, routing 7.4.20 | Host / proxy / path bypass | `trustProxies` sudah dibatasi `127.0.0.1`/`::1`; reset password sudah menahan host palsu; sisanya kecil | ≥ 7.4.23 |
| symfony/mime 7.4.19 | Email header injection (medium) | Kecil (email sistem, nilai dari server) | ≥ 7.4.22 |
| symfony/html-sanitizer, yaml 7.4.18 | Sanitizer / parser | Tidak dipakai langsung (mungkin lewat Filament) | ≥ 7.4.23 |

Usulan: satu batch **update minor/patch** (tanpa naik versi besar) `filament/filament livewire/livewire
laravel/framework symfony/*` → full test suite → sandbox → production. Butuh persetujuan user karena mengubah dependency
production. Bisa dikerjakan terpisah dari langkah pembayaran.

## 8. Di luar cakupan (dicatat untuk nanti)
- Backup otomatis harian + salin ke luar VPS; restore lewat perintah SSH + uji restore rutin.
- Traffic pengunjung (Cloudflare Web Analytics / GA4 / Umami).
- Peringatan email (disk, error API, scheduler, Reverb, late settlement).
