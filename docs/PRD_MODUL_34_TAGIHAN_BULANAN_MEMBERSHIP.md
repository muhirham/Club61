# PRD Modul 34 — Tagihan Bulanan Perusahaan (Rekap Jam Main, Ditagihkan Finance)

| | |
|---|---|
| **Versi** | v0.5 (10 Nov 2026) — Tagihan Bulanan dipakai seperti benefit kartu member (tanpa metode bayar khusus); semua transfer perusahaan dicatat lewat satu tombol **Catat Transfer** di Kelola Sponsor. v0.4 (9 Nov): Finance menentukan harga & menagih di luar sistem; sistem merekap jam main dan mencatat transfer. |
| **Tanggal** | 8–9 Nov 2026 |
| **Asal** | Klien korporat (Huawei): blok jadwal tiap Jumat 1 lapangan 19.00–22.00 mulai 20 Nov 2026, dibayar per bulan lewat transfer. Harga per jam (sewa lapangan + 20%) ditentukan dan ditagihkan oleh Finance Club 61. |
| **Dependensi** | Membership (paket, kartu, aturan akses), Jadwal Tetap Mingguan, Kelola Sponsor, Kelola Pemesanan, POS Walk-In, checkout online, Buku Transaksi (LedgerEntry), Analitik |
| **Production** | Sudah live dengan data nyata → **tanpa migrasi**. Pengaturan disimpan di kolom JSON yang sudah ada; kolom string dipakai untuk nilai baru (gateway, sumber buku, tipe order). Paket & kartu lama tidak berubah perilakunya. |

---

## 1. Masalah

Setiap booking wajib dibayar saat itu juga. Klien korporat ingin memakai slot tetap mingguan **dan** booking tambahan, lalu membayar **sekali per bulan lewat transfer**. Harga per jam dan tagihan dibuat oleh **Finance** di luar sistem; Club 61 hanya perlu:
- data jam main yang lengkap per bulan untuk dasar tagihan Finance,
- pencatatan uang transfer perusahaan supaya tetap masuk laporan pendapatan.

## 2. Keputusan (owner, 8–9 Nov 2026)

| # | Topik | Keputusan |
|---|---|---|
| 1 | Tempat pengaturan | **Paket Membership → fasilitas Padel**: centang **"Tagihan Bulanan"**. Hanya untuk paket **Organisasi (Perusahaan / Sponsor)** yang dijual **Khusus Front Desk / Admin**. Kuota otomatis **Tanpa Kuota**, diskon 0. Tidak ada isian harga, waktu penagihan, maupun jatuh tempo. |
| 2 | Siapa yang booking | **Hanya PIC aktif** perusahaan di Kelola Sponsor (bukan pembeli awal, supaya ganti PIC tetap jalan). |
| 3 | Bagi jam ke karyawan | **Tidak bisa** (rilis voucher / impor CSV ditolak di server; portal PIC menampilkan keterangan). |
| 4 | Cara booking | (a) **Jadwal Tetap Mingguan** otomatis, (b) **PIC booking sendiri** di web / aplikasi, (c) **resepsionis** di POS Walk-In. Pada (b) & (c) kartu Tagihan Bulanan tampil **seperti benefit kartu member** (toggle, otomatis aktif untuk PIC): sewa lapangan ditanggung penuh. Tidak ada metode bayar khusus. |
| 5 | Status booking | **Langsung terkonfirmasi Rp0** (seperti booking kuota jam): tiket QR aktif, check-in normal, tanpa batas bayar. |
| 6 | Harga & tagihan | **Ditentukan dan diterbitkan Finance** di luar sistem. Sistem tidak menghitung harga. |
| 7 | Batal / tidak hadir | Batal **sebelum batas waktu reschedule** → keluar dari rekap. **Sudah mulai, lewat batas, atau tidak hadir → tetap tercatat** sebagai jam main. Lewati tanggal / hapus Jadwal Tetap (keputusan Club 61) tidak memakai batas waktu. Pindah jadwal mengikuti aturan reschedule global, tanpa selisih harga. |
| 8 | Sewa alat / F&B / voucher | **Tidak termasuk** Tagihan Bulanan. Sewa alat yang dipesan bersama booking dibayar seperti biasa (online: QRIS / VA; kasir: EDC / QRIS) — sama dengan skema voucher jam corporate. Kode promo mengikuti aturan benefit member biasa. |
| 9 | Pembayaran | Perusahaan **transfer langsung ke rekening Club 61**. Dicatat dari **Kelola Sponsor → "Catat Transfer"** (tabel & halaman Edit Sponsor). Jenis **Tagihan Bulanan**: periode + **nominal manual sesuai invoice Finance**, satu pencatatan per perusahaan per bulan. Jenis **Pembayaran Paket Membership**: transfer untuk paket yang sudah aktif (mis. bayar sekali / cicilan), kartu, kuota, karyawan & jadwal tidak diubah. Keduanya: tanggal transfer, bank pengirim, nomor referensi, **bukti transfer wajib**. Bukan uang kasir → **tanpa shift**, tidak ada di POS. |
| 10 | Membership corporate | **"Berikan Membership Corporate"** di Kelola Sponsor kini punya **Cara Pembayaran**: **Transfer ke Rekening Perusahaan** (nominal = harga paket + pajak sesuai Master Data, isian & bukti transfer sama dengan poin 9) atau **Tanpa Pembayaran** (alur lama, alasan wajib). Berlaku untuk semua corporate. |
| 11 | Laporan | Transfer perusahaan masuk **Riwayat Pembayaran, Buku Transaksi & Analitik** dengan sumber **"Transfer Corporate"**: Tagihan Bulanan → kategori **Sewa Lapangan**, pemberian / pembayaran paket → **Membership**. Booking Rp0 tidak menambah pendapatan. Tidak masuk Rekap Penjualan Harian kasir. |
| 12 | Salah input | **"Batalkan Pencatatan"** (alasan wajib) di halaman Tagihan Bulanan untuk Tagihan Bulanan & Pembayaran Paket: baris pembalik di Buku Transaksi, lalu dicatat ulang. Transfer pemberian membership tidak dapat dibatalkan di sini (kartunya aktif karena pembayaran itu). |
| 13 | Hak akses | Baru: **Catat Pembayaran Transfer Corporate** (`record_corporate_transfer`) — tidak termasuk preset resepsionis. Halaman **Tagihan Bulanan** memakai `View:TagihanBulanan`. |

