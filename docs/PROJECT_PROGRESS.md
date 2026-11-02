# Roadmap & Progress Checklist: Club 61 Central Backend System

Dokumen pelacak progres (Single Source of Truth). Status di bawah ini hasil audit langsung ke kode (bukan ke PRD) per **30 September 2026**. Seluruh teks disajikan bersih tanpa ikon atau emoji.

### Legenda

| Tanda | Arti |
| :--- | :--- |
| `[x]` | Selesai, sudah pakai data asli (database / service / payment gateway) |
| `[~]` | Sebagian jalan, masih ada bagian yang kurang |
| `[ ]` | Belum dikerjakan |
| **DUMMY** | Halaman / fitur sudah tampil, tapi isinya data palsu (hardcoded) atau tombolnya tidak melakukan apa-apa |
| **SKEMA SAJA** | Tabel database & model sudah ada, tapi belum dipakai kode mana pun |
| **KRITIS** | Bug yang bisa bikin rugi uang / celah keamanan, wajib dibereskan sebelum live |

---

## Ringkasan Status Global

| Modul | Deskripsi | Status | Progress |
| :--- | :--- | :--- | :---: |
| 01 | Core Auth & Multi-Door Login | Jalan, reset sandi via email belum | 85% |
| 02 | Padel Court Booking Engine | Selesai | 97% |
| 03 | Wellness & Sauna | API booking jalan, pembayaran & halaman belum | 35% |
| 04 | Salon & Beauty | Katalog saja | 15% |
| 05 | Membership (Padel/Gym/Wellness) & Gym Pass | Jalan, pembayaran online perlu dirapikan | 75% |
| 06 | F&B Cafe, Table QR & Kitchen KDS | POS Kasir F&B jalan, KDS DUMMY, self-order belum | 45% |
| 07 | Merchandise Retail | Katalog saja | 10% |
| 08 | POS Frontdesk & Split Bill | POS Padel & F&B jalan, split bill belum | 55% |
| 09 | Role & Permission Matrix | Selesai | 100% |
| 10 | Unified Payment Gateway (Midtrans) | Jalan + rekonsiliasi, handler baru Padel & Membership | 80% |
| 11 | Walk-In Offline Booking (POS Padel) | Selesai | 100% |
| 12 | Sponsor / Corporate Account | Jalan | 90% |
| 13 | Multi-Branch Tenancy | PRD saja | 0% |
| 14 | Company Profile / Konten Website | Selesai | 100% |
| 15 | Manajemen Menu F&B | Selesai | 100% |
| 16 | Activity / Audit Log | Jalan (panel superadmin, transaksi, perubahan data, login) | 85% |
| 17 | Laporan Keuangan & Riwayat Transaksi Terpadu | Buku Transaksi + Antrian Refund + dashboard Analytics dari buku jalan; MDR & rekonsiliasi bank (Fase 4) menyusul | 90% |
| 18 | Pengaturan Invoice / Struk Terpusat | Belum ada | 0% |
| 19 | Realtime (Laravel Reverb) | Belum terpasang, masih polling | 0% |
| 20 | Halaman Admin Pendukung (Dashboard, Club, Karyawan, Turnamen, Marketing) | DUMMY semua | 0% |
| 21 | Kebijakan Refund, No-Show & Pembayaran Bermasalah | PRD draft, menunggu keputusan PM | 0% |

**Automated test suite:** 634 passed (2748 assertions) — termasuk regresi audit "bom waktu" 1 Okt 2026 (`tests/Feature/Padel/PaymentTimeBombRegressionTest.php`).

---

## PRIORITAS SEBELUM LIVE

### KRITIS (bisa bikin rugi uang)
- [x] **Upgrade Laravel 11.56 → 12.69.3** (4 Okt 2026): Laravel 11 tidak menerima patch keamanan lagi; celah *high* "CRLF injection pada aturan validasi `email`" (GHSA-5vg9-5847-vvmq) dan *Temporary Signed URL Path Confusion* hanya diperbaiki di 12.x. Ikut naik: `league/commonmark` 2.10.3 (DoS tabel Markdown), PHPUnit 11 (dev). `composer audit`: 0 advisory. Deploy: `composer install`.
- [x] **Alamat di struk salah** (DIPERBAIKI 4 Okt 2026): struk POS Walk-In & Z-Report menulis "Jl. Karang Tengah Raya No. 61, Lebak Bulus" secara hardcode. Sekarang semua struk / invoice salinan admin memakai alamat dari menu **Konten Website** (`CompanyProfileSetting::receiptAddress()`).
- [x] **KRITIS: Midtrans gagal = dianggap lunas** (DIPERBAIKI 30 Sep 2026). Dulu `MidtransService::createSnapTransaction()` menangkap semua error Midtrans (key salah, jaringan putus, request ditolak) atau server key kosong, lalu mengembalikan token palsu `is_mock=true`, sehingga booking padel langsung `PAID` dan membership online langsung aktif gratis.
  - Sekarang fail-closed: token mock hanya di environment `local` (tanpa key) / `testing`. Selain itu checkout ditolak HTTP 503 (`PaymentGatewayUnavailableException`), transaksi DB di-rollback, slot tetap `LOCKED` supaya customer bisa coba lagi, dan error dicatat `[ALERT]` di log.
  - Test: `tests/Feature/Payment/MidtransFailClosedTest.php` (7 test).
  - Catatan: driver `mock` via config `PAYMENT_DRIVER=mock` masih bisa dipakai di server non-production. Pastikan server sandbox / production memakai `PAYMENT_DRIVER=midtrans`.
- [x] Client key cadangan `'SB-Mid-client-demo-61'` dihapus (4 Okt 2026): `snap.js` hanya dimuat dengan `MIDTRANS_CLIENT_KEY` asli (`customer/partials/midtrans-snap.blade.php`); tanpa key, customer diarahkan ke halaman bayar Midtrans (`redirect_url`). Checkout padel dulu menampilkan "pembayaran berhasil" kalau popup Snap tidak termuat — sekarang diarahkan ke halaman bayar.

### URGENT (permintaan PM)
- [x] Modul 16: Activity / Audit Log untuk superadmin.
- [x] Modul 17: Laporan keuangan per modul + tiap transaksi bisa dilacak detail & invoice-nya — Buku Transaksi & Antrian Refund (Fase 2) dan dashboard Analytics dari buku (Fase 3) jalan (4 Okt 2026). Fase 4 (MDR Midtrans, rekonsiliasi mutasi bank) menyusul.
  - [x] Analytics & Keuangan membaca `ledger_entries` (F&B ikut terhitung, angka = Buku Transaksi); rincian per kategori / sumber / metode bayar (klik baris → Buku Transaksi tersaring lewat `?periode=&kategori=&sumber=&metode=`); grafik tren harian (per bulan untuk rentang > 62 hari); okupansi dari jam buka lapangan aktif (dulu tetap 4 × 18 jam). Test: `tests/Feature/Finance/AnalyticsLedgerTest.php`.
  - [x] Dashboard admin memakai data asli (4 Okt 2026) — dulu seluruh isinya contoh mati ("Rp 50.272.597", "132 Bookings", booking "PXDL"). Isi: uang masuk hari ini vs kemarin & bulan ini (dari Buku Transaksi, hanya untuk yang boleh membuka Analytics), booking & okupansi hari ini, customer baru bulan ini vs bulan lalu, member aktif, daftar "Perlu Ditindaklanjuti" (tagihan kasir, refund, booking menunggu bayar), grafik uang masuk 7/30/90 hari, jadwal lapangan hari ini + pencarian. Okupansi Dashboard & Analytics satu rumus (`App\Services\Padel\CourtOccupancy`). Test: `tests/Feature/Finance/AdminDashboardTest.php`.
- [ ] Modul 18: Panel pengaturan invoice untuk semua modul.
- [ ] Rapikan pembayaran membership online di portal customer.
  - [x] Master Fasilitas Membership (menu **Fasilitas Membership**): tambah fasilitas baru (mode check-in / info saja), nama & deskripsi benefit diatur admin; paket punya deskripsi + daftar privilege + catatan per benefit. Halaman membership, My Club, teaser depan, POS Jual Membership & API `/membership/plans` (`benefit_cards`) tidak lagi memakai teks dummy (2 Okt 2026).
  - [x] Check-in generik `POST /api/v1/membership/checkin`; check-in Gym unlimited dulu selalu gagal, paket "diskon saja" dulu bisa check-in gratis — keduanya diperbaiki.
  - [x] Lanjutkan bayar membership PENDING_PAYMENT (4 Okt 2026): tombol "Continue Payment" + ganti metode + "Cancel This Order" di halaman Invoice; checkout paket sama saat masih pending = lanjut bayar order yang sama (tidak ada order dobel), paket beda = ditolak 409 dan diarahkan ke pesanan lama. Notifikasi expire Midtrans ikut membatalkan kartu PENDING; pesanan online yang ditinggal > 24 jam dibatalkan otomatis oleh `membership:sync-expired`. API: `POST /api/v1/membership/purchases/{id}/pay` & `/cancel`, flag `can_pay_online` di `my-purchases`. Test: `tests/Feature/Membership/MembershipOnlinePaymentTest.php`.
- [~] Reset sandi via email: kode, tampilan & email Club 61 siap (4 Okt 2026); tinggal isi SMTP di `.env` server lalu tes `php artisan mail:test`.
- [ ] Reverb untuk update tanpa refresh.

---

## MODUL 01: CORE AUTHENTICATION & MULTI-DOOR ACCESS

### Status: 85%
- [x] Autentikasi multi-guard (Web Session & API Sanctum Bearer Token).
- [x] Login dengan username / email, shortcut `admin`.
- [x] Redirect otomatis berdasarkan `home_route` role (`/admin`, `/pos`, `/kitchen`, `/admin/book-offline-court`, `/dashboard`).
- [x] Staf diblokir dari halaman portal customer (middleware `CustomerPortalOnly`).
- [x] Staf tanpa akses Dashboard diarahkan ke halaman admin pertama yang boleh dibuka.
- [x] Reset sandi: controller jalan, pesan generik (tidak membocorkan email terdaftar), token Sanctum dicabut setelah reset.
- [x] Sesi web di perangkat lain otomatis keluar setelah password diganti / direset (`AuthenticateSession` di grup web; dulu hanya panel admin). Test: `tests/Feature/Auth/SessionInvalidationTest.php`.
- [ ] **Reset sandi via email belum sampai ke user.** `MAIL_MAILER=log`, jadi link reset cuma masuk ke `storage/logs`. Perlu:
  - [ ] Setting SMTP (Gmail App Password / Mailtrap / Resend / SES) di `.env` server.
  - [x] Template email reset bertema Club 61, bahasa Indonesia (`App\Notifications\Auth\ResetPasswordNotification`).
  - [x] Halaman Lupa / Atur Ulang Kata Sandi bergaya Club 61; bisa pakai email ATAU nomor HP.
  - [x] Link reset memakai `APP_URL` (dulu dari header Host — bisa dibelokkan ke domain penyerang karena `trustProxies('*')`).
  - [x] Akun walk-in dengan email placeholder (`*@walkin.club61.internal`, `mbr_*@club61.id`, `corp_*@club61.id`) tidak dikirimi email; halaman mengarahkan ke frontdesk.
  - [x] Batas 5 permintaan/menit per IP; SMTP gagal → pesan jelas + Log Aktivitas KRITIS `auth.password_reset_mail_failed`.
  - [x] Perintah cek SMTP: `php artisan mail:test alamat@email.com`.
  - [ ] Pengirim `MAIL_FROM_ADDRESS` masih `hello@example.com` — isi di `.env` server.
- [ ] **Verifikasi email tidak aktif.** Model `User` tidak mengimplementasikan `MustVerifyEmail`, jadi middleware `verified` di route portal tidak berfungsi.

---

## MODUL 02: PADEL COURT BOOKING ENGINE

### Status: 95%
- [x] Matriks jadwal real-time timezone-aware, tarif Reguler vs Prime Time.
- [x] Jam yang sudah lewat disembunyikan / ditolak (customer, POS walk-in, monitoring).
- [x] Privasi identitas pemain di jadwal publik.
- [x] Katalog sewa alat + pengurangan stok.
- [x] Two-tier concurrency lock (cache lock + `SELECT ... FOR UPDATE`), atomic multi-slot.
- [x] Auto-expiry slot yang tidak dibayar (scheduler tiap 1 menit) dengan anti-premature guard.
- [x] Countdown timer di `/cart` dan `/checkout`.
- [x] Checkout idempotent (`X-Idempotency-Key`).
- [x] Consolidated invoice multi-jam (1 order, 1 boarding pass).
- [x] Pelunasan selisih reschedule (delta) via Midtrans maupun kasir.
- [x] Admin: pindah jadwal, quick settle, batalkan & refund, cek status bayar ke Midtrans.
- [x] Reschedule & selisih bayar (diperbaiki 30 Sep 2026, `tests/Feature/Padel/ReschedulePaymentTest.php`):
  - [x] Benefit membership (kuota / diskon %) & voucher sponsor ikut pindah ke jadwal baru — tidak ada lagi tagih ganda.
  - [x] Modal menampilkan rincian lengkap (tarif, benefit, selisih, pajak, biaya layanan, total) dengan rumus yang sama persis dengan yang ditagih.
  - [x] Bayar di frontdesk wajib shift aktif + bukti bayar (RRN QRIS / slip EDC / referensi transfer), 1 bukti hanya untuk 1 transaksi, uang masuk rekap shift.
  - [x] "Kirim tagihan ke customer": bayar via Midtrans di invoice atau di kasir; rekonsiliasi Midtrans ikut mengecek tagihan selisih.
  - [x] Pindah ke jam lebih murah: selisih HANGUS (kebijakan PM), tercatat di invoice & log, tanpa refund fiktif.
  - [x] Invoice customer menampilkan selisih reschedule (lunas / belum) dan selisih yang hangus.
  - [x] Slot hasil reschedule tidak bisa di-double-book walau cache kunci hilang.
  - [x] Refund dibatasi uang yang benar-benar masuk; opsi "Saldo Deposit Member" (fitur tidak ada) dihapus.
- [x] Check-in via scan QR / ketik kode booking (single-use, toleransi double scan, tolak kalau ada delta belum lunas).
- [x] Invoice customer: pajak, biaya layanan, diskon membership & voucher corporate tampil benar (kartu tiket + PNG e-ticket).
- [x] Arsitektur service modular (5 traits).
- [~] Coach padel: kolom `coach_id` & `coach_fee` ada di skema, **belum ada UI pemilihan pelatih**.
- [x] Checkout ditolak (bukan dianggap lunas) kalau Midtrans error.
- [x] QR e-tiket & kartu member dibuat di browser dengan `public/js/qrcode.min.js` (4 Okt 2026); `api.qrserver.com` (ikut menerima kode akses gate) & CDN qrcodejs tidak dipakai lagi.
- [x] Placeholder `'CLUB61-DEMO'` / `'CLUB61-PASS'` dihapus: tiket tanpa kode QR menampilkan "QR belum tersedia". Halaman `/membership` tanpa paket aktif tidak lagi error 500. Test: `tests/Feature/Payment/CustomerPaymentScriptsTest.php`.

---

## MODUL 03: WELLNESS (SAUNA)

### Status: 35%
- [x] Skema: `wellness_facilities`, `wellness_slots`, `wellness_bookings`, `wellness_waitlists`.
- [x] Seeder fasilitas (Finnish Sauna — Club 61 tidak punya Ice Bath; data Ice Bath lama dibersihkan migration `2026_10_04_100001`).
- [x] API: `GET facilities`, `GET slots`, `POST book`, `POST cancel` (`routes/api/wellness.php`).
- [x] `WellnessBookingService`: lock kuota, potong kuota / diskon membership.
- [ ] **Booking berbayar tidak bisa dibayar:** status tetap `PENDING`, tidak membuat Order / Payment, tidak ada fulfillment handler di `PaymentFulfillmentRegistry`.
- [ ] Waitlist otomatis kalau sesi penuh (tabel ada, logika belum).
- [ ] Halaman customer `/wellness`.
- [ ] **POS Wellness** untuk kasir / resepsionis.
- [ ] Halaman admin **Kelola Club** masih **DUMMY** (4 kartu fasilitas hardcoded, tombol "Jadwal Maintenance" tidak berfungsi).

---

## MODUL 04: SALON & BEAUTY TREATMENT

### Status: 15%
- [x] Skema: `salon_services`, `salon_appointments`, `salon_appointment_services`.
- [x] Seeder layanan & stylist.
- [x] API katalog `GET /api/v1/salon/services`.
- [ ] API daftar stylist.
- [ ] Algoritma duration stacking & anti-overlap stylist.
- [ ] API appointment + pembayaran.
- [ ] Halaman customer `/salon` dan POS salon.

---

## MODUL 05: MEMBERSHIP & GYM PASS

### Status: 75%
- [x] Skema membership (plan, user membership, benefit, saldo kuota, usage log immutable).
- [x] Resource admin **Membership Plan** (CRUD).
- [x] Halaman admin **Jual Membership** (penjualan di kasir, pembayaran & aktivasi).
- [x] Halaman admin **Customer & Member VIP** (read-only, analisa kebiasaan dari data asli).
- [x] API: plans, checkout, my-membership, my-purchases, history, `checkin-gym`.
- [x] Fulfillment handler membership terdaftar (aktivasi otomatis setelah webhook lunas).
- [x] Diskon / kuota membership terpakai di booking padel & wellness.
- [x] Scheduler `membership:sync-expired` harian.
- [x] API katalog paket gym `GET /api/v1/gym/packages` (filter plan dengan benefit GYM).
- [~] **Pembayaran membership online di portal customer** (`customer/membership.blade.php`):
  - [x] Order, pajak & biaya, Midtrans Snap, webhook, aktivasi otomatis sudah asli.
  - [x] Midtrans error tidak lagi mengaktifkan membership gratis.
  - [ ] Rincian tagihan menampilkan harga plan sebagai total, padahal server menambah pajak & biaya admin, jadi nominal di layar beda dengan yang ditagih (`membership.blade.php:546-547`).
  - [ ] **DUMMY:** teks benefit per plan masih hardcoded per tipe plan (`membership.blade.php:~510-543`), bukan dari data benefit plan.
  - [ ] Pilihan metode bayar cuma 2 radio; nilai `MIDTRANS_SNAP` tidak dikenali sehingga semua metode muncul di Snap.
  - [ ] Upgrade / beli plan lain saat masih punya plan aktif belum ditangani.
  - [ ] Membership berstatus `PENDING_PAYMENT` tidak tampil di My Club (customer tidak bisa lanjut bayar).
- [ ] Kartu member digital / QR gate pass gym di portal customer.
- [ ] POS Gym (check-in & jual paket di meja resepsionis).

---

## MODUL 06: CAFE F&B, TABLE QR & KITCHEN KDS