## 3. Alur

### 3.1 Jadwal Tetap Mingguan
Booking dibuat atas nama PIC aktif, tidak memotong kuota, order Rp0 lunas dengan metode **"Tagihan Bulanan — ditagihkan oleh Club 61"** (gateway `MONTHLY_BILLING`, tanpa shift).

### 3.2 PIC booking online
Ringkasan checkout menampilkan kartu **MEMBER · {perusahaan}** dengan toggle (otomatis aktif untuk PIC kartu aktif yang jam mainnya sesuai ketentuan), keterangan *"Sewa lapangan masuk tagihan bulanan {perusahaan}."*, dan baris **"Tagihan Bulanan {perusahaan} − Rp …"**. Total Rp0 → *"Ditanggung penuh — tidak perlu membayar"* + **Konfirmasi Booking**. Ada sewa alat → alat dibayar online seperti biasa. Toggle dimatikan → harga normal, tidak masuk rekap. Server memeriksa ulang (PIC aktif, masa berlaku, perusahaan aktif, aturan akses). API: tanpa parameter baru — preview mengembalikan `benefit_type = MONTHLY_BILLING`; `membership_balance_id = NONE` mematikannya. Aplikasi Flutter cukup menampilkan benefit dari preview (teks khusus opsional).

### 3.3 Resepsionis (POS Walk-In)
Customer terpilih (Cari Akun) adalah PIC → badge member **"Tagihan Bulanan {perusahaan} • Sewa lapangan masuk tagihan bulanan"** dengan toggle, sama seperti member lain. Sewa lapangan Rp0; sewa alat dibayar EDC / QRIS biasa.
### 3.4 Kelola Pemesanan
Label **"Tagihan Bulanan · {perusahaan}"**. Tombol refund disembunyikan; pembatalan memakai **Batalkan Sesi Tagihan** (batas waktu reschedule). Reschedule tanpa selisih harga.

### 3.5 Halaman "Tagihan Bulanan" (Customer & Membership)
Pilih perusahaan + periode (tanggal main) → total jam, jam reguler / peak (Master Data), status pembayaran, rincian per booking (status Belum Main / Main / Tidak Hadir, sumber Jadwal Tetap / Booking Online PIC / Walk-in Kasir). **Unduh Excel & PDF** untuk Finance (tanpa harga). Riwayat transfer perusahaan (membership & Tagihan Bulanan) dengan unduh bukti dan Batalkan Pencatatan.

### 3.6 Kelola Sponsor
- Tombol **"Catat Transfer"** (tabel & halaman Edit Sponsor, izin `record_corporate_transfer`): jenis Tagihan Bulanan (menampilkan ringkasan jam periode terpilih) atau Pembayaran Paket Membership.
- **"Berikan Membership Corporate"** dengan pilihan cara pembayaran (poin 10).