### Status: 45%
- [x] Skema: kategori, menu, modifier, bahan baku, BOM resep, `table_qr_codes`, `kitchen_tickets`.
- [x] Halaman admin **Kelola Menu F&B** (CRUD menu, kategori, modifier, upload gambar aman).
- [x] **POS Kasir F&B** (`/pos`): keranjang, modifier, meja / take away, nama pelanggan, nomor antrian, buka / tutup shift + rekonsiliasi, struk popup, tab Riwayat Transaksi.
- [x] API katalog `GET /api/v1/fnb/menu`.
- [ ] **Kitchen Display System `/kitchen` masih DUMMY:** tiket `#TKT-041` hardcoded, tombol cuma JavaScript lokal, tabel `kitchen_tickets` tidak pernah dibaca / diisi. Pesanan dari POS F&B belum masuk ke dapur.
- [ ] **Pengurangan stok bahan baku (BOM) saat order PAID** (SKEMA SAJA).
- [ ] **Table QR self-order** (SKEMA SAJA): meja di POS masih diketik bebas.
- [ ] **Halaman customer `/cafe` masih kosong** (file 1 baris, tidak ada route).
- [ ] API pemesanan F&B untuk customer / aplikasi mobile.
- [ ] Fulfillment handler F&B di `PaymentFulfillmentRegistry` (dibutuhkan kalau F&B dibayar online).

---

## MODUL 07: MERCHANDISE RETAIL

### Status: 10%
- [x] Skema: `merch_products`, `merch_variants`, `merch_stocks`, `merch_stock_mutations`.
- [x] Seeder produk & varian ukuran.
- [x] API katalog `GET /api/v1/merch/products`.
- [ ] Halaman admin kelola produk & stok.
- [ ] **POS Merchandise** (jual di kasir + mutasi stok).
- [ ] Checkout online + fulfillment handler.
- [ ] Halaman customer `/merch`.

---

## MODUL 08: POS FRONTDESK & SPLIT BILL

### Status: 55%
- [x] Skema: `orders`, `order_items`, `bill_splits`, `bill_split_items`, `payments`, `vouchers`.
- [x] POS Walk-In Padel (`/admin/book-offline-court`) dan POS Kasir F&B (`/pos`).
- [x] Shift kasir (`pos_cashier_shifts`) dengan rekonsiliasi setoran per metode bayar.
- [x] Role **Resepsionis** (POS walk-in + scan / input kode booking).
- [~] **Tutup shift Fase 1 (22 Okt 2026, POS Padel & F&B sama — `ShiftClosingService` + trait `ClosesPosShift`):**
  - Blind: kasir mengetik angka struk settlement / Audit Report EDC & aplikasi QRIS tanpa melihat angka sistem. Kalau selisih, angkanya dibekukan.
  - EDC per mesin per loket: dihitung sejak settlement terakhir (`pos_edc_settlements`), jadi "Settle sekarang" & "Belum settle (ganti shift)" sama-sama bisa dicocokkan.
  - Toleransi Rp0. Selisih → catatan wajib → status Perlu Review → diputuskan pemegang izin `approve_shift_closing` (default admin; penutup shift tidak mereview sendiri, kecuali super_admin).
  - Koreksi metode bayar salah klik oleh admin (`PaymentMethodCorrection`): nominal tetap, Buku Transaksi tetap satu baris dengan tanda "Dikoreksi siapa / kapan (asal: …)".
  - Baris form tutup shift = hanya metode yang ada transaksinya di shift itu (+ batch EDC sejak settlement terakhir; tiap mesin debit & kredit). Salah klik dikoreksi admin saat itu juga — Review Shift Kasir menampilkan shift "Sedang buka". Kalau baru ketahuan saat tutup: baris pindahan tampil "tidak diketik" di detail, admin putuskan; baris yang sudah diketik kasir tetap dihitung walau transaksinya dipindah.
  - Cetak ulang Z-Report dari Detail Shift Kasir (isi = `pos.partials.z-report-body` yang sama dengan POS, bertanda CETAK ULANG + reviewer, log `shift.z_report_reprinted`).
  - Analytics: kanal dipisah POS Frontdesk Padel / POS Kasir F&B / kasir lainnya / Payment Gateway Online (rincian per gateway DOKU / Midtrans dari pembayaran asal); teks "Midtrans" di Analytics diganti.
- **DOKU SNAP VA Mandiri / BRI / BNI / BSI (22 Okt 2026, belum diuji sandbox):** Partner Service ID (BIN) + prefix customer no. per bank diisi admin di form Edit metode VA-nya, bagian "DOKU SNAP" (tampil hanya saat DOKU dipakai di Gateway Pembayaran; ubah butuh izin `manage_payment_gateway`, log KRITIS), disimpan di `payment_gateway_settings.doku_snap_va` (migrasi 2026_10_22_120001). Bank yang kosong tetap DOKU Checkout; kolom "Jalur DOKU" (saat DOKU dipakai) menunjukkan SNAP / Checkout. BSI: metode bawaan baru `BSI_SYARIAH_VA` (DOKU saja, nonaktif; kanal SNAP `VIRTUAL_ACCOUNT_BSI`), "VA Bank Lain" di DOKU jadi Permata saja. Invoice: langkah bayar umum untuk VA selain BCA. POS: SNAP otomatis kalau "QR otomatis di kasir" = DOKU.
  - Pengingat di POS: shift dibuka sejak kemarin / > 12 jam, EDC belum di-settle menjelang 22.00, EDC kemarin belum di-settle saat buka shift.
  - Halaman Keuangan → **Review Shift Kasir** + detail shift. Z-Report bisa dicetak di kedua loket.
  - **Perbaikan shift lupa ditutup** (1 Nov 2026, tanpa migrasi; kasus production shift dibuka 30 Okt belum ditutup):
    Cetak Rekap (Rekap Penjualan Harian / X-Report) sekarang per tanggal kalender — shift yang sempat buka di tanggal
    itu (termasuk shift hari sebelumnya yang belum ditutup), hanya pembayaran yang dibayar di tanggal itu, periode
    00:00–23:59 bila shift melewati pergantian hari; struk menulis "Shift sejak dd/mm HH:MM — BELUM DITUTUP". Dulu
    hanya shift yang DIBUKA di tanggal itu → tanggal berikutnya "Belum Ada Shift" dan tanggal buka ikut menghitung semua
    hari. Shift yang masih buka sejak sebelum hari ini juga dicetak bagian **SHIFT BELUM DITUTUP**: total seluruh
    shift sejak dibuka s/d sekarang per metode (kelompok sama dengan form Tutup Shift), untuk dicocokkan dengan jumlah
    struk settlement EDC / mutasi QRIS saat menutup shift. Shift normal (buka & tutup di hari yang sama) angkanya tidak berubah. Rekap Shift Kasir: kolom Penjualan
    shift yang masih buka = total berjalan (dulu Rp 0 sampai ditutup). Data tidak diubah — shift lama cukup ditutup
    biasa (angka settlement EDC / mutasi QRIS seluruh periode dijumlahkan), selisih → Perlu Review.
- [ ] **Riwayat shift kasir Fase 2:** filter lengkap, Excel per shift, cetak ulang Z dari panel, kasir melihat shift miliknya. **Fase 3:** Tutup Harian per tanggal (dana masuk rekening H+1, MDR) + Excel per hari + kolom Shift di Excel Buku Transaksi.
- [ ] **Split bill** EQUAL & BY_ITEM (SKEMA SAJA).
- [ ] Keranjang terpadu (padel + kafe + raket + merch dalam 1 struk).
- [ ] POS Wellness, Gym, Merchandise (lihat modul masing-masing).
- [ ] Alamat di header struk POS Padel masih hardcoded "Jl. Karang Tengah Raya No. 61" (`book-offline-court.blade.php:1060,1527`). Akan diganti oleh Modul 18.

---

## MODUL 09: ENTERPRISE ROLE & PERMISSION MATRIX

### Status: 100%
- [x] Spatie Permission + Filament Shield, pivot ULID `char(26)`.
- [x] `Club61PermissionMatrix` terpusat, preset permission per role (super_admin, admin, cashier, receptionist, kitchen, customer).
- [x] Daftar izin "backdoor" (refund, reschedule, pajak, harga lapangan, kelola role, hapus user, dll.) disembunyikan dari role admin.
- [x] Resource **Role** kustom (matriks centang per modul, home route).
- [x] `HasPageShield` di semua halaman admin; tombol sensitif dicek di server, bukan cuma disembunyikan.
- [x] Super admin tidak bisa diedit / dihapus oleh non-super-admin.
- [x] Seeder akun demo (`DemoAccessSeeder`) tanpa menimpa edit role manual.

---

## MODUL 10: UNIFIED PAYMENT GATEWAY

### Status: 80%
- [x] `PaymentManager` + driver Midtrans & Mock (mock diblokir di production).
- [x] Webhook Midtrans dengan validasi signature SHA512.
- [x] `PaymentOrchestratorService::markOrderAsPaid` idempoten + fulfillment registry.
- [x] **Rekonsiliasi Midtrans** tanpa webhook: scheduler `payment:reconcile-midtrans` tiap 5 menit, cek saat polling invoice, tombol cek manual untuk staf, auto-tutup order yang ditinggal.
- [x] Pembayaran telat setelah expired tercatat PAID + refund PENDING.
- [~] Fulfillment handler baru untuk **Padel** dan **Membership**. Belum untuk Wellness, Gym, Merch, F&B online.
- [ ] **Xendit belum ada.** Dokumen lama menyebut Xendit sudah jadi, ternyata drivernya tidak ada di kode.
- [x] Fail-closed saat Midtrans error / server key kosong (HTTP 503, tidak ada lagi fallback mock di server).
- [ ] Endpoint `/api/v1/payments/simulate` (khusus local / testing) menandai PAID tanpa menjalankan fulfillment.

---

## MODUL 11: WALK-IN OFFLINE BOOKING (POS PADEL)