## 4. Rancangan teknis (tanpa migrasi)

- Penanda paket / kartu: `extra_benefits.monthly_billing = {"enabled": true}` pada fasilitas PADEL; booking ditandai `padel_bookings.membership_balance_id` ke kartu itu.
- `App\Services\Membership\MonthlyBilling`: `recordBookings()` (Jadwal Tetap: booking Rp0 PAID + order Rp0 + `markOrderAsPaid` gateway `MONTHLY_BILLING`), `cancel()` (lewat `requestCancelAndRefund`, refund Rp0), `recap()` (hanya order lunas), `cardForBooker()`, `paymentFor()`.
- Checkout online & POS: `applyMonthlyBillingToCourtBookings()` dijalankan sebelum benefit membership biasa (bentuk hasil sama, `benefit_type = MONTHLY_BILLING`); total Rp0 → metode `MONTHLY_BILLING`.
- `App\Services\Membership\MonthlyBillingPayment::record()` → order `CORPORATE` + item `MONTHLY_BILL` (kategori buku Sewa Lapangan) → `CorporateTransfer::markPaid`.
- `CorporateTransfer::recordPackagePayment()` → order `CORPORATE` + item `MEMBERSHIP` (kategori Membership), `payload_log.corporate_kind = PACKAGE`. `CorporateTransfer::void()` → refund PROCESSED metode `PEMBATALAN_PENCATATAN` + baris buku pembalik, order REFUNDED, `payload_log.voided`.
- `App\Services\Sponsor\CorporateTransfer`: isian form, validasi, cek nomor referensi ganda, bukti di disk privat `local/corporate-transfer` (lewat SecureImageUploader), gateway `CORPORATE_TRANSFER`, riwayat.
- `SponsorOrganizationService::grantCorporateMembershipPaid()`: order MEMBERSHIP → `purchasePlan` → `CorporateTransfer::markPaid` → aktivasi lewat fulfillment.
- Buku Transaksi: sumber baru `CORPORATE_TRANSFER` ("Transfer Corporate"); "kasir" = staf pencatat. Analitik: baris kanal **Transfer Corporate**.
- `MonthlyBillingRecapExport`: Excel (OpenSpout) & PDF (`exports.rekap-tagihan-bulanan-pdf`).

## 5. Tahapan

| Tahap | Isi | Status |
|---|---|---|
| 1 | Penanda paket, Jadwal Tetap atas nama PIC, batal / pindah jadwal, PIC tanpa bagi jam | ✅ dibangun ulang 9 Nov 2026 (Rp0 terkonfirmasi) |
| 2 | PIC booking online + resepsionis di POS Walk-In (sebagai benefit kartu member, 10 Nov 2026) | ✅ |
| 3 | Rekap + Excel / PDF, Catat Transfer (Tagihan Bulanan & Pembayaran Paket) di Kelola Sponsor, membership corporate berbayar, buku & analitik | ✅ 9–10 Nov 2026 |

Yang dihapus dari v0.3: harga paket (Harga Dasar + %, tarif khusus), waktu penagihan & jatuh tempo, batas tagihan, pelunasan di POS Walk-In, Pro Forma / Invoice PDF, baris jatuh tempo di Dashboard, izin `settle_monthly_bill`.

## 6. Uji

`tests/Feature/Membership/MonthlyBillingTest.php` (booking Rp0, check-in, batal, lewati / hapus jadwal, reschedule, checkout PIC & non-PIC, POS resepsionis, PIC tanpa bagi jam, form paket, Terapkan Aturan) dan `tests/Feature/Membership/MonthlyBillingPaymentTest.php` (rekap & jam peak, pencatatan nominal manual + buku + analitik, upload bukti dari Kelola Sponsor, batalkan pencatatan, membership corporate berbayar, halaman + Excel / PDF, hak akses).

## 7. Catatan operasional

- Beri izin **Catat Pembayaran Transfer Corporate** dan **View:TagihanBulanan** di Roles & Hak Akses untuk staf Finance.
- Jadwal Tetap Huawei: atur *weeks_ahead* sesuai kebutuhan rekap (mis. ±6 minggu).
- Aplikasi Flutter: benefit Tagihan Bulanan datang dari preview yang sama dengan kuota member (`benefit_type = MONTHLY_BILLING`); tambahkan teks keterangan bila diperlukan.
- Huawei di produksi saat ini memakai paket kuota 9 jam (Jadwal Tetap 20 Nov – 6 Des). Pemindahan kartu itu ke Tagihan Bulanan menunggu keputusan apakah 9 jam tersebut ikut ditagihkan.