### Status: 100%
- [x] Pilih lapangan & jam di kasir, jam lewat disembunyikan.
- [x] Pembayaran tunai / QRIS / transfer / EDC, metode tidak dikenal ditolak.
- [x] Pajak & biaya layanan dari Pengaturan Biaya & Pajak.
- [x] Shift kasir + ringkasan tutup shift.
- [x] Struk cetak.
- [x] **Tambah Alat ke Booking** (1 Nov 2026, tanpa migrasi; izin `add_booking_equipment`, tidak masuk role bawaan —
  dicentang manual oleh super admin): tombol "+ Tambah Alat ke Booking" di baris tanggal POS Walk-In. Alur kasir yang
  sama tanpa memilih lapangan: 1 pilih booking hari ini / ke depan — yang sudah lewat tidak bisa (ketuk blok booking di jadwal / cari kode, nama, HP; hasil cari menampilkan tanggal), customer
  otomatis dari booking, 3 pilih alat, 4 layar pembayaran yang sama (QRIS otomatis payment gateway / QRIS statis / EDC). QR otomatis: order alat menunggu sampai lunas — batal / kedaluwarsa → order batal & stok bola kembali; alat dari order yang belum lunas tidak dihitung saat check-in, retur, maupun di Kelola Pemesanan.
  - Booking lunas / sudah check-in → transaksi baru (order WALK_IN berisi alat saja) masuk shift yang buka, struk
    "Tambahan Alat untuk Booking", Riwayat Transaksi jenis "Tambahan Alat". Order & pembayaran booking lama tidak diubah.
  - Sesi coaching Tagihan Berkala yang belum dilunasi → alat masuk order tagihannya (pajak & biaya layanan dihitung
    ulang sekali per transaksi), ikut ditagih di Pelunasan Tagihan Coaching & invoice.
  - Stok: bola dipotong saat itu; raket / handuk saat itu bila sudah check-in, selain itu saat check-in. Check-in &
    retur alat sekarang juga membaca alat yang ditautkan ke booking lewat transaksi terpisah.
  - Server mengecek ulang di dalam kunci: booking hari ini & statusnya, cara bayar (transaksi baru / tagihan), stok,
    dan total yang tampil di layar. Kelola Pemesanan menampilkan "+ Alat: … · Rp …" di bawah kode booking.
  - Belum: refund booking tidak ikut mengembalikan uang tambahan alat (transaksi terpisah).
- [x] **Perapian UI tablet POS** (1 Nov 2026, hanya tampilan): di layar sentuh, sidebar halaman POS otomatis diciutkan
  sampai lebar 1440px (dulu hanya 1024–1279px); aturan "layar pendek" laptop tidak lagi mengecilkan tombol & kotak jam
  di tablet; semua tombol ≥44px (tombol +/− alat 48px dengan jarak 12px, tombol hapus slot, voucher, ganti customer);
  teks kotak jam lebih besar; banner peringatan shift lebih tipis. Laptop / PC tidak berubah.

---

## MODUL 12: SPONSOR / CORPORATE ACCOUNT

### Status: 90%
- [x] Resource admin Sponsor Organization & jadwal akses sponsor.
- [x] Dua jalur membership corporate: dibeli customer, atau diberikan admin (izin `grant_corporate_membership`).
- [x] Pulihkan sponsor lama yang pernah dihapus (tidak error duplikat).
- [x] Dashboard PIC corporate (`/corporate`): kelola anggota, import CSV, rilis / cabut voucher.
- [x] Halaman admin **Sponsor Team** (pratinjau dashboard PIC, dikunci izin sendiri).
- [~] Sebagian keputusan PM masih menunggu (lihat PRD Modul 12 §8).
- [x] **Jadwal Tetap Mingguan** (25 Okt 2026, permintaan klien untuk IOH: tiap Kamis 19:00–22:00, 3 lapangan): menu
  Operasional Harian → Jadwal Tetap Mingguan (izin `manage_recurring_bookings`). Booking rutin dibuat otomatis
  (tombol + `padel:generate-recurring` tiap 00:20) sampai N minggu ke depan atas nama pemilik membership (corporate /
  individu), lunas 100% dari kuota jam PADEL (order Rp0, tanpa shift kasir). Kuota kurang / slot bentrok = minggu itu
  GAGAL dan dicoba lagi, tidak pernah jadi tagihan. Lewati tanggal (booking dibatalkan, jam kembali) & batal lewati.
  Batas reschedule sekarang SATU pengaturan global untuk semua booking (tombol Batas Reschedule di Jadwal Tetap Mingguan,
  jam / hari, bawaan 2 jam, izin `manage_booking_time_limits`); lewat batas, tombol Reschedule di Kelola Pemesanan
  nonaktif & server menolak. Belum: kuota off-peak IM3 Premium (menunggu klien).
- [x] **Batas tanggal booking & tanggal tutup** (hotfix Grand Opening, 1 Nov 2026, PR #26, tanpa migrasi): `.env`
  `BOOKING_OPEN_UNTIL` (tanggal main terakhir yang boleh dibooking), `BOOKING_CLOSED_DATES` (tutup sehari penuh,
  dipisah koma), `BOOKING_CLOSED_REASON` (keterangan di pesan) lewat `App\Support\BookingWindow` + `config/booking_window.php`.
  Jadwal Tetap pada tanggal tutup dilewati tanpa memotong kuota. Setelah diubah: `php artisan config:cache`, cek dengan
  `php artisan booking:window`.
- [x] **Jadwal Tetap Mingguan — perbaikan** (1 Nov 2026, tanpa migrasi baru):
  - Form punya bagian **Lama jadwal**: Sampai membership habis / Selama N minggu / Sampai tanggal tertentu. Dulu jadwal
    selalu berjalan sampai masa aktif membership habis. Isian lama "minggu ke depan" diganti nama **Kunci slot lebih
    dulu**: hanya seberapa jauh booking dibuat di depan (bergulir tiap malam), bukan lama jadwal. Jadwal lama di production
    terbaca otomatis (tanggal akhir = akhir membership → "Sampai membership habis"); tidak ada data yang diubah.
  - **Lewati tanggal**: tanggal yang sudah dilewati tidak muncul lagi di pilihan; label tiap tanggal menjelaskan
    "sudah dibooking, X jam kuota dikembalikan" atau "belum dibooking, kuota belum terpotong". Batal lewati tetap ada.
  - **Perkiraan kuota** di tabel: kolom Periode menampilkan "booking dibuat s/d …"; kolom Sisa kuota menampilkan
    "Kuota cukup sampai akhir periode" (hijau) atau "Kuota kurang X jam … Cukup sampai … hanya n dari m lapangan …
    dst. tidak akan dibuat" (merah). Dihitung per tanggal lalu per lapangan seperti pembuat booking, melewati tanggal
    yang dilewati / tutup, menggabungkan semua jadwal aktif satu membership (kuotanya satu). Hanya perkiraan: jam yang
    dirilis ke karyawan sponsor ikut memakai kuota yang sama.
- [x] **Kustomer → tab Anggota Tim Sponsor** (1 Nov 2026, tanpa migrasi): dulu daftar & KPI hardcoded (data contoh
  sinarharapan.com). Sekarang data asli dari kartu corporate: KPI Kuota Kontrak / Dipakai Jadwal Tetap / Diberikan ke
  Karyawan (+ terpakai) / Sisa Kuota, tabel **Jadwal Tetap Mingguan** (sponsor tanpa anggota, mis. IM3, tetap terbaca),
  tabel anggota dengan status Aktif Bermain / Belum Pernah Main / Nonaktif, pengingat WhatsApp ke PIC hanya bila ada
  anggota yang belum main. **Jam favorit** customer dihitung dari booking asli (jam mulai terbanyak, booking lunas /
  check-in / selesai).

---

## MODUL 13: MULTI-BRANCH TENANCY

### Status: 0% (PRD saja)
- [x] PRD draft `docs/PRD_MODUL_13_MULTI_BRANCH_TENANCY.md`.
- [ ] Belum ada kolom `branch_id`, model Branch, atau scoping query di kode.

---

## MODUL 14: COMPANY PROFILE / KONTEN WEBSITE

### Status: 100%
- [x] Halaman admin **Kelola Konten Website** (profil, lokasi, footer, fasilitas, value props, terjemahan Inggris).
- [x] Landing page `welcome` membaca konten dari database.

---

## MODUL 15: MANAJEMEN MENU F&B

### Status: 100%
- [x] CRUD kategori, menu, modifier group (relasi many-to-many), gambar via `SecureImageUploader`.
- [ ] Catatan server: upload gambar butuh ekstensi PHP `gd` (`php8.3-gd`). Belum dicantumkan di `composer.json` (`"ext-gd": "*"`).

---

## MODUL 16: ACTIVITY / AUDIT LOG (URGENT)

### Status: 85% (dibangun sendiri, tanpa package)
- [x] PRD `docs/PRD_MODUL_16_ACTIVITY_AUDIT_LOG.md` (keputusan §9 pakai usulan default: simpan 24 bulan, super_admin saja, customer ikut dicatat, akses baca halaman tidak dicatat).
- [x] Tabel `activity_logs` + model **immutable** (tidak bisa diedit / dihapus dari aplikasi, termasuk super_admin).
- [x] Satu pintu tulis `ActivityLogger`: snapshot nama & role pelaku, IP, perangkat, halaman asal, batch per request.
- [x] Rahasia tidak pernah disimpan (password, token, key, hash QR, payload gateway) — diganti `[disembunyikan]`.
- [x] Otomatis: perubahan lapangan, alat sewa, menu F&B, pajak & biaya (KRITIS), voucher, paket membership, sponsor, user, role, konten website.
- [x] Transaksi: setiap pembayaran lunas di semua modul (walk-in, booking online, F&B, membership, pelunasan kasir, webhook, rekonsiliasi Midtrans) lengkap dengan item, metode bayar, meja, antrian, kasir.
- [x] Aksi sensitif: refund / batal (KRITIS), reschedule, check-in, selesai, retur alat, membership corporate gratis (KRITIS), perubahan izin role (KRITIS kalau izin backdoor), ganti role user.
- [x] Shift kasir buka / tutup, ditandai kalau ada selisih setoran.
- [x] Keamanan: login, logout, gagal login (tanpa password), lockout, reset sandi, **setiap percobaan akses tanpa izin (403)**.
- [x] Aksi sistem (scheduler, webhook) tercatat sebagai SISTEM / WEBHOOK, bukan dibebankan ke user yang kebetulan membuka halaman.
- [x] Panel **Log Aktivitas** (super_admin): filter tanggal, pengguna, modul, tingkat, channel, jenis aksi, pencarian; detail sebelum → sesudah; export CSV (izin terpisah, aman dari formula injection).
- [x] Retensi otomatis `audit:prune` harian (menolak konfigurasi 0 bulan).
- [x] Test: `tests/Feature/Audit/ActivityLogTest.php` (21 test).
- [ ] Hash berantai (deteksi manipulasi langsung di database).
- [ ] Alert otomatis ke super_admin (refund besar, perubahan pajak).
- [ ] Tombol "Riwayat" per booking / menu / sponsor di halaman masing-masing.

---

## MODUL 17: LAPORAN KEUANGAN & RIWAYAT TRANSAKSI TERPADU (URGENT)

### Status: 75%
Sumber pendapatan yang harus masuk laporan: POS Walk-In Padel, Booking Online Padel, Membership (online & kasir), POS F&B, POS Wellness, Gym, Merchandise.

PRD: [`PRD_MODUL_17_BUKU_TRANSAKSI_TERPADU.md`](PRD_MODUL_17_BUKU_TRANSAKSI_TERPADU.md) (draft 1 Okt 2026; pertanyaan §10 memakai usulan default).

- [x] **Fase 1 — Fondasi data** (4 Okt 2026):
  - Tabel `ledger_entries` + model immutable `LedgerEntry`: satu baris per kategori (Sewa Lapangan / Add-on Padel / Membership / F&B) per pembayaran atau refund, dengan snapshot order, customer, kasir, shift, metode & bukti bayar.
  - `LedgerWriter` dipanggil di semua cabang `markOrderAsPaid` (lunas, kelebihan bayar, pembayaran ganda `DUPLICATE`, uang masuk untuk tagihan tertutup) dan saat refund jadi `PROCESSED` — di transaksi DB yang sama; gagal tulis buku = pelunasan ikut gagal.
  - Pembagian diskon/pajak/biaya layanan proporsional per kategori, pelunasan selisih reschedule dari `payload_log`-nya sendiri, total baris = `payments.amount` persis (dihitung dalam sen). Transaksi `MOCK` & catatan `legacy_backfill` tidak dicatat.
  - Item sewa alat kini `item_type = EQUIPMENT` (data lama dikonversi migration); kolom `payments.paid_at` (data lama dari `updated_at`), dipakai riwayat & struk POS Walk-In.
  - Command `ledger:backfill {--from=} {--dry-run}` (idempoten) dan `ledger:verify {--date=} {--days=}` (terjadwal 01:15, selisih → Log Aktivitas KRITIS).
  - Test: `tests/Feature/Finance/LedgerTest.php` (18 test).
- [x] **Fase 2 — Halaman Buku Transaksi & Antrian Refund** (4 Okt 2026), grup menu **Keuangan**:
  - **Buku Transaksi**: satu baris per pembayaran / refund, kartu ringkasan (penjualan bersih, biaya layanan, pajak terkumpul, refund, total uang masuk bersih + info benefit, hangus, refund menunggu), filter periode (preset WIB), sumber, kategori, metode, kasir, shift, status, pencarian (no. order / kode booking / nama / HP / RRN).
  - Detail slide-over: pembagian per kategori, bukti bayar, riwayat pembayaran & refund order, booking terkait, log aktivitas order (+ tautan `Log Aktivitas?cari=`).
  - Invoice **SALINAN ADMIN**: struk POS Walk-In untuk pembayaran kasir padel; invoice ringkas dari buku untuk online / membership / F&B / refund. Tercatat `ledger.invoice_viewed`.
  - Tombol **Export** (dropdown): Excel (lembar *Transaksi* + *Rincian Kategori*) & PDF (ringkasan, rekap per kategori, daftar transaksi; maks. 1.500 baris, `barryvdh/laravel-dompdf`) lewat route `admin.buku-transaksi.export` — dikecualikan dari mode SPA panel supaya file terunduh (dulu isi XLSX tampil sebagai teks). Anti formula injection, tanpa HP/email, tercatat `ledger.exported`.
  - **Antrian Refund**: proses (metode + nomor referensi → baris buku negatif) / tolak (alasan wajib), row lock anti diproses dua kali, Log Aktivitas KRITIS (`refund.processed` / `refund.rejected`). Kolom baru `refunds.refund_method`, `refund_reference`, `processed_by_id`, `admin_notes`.
  - Izin backdoor (hanya super_admin): `View:BukuTransaksi`, `export_ledger`, `view_ledger_invoice`, `process_refund_queue`.
  - Test: `tests/Feature/Finance/BukuTransaksiTest.php` (14 test).
- [ ] **Fase 3 — Dashboard**: rincian per kategori/sumber/metode, grafik, Analytics membaca dari buku.

- [~] Halaman **Analytics & Keuangan** sudah query data asli, tapi:
  - [ ] **Pendapatan F&B tidak dihitung sama sekali.**
  - [ ] Wellness, Gym, Merch belum ada (modulnya belum jalan).
  - [ ] Okupansi masih rumus tetap 4 lapangan x 18 jam; filter "ALL" sebenarnya cuma 30 hari.
  - [ ] Belum ada pilih rentang tanggal, grafik, dan export (Excel / PDF).
- [x] Tab Riwayat Transaksi di POS F&B (khusus F&B, 50 transaksi terakhir).
- [ ] **Buku transaksi terpadu**: semua order & payment lintas modul dalam satu tabel, filter (modul, tanggal, metode bayar, kasir, status), klik untuk lihat detail item + pembayaran + refund.
- [ ] **Lihat / cetak ulang invoice** untuk setiap transaksi dari panel admin (sekarang invoice cuma ada di portal customer untuk padel & membership, dan struk kasir cuma sekali tampil setelah bayar).
- [ ] Nomor invoice resmi yang tersimpan (sekarang pakai `order_number`).
- [ ] Laporan per modul (harian / bulanan) + rekap per metode bayar.
- [ ] Laporan shift kasir & selisih setoran.
- [ ] Laporan refund.

---

## MODUL 18: PENGATURAN INVOICE / STRUK TERPUSAT

### Status: 0%
- [ ] Panel pengaturan: logo, nama usaha, alamat, telepon, NPWP, header & footer, catatan kaki per modul.
- [ ] Dipakai oleh semua invoice & struk (POS Padel, POS F&B, Jual Membership, invoice customer, modul lain nanti).
- Kondisi sekarang: tabel `club_finance_settings` hanya menyimpan pajak & biaya admin; alamat struk hardcoded di blade.

---

## MODUL 19: REALTIME (LARAVEL REVERB)

### Status: 0%
- [ ] Belum terpasang: tidak ada `laravel/reverb`, `laravel-echo`, `config/broadcasting.php`, `routes/channels.php`, maupun event `ShouldBroadcast`.
- Kondisi sekarang pakai polling:
  - Monitoring Lapangan (`booking-system.blade.php`) `wire:poll.10s`.
  - Invoice customer polling status bayar maksimal 10 kali.
  - Notifikasi navbar customer dibangun dari data booking, status "dibaca" cuma di localStorage.
- Kandidat pertama realtime: KDS dapur (pesanan baru dari POS F&B), monitoring lapangan, status bayar invoice, notifikasi customer.
- Catatan server: Reverb butuh proses yang jalan terus (supervisor / systemd) + konfigurasi proxy websocket di Nginx.

---

## MODUL 21: KEBIJAKAN REFUND, NO-SHOW & PEMBAYARAN BERMASALAH

### Status: 80% (Tahap 1 selesai 5 Okt 2026, pembayaran bermasalah ditunda)

PRD: [`PRD_MODUL_21_REFUND_NO_SHOW_PEMBAYARAN_BERMASALAH.md`](PRD_MODUL_21_REFUND_NO_SHOW_PEMBAYARAN_BERMASALAH.md) — keputusan PM di §0.

- [x] Refund dua langkah: kasir / resepsionis / admin mengajukan dari Kelola Pemesanan (booking langsung batal, refund penuh tanpa potongan), superadmin menyetujui di Antrian Refund.
- [x] Refund ditolak → uangnya jadi voucher saldo customer (+ email). Customer tidak bisa mengajukan refund sendiri.
- [x] Kunci refund begitu jam main dimulai; reschedule paling lambat 2 jam sebelum main; booking hangus final.
- [x] Voucher dipakai di checkout online & POS Walk-In; halaman Daftar Voucher (Keuangan).
- [ ] Menu Pembayaran Bermasalah (saldo customer terpotong tapi uang belum masuk) — ditunda PM.

---

## MODUL 22: VOUCHER SALDO & VOUCHER PROMO MARKETING

### Status: ±70% (tahap 2 & 3 dibangun; tahap 1 dilewati dulu, cara D menyusul)

PRD: [`PRD_MODUL_22_VOUCHER_DAN_PROMO.md`](PRD_MODUL_22_VOUCHER_DAN_PROMO.md) v0.2 (18 Okt 2026) — skema dinamis, cara sebar A–D, aturan tetap T1–T6.

- [x] Tabel & mesin voucher satu untuk online dan kasir (`VoucherService`), voucher saldo dari refund ditolak, Daftar Voucher.
- [ ] Voucher saldo **sekali pakai, sisa hangus** (keputusan PM 5 Okt 2026 — yang berjalan sekarang masih menyimpan sisa).
- [x] Tahap 2 (19 Okt 2026): buat / ubah / nonaktifkan promo **kode umum (A)** di Daftar Voucher — kuota, periode, kanal, kategori sewa lapangan, 1x per akun, gabung benefit member.
- [x] Tahap 3 (23 Okt 2026, belum di-commit): **kode unik massal (B)** — kampanye + N kode `AWALAN-XXXXXX` (kuota 1, 1 kode per akun per kampanye), Unduh CSV kode & status; **klaim ke akun (C)** — link `/promo/KODE`, kuota = klaim, voucher masuk Voucher Saya & muncul otomatis di checkout / POS, API `vouchers/claim` + `vouchers/mine.claimed`. Migrasi `2026_10_23_160001`.
- [ ] Cara D (otomatis: akun baru, ulang tahun, referral) & saklar gabung voucher sponsor.

---

## MODUL 23: STRUKTUR PORTAL CUSTOMER (MEMBERSHIP, MY CLUB, PROFILE, VOUCHER SAYA)

### Status: ±50% (navigasi, Profile, Voucher Saya selesai 18 Okt 2026; My Club compro & halaman Membership menyusul)

PRD: [`PRD_MODUL_23_STRUKTUR_PORTAL_CUSTOMER.md`](PRD_MODUL_23_STRUKTUR_PORTAL_CUSTOMER.md)

- [x] Navigasi: tab Membership menggantikan Profile di navigasi bawah HP; menu Membership di navbar desktop; Profile + Voucher Saya + Logout di dropdown akun.
- [ ] My Club jadi profil klub (compro): ~~kartu member & voucher dipindah~~ (selesai, diganti baris status member); 3 paket paling laku + "Lihat semua paket" menggantikan katalog lengkap — belum.
- [x] Profile: kartu membership aktif (QR + kuota), menu Voucher Saya, pengaturan akun dirapikan.
- [x] Voucher Saya gaya Shopee (voucher saldo + voucher jam corporate, tab Tersedia / Riwayat) — data dari `CustomerAccountService` (siap dipakai API mobile).
- [ ] Pindahkan link lama: `/my-club#corporate-vouchers` di Home ✓; redirect setelah beli membership & tombol "Kembali ke My Club", kartu Upgrade di Home — belum.

---

## MODUL 24: COACHING — OPSI B (ACADEMY SEWA LAPANGAN HARGA KHUSUS)

### Status: Tahap 1 & 2 dibangun 23 Okt 2026 (belum di-commit / belum dicoba di server)

PRD: [`PRD_MODUL_24_COACHING_OPSI_B_SEWA_LAPANGAN_ACADEMY.md`](PRD_MODUL_24_COACHING_OPSI_B_SEWA_LAPANGAN_ACADEMY.md) (dipilih). Opsi A [`PRD_MODUL_24_COACHING.md`](PRD_MODUL_24_COACHING.md) ditunda.

- [x] Menu admin **Coaching**: Jadwal Coaching (+ tombol WhatsApp pengingat coach), Coach, Academy, Tarif Setoran (aturan hari + jam + harga/jam + prioritas) & Pengaturan (biaya coach tambahan / jam, default Rp0; WA admin). Migrasi `2026_10_23_100001` (tarif awal 06–17 Rp100rb, 17–24 Rp150rb, SR Academy).
- [x] POS Walk-In tipe **Reguler / Coaching** (izin `book_coaching`): coach utama + tambahan, murid (maks 2), catatan; harga = `CoachingPricingService` (layar = server); membership / voucher / kode promo tidak dipakai; coach tidak bisa dobel jadwal (kunci baris coach); satu sesi tidak boleh dua lapangan di jam sama.
- [x] Buku Transaksi kategori **COACHING — Sewa Lapangan Coaching** (slot tetap item PADEL; termasuk selisih reschedule); Analytics baris "Pelatih & Coaching Session" aktif.
- [x] Reschedule: harga jadwal baru = tarif coaching, jam tanpa tarif tidak ditawarkan, jadwal coach dicek.
- [x] API hold customer tidak menerima `coach_id` lagi. Izin baru: `View:JadwalCoaching`, `book_coaching` (preset resepsionis), `manage_coaching`, `manage_coaching_rates` (pintu belakang, seperti tarif lapangan).
- [x] Tahap 2 (23 Okt 2026, belum di-commit): migrasi `2026_10_23_140001`.
  - **Monitoring Lapangan**: sel timetable sesi coaching menampilkan "Coach Nalda +1"; popup detail berisi coach (+ tombol WA pengingat), academy, murid, catatan.
  - **Profil coach** di portal customer `/coaching` (menu Coaching; di layar < 1280px lewat dropdown akun): foto (selalu utuh, tidak dipotong), olahraga, motto opsional (migrasi `2026_10_23_150001`), poin keunggulan, tombol WA admin coaching. Tampil hanya coach aktif + "Tampilkan di profil" (default mati). No. WA / email / catatan coach tidak ditampilkan. Foto lewat `SecureImageUploader`, path foto dikunci di model.
  - **API** `GET /api/v1/coaching/coaches` (publik, read-only, sama dengan halaman web).
  - **Link bio** `/links` (publik): tombol diatur di Marketing & Event → Link Bio Instagram (izin `manage_link_bio`, preset admin). Default: Book Court, Lokasi, Admin Coaching, Pricelist (gambar, nonaktif sampai diunggah), Admin Club 61; media sosial dari Konten Website. Tombol tanpa tujuan tidak tampil; link bebas wajib http/https.
  - **Analytics**: Rekap Coaching per Academy (sesi, jam, nilai sewa) & per Coach (sesi utama / tambahan, jam).

---
## MODUL 28: HASIL MEETING TRAINING (28 OKT 2026)

### Status: Batch 1 (28 Okt) & Batch 2 (29 Okt 2026) dibangun, belum di-commit; batch 3–5 menunggu keputusan

PRD: [`PRD_MODUL_28_HASIL_MEETING_TRAINING_28_OKT_2026.md`](PRD_MODUL_28_HASIL_MEETING_TRAINING_28_OKT_2026.md) (berisi daftar pertanyaan terbuka).

- [x] **EDC hanya BCA**: pilihan Mesin EDC Mandiri / Lainnya hilang dari semua layar bayar kasir & koreksi metode; server menolak terminal selain BCA (`PosPaymentProof::ACTIVE_EDC_TERMINALS`). Data lama tetap terbaca.
- [x] **Reschedule maks. 3x per booking** (`PadelBookingService::MAX_RESCHEDULES`), termasuk pindahan Jadwal Tetap; tombol di Kelola Pemesanan nonaktif setelah 3x.
- [x] **Sauna → Wellness** di teks tampilan (portal customer termasuk My Club, admin, label izin). Kode internal `SAUNA` tetap. Nama fasilitas yang tersimpan di database diganti admin di tiap server.
- [x] **Revoke dihapus** dari portal corporate (tombol & endpoint API). Anggota lama berstatus REVOKED tetap bisa diaktifkan lagi.
- [~] ~~Selisih prime → regular jadi voucher~~ — batal (28 Okt 2026); selisih tetap HANGUS.
- [x] **Batch 2 — aturan akses paket** (migrasi `2026_10_29_100001`): benefit Padel paket punya satu pilihan **Waktu Berlaku** (Kapan saja / Hanya di luar jam peak / Hanya di jam peak / Atur manual hari + jam 24 jam) dengan ringkasan "Paket Dapat Digunakan" dan tolak simpan kalau nol jam; satu pengecek `PadelAccessRule` dipakai checkout online, kasir Walk-In, **voucher jam karyawan corporate** (dulu tidak dicek), reschedule & Jadwal Tetap. Di luar aturan = harga normal + alasan di layar. Kartu memegang salinan aturan saat dibeli; tombol **Terapkan Aturan** (izin `apply_membership_plan_rules`, super admin) menyamakan kartu aktif dengan paket terbaru.
- [x] **Terapkan Kuota & Masa Aktif** (31 Okt 2026, tanpa migrasi baru): tombol di Paket Membership (izin `apply_membership_plan_quota`, bawaan super admin saja; izinnya dibuat oleh migrasi `2026_10_31_100001` saat deploy). Pratinjau per kartu dulu, lalu kartu aktif / menunggu bayar disamakan dengan kuota & masa aktif paket terbaru: selisih kuota ditambahkan ke sisa (jam terpakai tetap dihitung, tidak pernah minus), tanggal berakhir = tanggal mulai + masa aktif paket (dilewati bila sudah lewat, atau bila kartu sudah pernah diperpanjang supaya masa perpanjangan tidak terpotong). Kuota unlimited / jenis berbeda tidak disentuh; diskon & aturan akses tetap lewat Terapkan Aturan. Tercatat di Audit Log Mutasi Kuota ("PENYESUAIAN (ADMIN)") & Log Aktivitas. Bila member memakai kuota tepat saat diterapkan, semua dibatalkan dan admin diminta membuka ulang pratinjau. Sejak 1 Nov 2026: paket dikunci lebih dulu & kuota tiap kartu dibaca ulang di dalam kunci, jadi dua admin yang menerapkan bersamaan tidak menambah kuota dua kali (yang kedua ditolak); bila masa aktif kartu jadi lebih pendek, batas akhir Jadwal Tetap Mingguan kartu itu (aktif / dijeda) ikut dipotong — booking yang sudah dibuat setelahnya tidak dibatalkan, hanya diinfokan di pratinjau. Tabel Paket Membership: Edit + menu ⋯ (Terapkan Aturan / Terapkan Kuota & Masa Aktif / Hapus); tombol Terapkan hanya untuk paket yang sudah terjual, tombol simpan disembunyikan bila tidak ada kartu yang berubah, kolom Catatan hanya bila ada catatan.
- [x] **Saluran Penjualan paket**: *Khusus Front Desk / Admin* tidak tampil & tidak bisa dibeli di web / aplikasi (dicek di server); bawaan *Online (Web & Aplikasi) dan Front Desk* = perilaku lama.
- [ ] Waktu Berlaku untuk gym & wellness; menu Jadwal Akses Sponsor (dibiarkan dulu). Master Data Jam Peak tiap server disesuaikan proposal harga (peak hanya Sen–Jum 17–24).
- [x] **Batch 3 — Monitoring Lapangan Harian / Mingguan / Bulanan** (`ScheduleOverview`): grid jam × hari dengan warna jenis booking, kalender bulanan dengan jumlah booking & persen terisi (klik tanggal → daftar / Buka Harian), Rekap Pemain, filter lapangan / jenis / cari. Hanya baca; export ditunda.
- [x] **Kelola Pemesanan tidak menumpuk** (29 Okt 2026, migrasi `2026_10_29_100002` index `booking_date + status`): filter Periode (bawaan *Bulan Ini & Mendatang*; Harian / Mingguan / Bulanan dengan ‹ ›, rentang tanggal, Semua Data), angka tab ikut periode, tab *Semua Aktif* tanpa batal / refund / expired, pencarian tetap mencakup semua periode. Data tidak dihapus. Monitoring: filter Indoor / Outdoor dihapus (cukup pilih per lapangan); tampilan Harian mengecilkan kolom jam yang sudah lewat / tutup di semua lapangan tanpa booking (seperti POS Walk-In), kolom lain berbagi lebar layar, tabel otomatis digeser ke kolom LIVE NOW saat dibuka, dan kolom lapangan dirampingkan di layar ≤1100px. Mingguan: kolom hari berbagi lebar (min. 105px) supaya Senin–Minggu muat di laptop 14" / tablet landscape.
- [ ] Batch 4 coaching kontrak, Batch 5 Indosat dua kantong jam (ditahan — Modul 29 didahulukan).

---
## MODUL 29: MINI INVENTORY & KEUANGAN F&B
**Status:** Tahap 1–3 dibangun 29 Okt 2026 (belum di-commit). PRD: `docs/PRD_MODUL_29_MINI_INVENTORY_DAN_KEUANGAN_FNB.md`.

Migrasi `2026_10_29_100003_create_fnb_stock_and_cashbook_tables`. Sidebar mendapat grup baru **F&B**.

- [x] **Tahap 1 — Stok per menu, tanpa bahan baku**
  - HPP manual dan Lacak Stok per menu, diatur di halaman Stok F&B dan di form Kelola Menu F&B.
  - Stok berkurang saat pesanan kasir F&B lunas: sekali per pesanan, boleh minus, HPP disimpan per item.
  - Stok Masuk otomatis tercatat di Buku Kas sebagai Belanja Stok, dan bisa dibatalkan dengan alasan.
  - Penyesuaian wajib alasan + catatan.
  - Stok Opname hanya mencatat selisih.
  - Kartu Stok tidak dapat diubah atau dihapus.
  - Penanda "Sisa N / Stok minus" di kasir F&B.
  - Izin per aksi dan Log Aktivitas.
- [x] **Tahap 2 — Buku Kas F&B**
  - Pengeluaran dan pemasukan lain berkategori, dengan kategori yang bisa diatur.
  - Foto nota disimpan privat.
  - Catatan dibatalkan dengan alasan, tidak dihapus.
- [x] **Tahap 3 — Laporan F&B**
  - Laba: penjualan − HPP + pemasukan lain − kerugian stok − biaya operasional.
  - Arus Kas, tren 6 bulan, menu terlaris / paling untung, stok menipis.
- [x] **Revisi 30 Okt 2026 (PRD v0.3.0)**
  - HPP, Lacak Persediaan, dan Stok Minimum hanya diatur di data menu.
  - **Penerimaan Barang** banyak menu per nota, dengan supplier dan no. nota. Buku Kas mencatat satu pengeluaran Pembelian Persediaan. Pembatalan dilakukan per penerimaan.
  - **Impor Excel**: templat berisi menu yang dilacak, pratinjau per baris, dan disimpan semua atau tidak sama sekali.
  - Istilah dibuat formal; Supplier dan Nilai Stok (HPP) tetap.
  - Migrasi `100003` diubah, jadi di lokal perlu rollback lalu migrate ulang.
- [x] **Revisi PRD v0.4.0 (30 Okt 2026)**
  - **Templat impor** memuat semua menu. Isian per baris: Stok Fisik, Jumlah Diterima, Harga Beli per Satuan, Supplier, dan No. Nota.
  - **Saat impor, sistem otomatis:**
    - mengaktifkan Lacak Persediaan;
    - mencatat Stok Opname dari stok fisik;
    - membuat satu Penerimaan Barang per supplier + nota;
    - memperbarui HPP bila dipilih.
  - **Form manual** memakai harga per satuan.
  - **Tombol halaman:** satu tombol utama + dropdown Lainnya, di Stok F&B dan Buku Kas.
  - **Menu F&B** dipindah ke grup F&B.
- [x] **Revisi PRD v0.5.0 (30 Okt 2026), hasil evaluasi alur**
  - Aksi stok cuma dari tombol atas; klik nama menu membuka Kartu Stok.
  - Penyesuaian Stok bisa banyak menu sekaligus, ditambah alasan "Dikembalikan ke Penitip" yang bukan kerugian.
  - Penerimaan manual otomatis mengaktifkan Lacak Persediaan.
  - Pemasukan hanya dari penjualan kasir; Buku Kas F&B diganti nama jadi **Pengeluaran F&B**.
  - Migrasi `100003` diubah lagi.
- [x] **Revisi PRD v0.6.0 (30 Okt 2026)**
  - Catatan per menu di Stok Opname.
  - **Unduh Laporan F&B:** Excel 5 sheet dan PDF ringkas, izin `export_reports`.
  - **Cetak Lembar Opname:** PDF hitung buta dengan tanda tangan.
  - **Unduh Laporan Pengeluaran F&B** (Excel / PDF), mengikuti filter halaman.
- [ ] Ditunda: arti "stock bulatan" (tanya PM), HPP varian / tambahan, pengeluaran di luar F&B. Pemasukan Lain dihapus, Setoran Modal tidak diperlukan (pemasukan hanya dari kasir).

---
## MODUL 30: SITUS CUSTOMER DUA BAHASA (ID / EN)
**Status:** dibangun 30 Okt 2026, belum di-commit. Tanpa migrasi; data production tidak tersentuh.

- [x] **Fondasi**
  - Pilihan bahasa disimpan di session + cookie `site_locale` (1 tahun), default **Bahasa Indonesia**. Tidak ada kolom database.
  - Format tanggal (Carbon) ikut bahasa yang dipilih.
  - Komponen `<x-lang-switch />` (variant dark / light).
  - Test `LocaleParityTest`: setiap kunci wajib ada di `lang/id` dan `lang/en`.
- [x] **Tahap A:** tombol ID/EN di navbar (≥1280px) dan menu akun (HP), login/register/lupa & reset password/verifikasi, coming-soon, dashboard, profil, voucher saya. File lang: `nav`, `account`, `dashboard`.
- [x] **Tahap B:** booking, keranjang, checkout. Termasuk teks JS (objek i18n `@js`), popup akses member/voucher, dan format tanggal JS. File lang: `booking`, `cart`, `checkout`.
- [x] **Tahap C:** membership, My Club, coaching, portal corporate, klaim promo, invoice web + e-ticket, Member Pass. Template PDF/email invoice tetap Indonesia. File lang: `membership`, `myclub`, `coaching`, `corporate`, `promo`, `invoice`.
- [x] **Tahap D:** pesan server (API/JSON) ke customer, lewat `App\Support\SiteLocale::text()` + `lang/{id,en}/messages.php`.
  - Pesan berbahasa Inggris **hanya** bila customer memilih EN (session/cookie). Middleware `SetApiLocale` di grup api hanya mengubah locale bila ada pilihan.
  - Aplikasi mobile/bearer token, panel admin, kasir (Livewire), dan console tetap mendapat teks Indonesia yang sama persis dengan sebelumnya. Header `Accept-Language` diabaikan.
- **Tidak diterjemahkan:** data yang diinput admin (nama/deskripsi paket, fasilitas, konten company profile, nama menu/lapangan/coach/perusahaan), nama merek, pesan 403 (tercatat di Log Aktivitas), dan jalur POS/walk-in.
- **Lapangan (catatan meeting 30 Okt):**
  - Pilihan Outdoor dihapus di Master Data; lapangan lama bertipe OUTDOOR menjadi INDOOR saat disimpan.
  - Seeder memakai Court BCA / Court Protelindo / Court IM3 Platinum.
  - **Production:** ganti nama lewat Master Data (Iforte → Protelindo, Indosat → IM3 Platinum). Jangan membuat lapangan baru, supaya riwayat booking tetap terhubung.
  - **Urutan tampil lapangan** (migrasi `2026_10_30_100001`, hanya menambah kolom `padel_courts.sort_order` default 0): isian "Urutan Tampil" di Master Data → Edit Lapangan. Semua daftar lapangan (Monitoring, POS, Kelola Pemesanan, Jadwal Tetap, booking customer, signage, template impor, API) diurutkan `sort_order` lalu nama (scope `PadelCourt::ordered()`). Sebelum diisi semua 0 sehingga tetap abjad. **Production:** setelah `php artisan migrate`, isi BCA = 1, Protelindo = 2, IM3 Platinum = 3.
- **Perbaikan hasil review keamanan (30 Okt 2026, tanpa migrasi baru):**
  - Dua bahasa: `lang/en/passwords.php` memakai satu pesan untuk email terdaftar / tidak (anti tebak akun); request Livewire, `/pos`, `/kitchen` selalu Bahasa Indonesia walau browser pernah memilih EN; `lang.switch` hanya kembali ke halaman situs sendiri; pesan lupa / reset password ikut bahasa.
  - Modul 29: tanggal pengeluaran memakai WIB; pelunasan F&B mengunci menu berurutan id (anti deadlock), impor Excel mengunci semua menunya di awal; HPP Rp0 dihitung "belum memiliki HPP" + nama menunya ditampilkan; mengaktifkan ulang Lacak Persediaan menolkan stok lama lewat Kartu Stok (nilai 0, bukan kerugian); batal penerimaan tidak mengubah stok menu yang tidak dilacak; jenis kategori pengeluaran terkunci bila sudah ada catatan; batas angka (qty 100.000, harga Rp100 jt) di service, impor & opname; migrasi `2026_10_29_100003` aman dijalankan ulang bila sempat berhenti.
  - Lapangan: dropdown lapangan Kelola Pemesanan langsung diperbarui saat lapangan diubah; scope `ordered()` memakai nama kolom berprefix tabel.

---
## MODUL 31: TAGIHAN COACHING (TAGIHAN BERKALA ACADEMY)
PRD: `docs/PRD_MODUL_31_TAGIHAN_COACHING.md`. Permintaan klien: booking coaching tidak ditagih per transaksi, tetapi digantung dan dilunasi sekaligus.
- [x] **Tahap 1** (31 Okt 2026, migrasi `2026_10_31_100001`, hanya menambah):
  - Menu Coaching → Academy: Metode Pembayaran (Bayar Langsung / Tagihan Berkala), Periode (Per Shift / Harian / Mingguan / Dua Mingguan + tanggal mulai siklus), sakelar blok booking bila tagihan lewat jatuh tempo. Academy baru default Tagihan Berkala – Per Shift; academy lama tetap Bayar Langsung.
  - POS Walk-In tab Coaching: academy Tagihan Berkala → "Catat ke Tagihan Berkala" tanpa EDC / QRIS (server yang menentukan dari pengaturan academy). Booking `PENDING_PAYMENT` tanpa batas bayar, order `UNPAID`, struk "Tagihan Berkala Academy (belum lunas)". Peringatan tagihan lewat jatuh tempo; blok bila sakelar aktif.
  - Pengecualian di satu helper `CoachingBillingService`: tidak dilepas pembersih otomatis / keranjang customer, boleh check-in, tidak bisa dibayar online. Reschedule ikut aturan global (sejak 1 Nov 2026, lihat Perbaikan di bawah).
  - Kelola Pemesanan: label "Tagihan Berkala · academy", tombol Batalkan Sesi Tagihan Berkala (sebelum batas waktu global; lewat batas / tidak datang tetap ditagih).
  - Izin baru `settle_coaching_bill` (resepsionis, admin) & `View:TagihanCoaching` (admin) sudah didaftarkan untuk Tahap 2–3.
- [x] **Tahap 2** (31 Okt 2026, tanpa migrasi baru):
  - POS Walk-In → tombol **Pelunasan Tagihan Coaching** (izin `settle_coaching_bill`): pilih academy, centang transaksi yang dibayar (boleh sebagian), metode QRIS / Mesin EDC BCA / Transfer Bank (+ foto bukti wajib, disk privat). Satu bukti melunasi banyak transaksi; tiap order dilunasi lewat `PaymentOrchestratorService` (Buku Transaksi kategori Coaching, shift kasir yang buka). Nomor `TC-yymmdd-XXXXX`, bukti pelunasan bisa dicetak, Log Aktivitas `coaching_tab.settled`. Bukti (RRN / approval / referensi transfer) tidak bisa dipakai ulang.
  - Tutup Shift: transfer pelunasan coaching = baris **Transfer Bank — Pelunasan Tagihan Coaching** (tidak diketik kasir, dicek admin pada mutasi rekening). Transfer lain (mis. selisih reschedule) tetap seperti sebelumnya. Peringatan bila tagihan Per Shift dari shift itu belum dilunasi.
- [x] **Tahap 3** (31 Okt 2026, tanpa migrasi baru):
  - Halaman **Coaching → Tagihan Coaching** (izin `View:TagihanCoaching`): KPI belum lunas / melewati jatuh tempo / dilunasi bulan ini, tabel sesi (filter academy, status, periode tanggal main), riwayat 30 pelunasan terakhir + unduh bukti transfer (disk privat).
  - **Unduh Invoice PDF** per academy + periode: sesi belum lunas saja, memakai Template Invoice yang sama (`emails.invoice.pdf`) lewat isian opsional judul / status "Belum Lunas" / "Total tagihan" / catatan jatuh tempo — invoice lunas customer tidak berubah. Tercatat di Log Aktivitas.
  - Tampilan: sesi tagihan berkala tampil sebagai booking (nama pemain + "Tagihan Berkala") di grid POS Walk-In, "TAGIHAN BERKALA" di Monitoring Lapangan, tidak bergaris putus di tampilan Mingguan, status "Tagihan Berkala" di Jadwal Coaching (dulu terbaca Hold / Menunggu bayar).
- [x] **Perbaikan** (1 Nov 2026, tanpa migrasi baru):
  - Sesi tagihan bisa di-reschedule di Kelola Pemesanan (batas waktu global, maks. 3x): tagihan ikut tarif jadwal baru, tanpa selisih / hangus, pajak & biaya layanan dihitung ulang sekali per transaksi, jatuh tempo dihitung ulang.
  - Academy tidak bisa dihapus selama masih punya sesi tagihan belum lunas.
  - Modal Pelunasan: bukti transfer tampil pratinjau foto + nama file + "Mengunggah…", langsung dicek saat dipilih.
  - Pojok POS "Omzet Walk-In" hanya menghitung order lunas (tagihan coaching belum lunas / order batal tidak ikut).
- [x] **Pemeriksaan keamanan sebelum rilis** (1 Nov 2026, tanpa migrasi baru): review seluruh perubahan Modul 31,
  Terapkan Kuota, Kustomer & Jadwal Tetap. Hak akses dicek di server, tidak ada IDOR / XSS, upload bukti aman (gambar
  maks. 4 MB, diproses ulang, disk privat), migrasi hanya menambah. Diperbaiki:
  - Pelunasan: tombol Terima mengirim total yang tampil di layar; tagihan berubah sebelum ditekan (mis. sesi dipindah
    jadwalnya) → ditolak "Total tagihan berubah dari … menjadi …", tidak ada yang tersimpan.
  - Modal Pelunasan: daftar academy & tagihan kosong untuk user tanpa izin `settle_coaching_bill` walau dibuka paksa
    dari browser; ringkasan pelunasan untuk dicetak tidak bisa diisi dari browser.
  - Log Aktivitas reschedule sesi tagihan mencatat "tagihan Rp X → Rp Y", bukan selisih ditagih / hangus.
  - **Keputusan:** Transfer Bank pelunasan belum punya tanda "sudah dicek mutasi"; admin mencocokkan manual dengan
    bukti transfer di halaman Tagihan Coaching (opsi B, 1 Nov 2026).
- **Izin Modul 31:** `manage_coaching` (pengaturan metode pembayaran academy), `book_coaching` (catat ke tagihan & batalkan sesi tagihan), `settle_coaching_bill` (Pelunasan Tagihan Coaching), `View:TagihanCoaching` (halaman, invoice, bukti transfer).
- **Production:** hanya migrasi `2026_10_31_100001` (menambah kolom / tabel). Academy yang sudah ada tetap Bayar Langsung sampai diubah admin, jadi perilaku kasir tidak berubah setelah deploy. Jangan di-rollback setelah ada tagihan.

---
## MODUL 20: HALAMAN ADMIN PENDUKUNG

### Status: 0% (semua DUMMY)
| Halaman | Kondisi | Yang dibutuhkan |
| :--- | :--- | :--- |
| **Dashboard** | **DUMMY**: angka "Rp 50.272.597", "132 transaksi", grafik SVG statis, daftar booking palsu | Ringkasan asli hari ini: pendapatan per modul, booking, okupansi, shift aktif |
| **Kelola Club** | **DUMMY**: 4 kartu fasilitas hardcoded | Kelola fasilitas wellness & jadwal maintenance |
| **Kelola Karyawan** | **DUMMY**: "Coach Budi Santoso", "Siti Hair Stylist" hardcoded, tombol Rekrut tidak jalan | Pakai `StaffProfile` / `StaffSchedule` (model sudah ada) |
| **Kelola Turnamen** | **DUMMY**: kartu statis "Segera Hadir" | Model & alur turnamen belum ada |
| **Marketing** | **DUMMY**: voucher CLUB61 / HAPPYHOUR hardcoded, tombol Buat Voucher tidak jalan | Pakai model `Pos/Voucher` (sudah ada) |

### Bagian DUMMY di Portal Customer
- [ ] Dashboard customer: statistik "3 Active Courts / 1000 Lux / Wellness" teks statis.
- [ ] Dashboard customer: kartu "Upgrade to Diamond Club" hardcoded, mengarah ke WhatsApp, bukan ke `/membership`.
- [ ] Dashboard customer: info venue & nomor "0812-6161-PADEL" statis, belum dari Konten Website.
- [ ] Halaman `home`, `padel`, `cafe` di `resources/views/customer` kosong (file 1 baris, tidak ada route).
- [ ] My Club: fasilitas, etiket, "privilege standards" masih teks marketing statis (boleh dibiarkan kalau memang copy tetap).

---

## Catatan Infrastruktur Server
- [x] Scheduler: `padel:release-expired-slots` (tiap menit), `payment:reconcile-midtrans` (tiap 5 menit), `membership:sync-expired` (harian). Butuh cron `* * * * * php artisan schedule:run` sebagai `www-data`.
- [x] Queue worker belum dibutuhkan (belum ada job antrian). Akan dibutuhkan saat email & Reverb aktif.
- [ ] Daftarkan URL notifikasi Midtrans di dashboard Midtrans.
- [ ] Setting SMTP untuk email.
- [ ] Ekstensi PHP `gd` wajib terpasang di server.
