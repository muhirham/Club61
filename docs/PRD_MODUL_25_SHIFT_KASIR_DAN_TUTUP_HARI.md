# Product Requirements Document (PRD)
## Modul 25: Shift Kasir, Tutup Hari & Settlement
**Club 61 Sports & Social Club Central System**

---

| Metadata Dokumen | Spesifikasi |
| :--- | :--- |
| **Kode Dokumen** | `PRD-MODUL-25-SHIFT-KASIR-DAN-TUTUP-HARI` |
| **Versi** | `v0.1.0-DRAFT` |
| **Status** | **Draft, menunggu jawaban PM (11 Okt 2026).** Alur usulan ada di §4. Empat keputusan PM ada di §5; tiap pertanyaan sudah punya usulan default supaya pengerjaan bisa jalan begitu disetujui. |
| **Sumber Requirement** | Temuan operasional 11 Okt 2026: Riwayat Transaksi F&B menampilkan transaksi 6–11 Okt karena shift F&B tidak pernah ditutup sejak 6 Okt. Operasional: **2 shift per hari di tiap loket**, settlement **1× per hari**. Arahan awal PM: settlement harus diketahui supervisor/atasan. |
| **Dependensi Teknis** | `PRD_MODUL_11_WALK_IN_OFFLINE_BOOKING.md` (Kasir Padel), `PRD_MODUL_15_FNB_MENU_MANAGEMENT.md` (Kasir F&B), `PRD_MODUL_17_BUKU_TRANSAKSI_TERPADU.md` (Buku Transaksi), `PRD_MODUL_10_UNIFIED_PAYMENT_GATEWAY.md` (QRIS / Midtrans). |
| **Prinsip Utama** | **SHIFT = SERAH TERIMA KASIR, TUTUP HARI = SETTLEMENT**; **SATU ATURAN UNTUK SEMUA LOKET**; **TIDAK ADA SHIFT YANG MELEWATI HARI**; **SELISIH SELALU DIKETAHUI ATASAN**. |

---

## 1. Ringkasan

Venue punya dua loket kasir: **Padel** (booking walk-in, pelunasan, jual membership) dan **F&B**. Tiap loket berganti kasir 2× sehari, dan semua pembayaran non-tunai (EDC BCA/Mandiri, QRIS, QRIS Otomatis Midtrans).

Usulannya memisahkan dua hal yang sekarang tercampur:

| | Tutup Shift | Tutup Hari |
| :--- | :--- | :--- |
| Kapan | Tiap ganti kasir (2× sehari per loket) | Sekali sehari, setelah semua shift ditutup |
| Oleh | Kasir yang selesai bertugas | Supervisor / atasan *(menunggu PM — §5 no. 3)* |
| Isi | Serah terima: jumlah transaksi & total per metode bayar shift itu, laporan shift dicetak | **Settlement mesin EDC**, cek mutasi QRIS & dashboard Midtrans, cocokkan dengan total sistem hari itu, selisih wajib dijelaskan |
| Hasil | Shift tertutup, kasir berikutnya buka shift baru | Tanggal bisnis itu final dan masuk laporan harian |

---

## 2. Kondisi Sekarang (per 11 Okt 2026)

| Bagian | Kasir Padel & Jual Membership | Kasir F&B |
| :--- | :--- | :--- |
| Loket (shift) | `PADEL_FRONTDESK` — Padel, pelunasan & membership memakai **shift yang sama** | `FNB_COUNTER` |
| Buka shift | Ada, satu shift terbuka per loket | Ada, satu shift terbuka per loket |
| Tutup shift | Ringkasan penjualan + catatan, **tanpa** input settlement | **Wajib** mengetik angka settlement per kategori (tiap mesin EDC debit/kredit, tiap QRIS), sistem menghitung selisih |
| Riwayat Transaksi | Per tanggal, default hari ini | Per **shift yang sedang terbuka**; kalau tidak ada shift terbuka → **50 transaksi terakhir dari semua hari** |
| Batas umur shift | Tidak ada | Tidak ada |
| Tutup Hari | Tidak ada | Tidak ada |

Masalah yang terjadi:
1. **Shift bisa terbuka berhari-hari.** Shift `SFT-FNB-20261006-0001` dibuka 6 Okt dan belum ditutup sampai 11 Okt, sehingga transaksi 5 hari dihitung sebagai satu shift dan semuanya muncul di Riwayat F&B.
2. **Setelah shift ditutup, Riwayat F&B tetap menampilkan transaksi lama** (jatuh ke 50 transaksi terakhir).
3. **Aturan tutup shift beda antar loket**: F&B minta angka settlement, Padel tidak.
4. **Settlement diminta di tiap tutup shift**, padahal settlement mesin EDC menutup batch mesin dan dilakukan sekali sehari. Dengan 2 shift per hari, kasir shift pertama tidak punya angka settlement untuk shiftnya saja.
5. **Tidak ada titik "hari ini selesai"** yang diketahui atasan.

---

## 3. Praktik POS Pada Umumnya

- **Laporan shift (X / Z report per kasir):** tiap kasir menutup shiftnya sendiri dan mendapat laporan shift. Satu loket dengan beberapa shift per hari punya laporan per shift. ([Lightspeed](https://shopkeep-support.lightspeedhq.com/support/reporting/z-and-x-reports), [Fred POS](https://cx.fred.com.au/hc/en-au/articles/13618566838031-X-and-Z-Reports-Fred-POS-Plus), [MobileTransaction](https://www.mobiletransaction.org/what-is-an-x-vs-z-report/))
- **End of Day (Tutup Hari):** sekali sehari. Penjualan tanggal itu difinalkan, batch kartu / EDC di-settle, lalu rekonsiliasi non-tunai, refund, void dan pembatalan. ([Xenial](https://www.xenial.com/product-documentation/en/genius-point-of-sale/enterprise-pos/enterprise-pos-app/manager-procedures/end-of-day-functions.html), [HashMicro — EOD Kasir](https://www.hashmicro.com/id/blog/pengertian-eod-kasir/), [Comarch POS](https://help.comarch.com/pos/?p=296))
- **Settlement EDC menutup batch mesin.** Banyak mesin juga bisa mencetak ringkasan batch berjalan **tanpa** settle. Fiturnya berbeda per mesin, jadi mesin BCA/Mandiri venue perlu dicek. ([Zoho](https://www.zoho.com/in/payments/help/pos/reports/), [PayFacto](https://docs.payfacto.com/payfacto-knowledge/canada-doc-center/applications/secure-payment/payment-standalone-mode/terminal-configuration/automatic-report-printing))

---

## 4. Alur Usulan

### 4.1 Hari Bisnis
- Satu hari bisnis dimulai pada **jam buka paling awal** dari lapangan aktif. Jam ini diambil dari jam operasional yang sudah diisi di admin (Kelola Lapangan, kolom jam buka / jam tutup per lapangan).
  Contoh: lapangan buka 06:00 → transaksi 11 Okt 00:30 masih masuk hari bisnis **10 Okt**.
- F&B mengikuti hari bisnis yang sama (F&B belum punya jam operasional sendiri).

### 4.2 Tutup Shift (serah terima, tiap loket, 2× sehari)
1. Kasir menekan **Tutup Shift**.
2. Sistem menampilkan jumlah transaksi dan total per metode bayar shift itu (per mesin EDC, per QRIS, QRIS Otomatis).
3. Kasir mencocokkan dengan tumpukan struk EDC (salinan merchant), atau ringkasan EDC yang dicetak **tanpa settle** kalau mesinnya mendukung.
4. Kalau ada yang tidak cocok, kasir wajib menulis catatan.
5. Laporan shift dicetak, shift tertutup, kasir berikutnya membuka shift baru.

Tidak ada settlement mesin di langkah ini. Aturan yang sama berlaku di loket Padel dan F&B.

### 4.3 Tutup Hari (settlement, sekali sehari)
1. Syarat: **semua shift di semua loket sudah ditutup** dan tidak ada Bayar Otomatis (QR/VA) yang masih menunggu.
2. Supervisor melakukan **settlement di tiap mesin EDC**, mengecek mutasi QRIS dan dashboard Midtrans.
3. Supervisor mengetik angka settlement **per mesin EDC dan per QRIS**. Sistem mencocokkan dengan total sistem hari bisnis itu:
   - **Mesin dipakai bersama** Padel & F&B → dicocokkan dengan total gabungan kedua loket.
   - **Mesin per loket** → dicocokkan dengan total loket pemilik mesin.

   Tutup Hari di tingkat venue bisa melayani kedua skema; yang membedakan hanya data "mesin ini milik loket mana" (§5 no. 1).
4. Selisih wajib diberi penjelasan. Laporan harian bisa dicetak / diunduh.
5. Hari bisnis itu ditandai **final**: tampil di laporan & Buku Transaksi sebagai hari yang sudah di-settle.

### 4.4 Pengaman
| Kondisi | Perilaku |
| :--- | :--- |
| Shift dibuka di hari bisnis sebelumnya dan belum ditutup | Kasir **tidak bisa bertransaksi** sampai shift itu ditutup. Banner: *"Shift kemarin (SFT-…) belum ditutup — tutup dulu sebelum melayani transaksi hari ini."* |
| Hari bisnis kemarin belum Tutup Hari | Banner peringatan di layar kasir dan dashboard admin. Transaksi hari ini **tetap boleh** supaya operasional tidak berhenti. |
| Tutup Hari ditekan saat masih ada shift terbuka | Ditolak, dengan daftar shift yang masih terbuka. |
| Transaksi masuk setelah Tutup Hari (mis. Midtrans terlambat) | Masuk ke hari bisnis berikutnya, dengan tanda "terlambat" di laporan. |

### 4.5 Riwayat Transaksi di Layar Kasir (semua loket)
- Default: **hanya transaksi shift yang sedang berjalan**. Setelah shift ditutup, daftarnya kosong untuk kasir berikutnya.
- Melihat hari / shift lain: mengikuti keputusan PM (§5 no. 4).
- Riwayat lengkap tetap ada di admin (**Buku Transaksi**, filter per shift sudah tersedia).

---

## 5. Pertanyaan untuk PM

| # | Pertanyaan | Usulan default | Dampak kalau dijawab lain |
| :--- | :--- | :--- | :--- |
| 1 | Mesin EDC BCA / Mandiri **dipakai bersama** loket Padel dan F&B, atau **tiap loket punya mesin sendiri**? | Tiap loket punya mesin sendiri | Kecil — hanya data kepemilikan mesin. Alur §4.3 tetap sama. |
| 2 | Hari bisnis berganti di **jam buka paling awal** lapangan (mis. 06:00)? | Ya, ikut jam operasional di admin | Kecil — jam ganti bisa dibuat pengaturan sendiri. |
| 3 | Siapa yang boleh **Tutup Hari**? (a) Supervisor / admin saja; (b) kasir shift terakhir boleh menutup, tapi supervisor wajib **menyetujui** sebelum hari final. | (a) Supervisor / admin saja | Sedang — (b) butuh status "menunggu persetujuan" + notifikasi ke supervisor. |
| 4 | Kasir boleh melihat **Riwayat Transaksi** shift / hari lain (untuk cetak ulang struk)? (a) Tidak, hanya shift berjalan; atasan yang membuka transaksi lama; (b) Boleh, lewat pilih tanggal, hanya untuk cetak ulang. | (a) Tidak — hanya shift berjalan | Kecil — keduanya diatur lewat hak akses per role. |
| 5 | Saat Tutup Shift, kasir melihat angka sistem dulu, atau **mengetik dulu baru ditunjukkan selisihnya** (*blind close*, mencegah kasir "menyesuaikan" angka)? | Lihat angka sistem (cukup untuk serah terima non-tunai) | Kecil. |
| 6 | Perlu role baru **Supervisor** (sekarang ada: super_admin, admin, cashier, receptionist, kitchen)? | Ya — role Supervisor dengan izin Tutup Hari & melihat riwayat semua shift | Kecil — atau izin Tutup Hari diberikan ke role admin. |

---

## 6. Perbaikan yang Bisa Dikerjakan Sekarang (tanpa menunggu PM)

| Perbaikan | Alasan aman dikerjakan dulu |
| :--- | :--- |
| Riwayat F&B tidak lagi jatuh ke "50 transaksi terakhir semua hari" saat tidak ada shift terbuka — default shift berjalan, kalau tidak ada → kosong / hari ini. | Bug, tidak bergantung keputusan PM. |
| Banner peringatan di layar kasir kalau shift yang terbuka dibuka di hari sebelumnya. | Hanya peringatan; pemblokiran transaksi menunggu persetujuan §4.4. |
| Tutup shift `SFT-FNB-20261006-0001` yang masih terbuka sejak 6 Okt. | Operasional — dilakukan kasir / admin dari layar kasir F&B. |

---

## 7. Rencana Implementasi (setelah jawaban PM)

| Tahap | Isi | Perkiraan |
| :--- | :--- | :--- |
| 1 | Hari bisnis (dari jam operasional), banner & blokir shift lewat hari, Riwayat kasir per shift di semua loket | ± 1 hari |
| 2 | Tutup Shift seragam (serah terima + laporan shift) di loket Padel & F&B; input settlement dipindah dari tutup shift | ± 1 hari |
| 3 | Tutup Hari: data mesin EDC per loket, form settlement per mesin / QRIS, cek semua shift tertutup, selisih + catatan, laporan harian, tanda "hari final" | ± 2 hari |
| 4 | Role Supervisor / hak akses Tutup Hari & riwayat, persetujuan (kalau PM memilih §5 no. 3b) | ± ½–1 hari |
| 5 | Pengujian otomatis + uji coba dengan kasir (simulasi 2 shift + Tutup Hari) | ± 1 hari |

---

## 8. Catatan Teknis untuk Tim Dev

- Shift: `App\Models\Pos\PosCashierShift` (`pos_cashier_shifts`), loket `PADEL_FRONTDESK` & `FNB_COUNTER`. Pembayaran kasir terikat ke shift lewat `payments.pos_shift_id` (diisi `PaymentOrchestratorService::markOrderAsPaid`).
- Rincian settlement per kategori sudah ada: `PosCashierShift::settlementBreakdown()` (per mesin EDC debit/kredit, per penyedia QRIS, QRIS Otomatis). Bisa dipakai ulang untuk Tutup Hari dengan menggabungkan semua shift dalam satu hari bisnis.
- Tutup shift sekarang: `FnbCashierTerminal::executeCloseShift()` (dengan rekonsiliasi), `BookOfflineCourt::executeCloseShift()` (tanpa rekonsiliasi).
- Riwayat: `FnbCashierTerminal::getOrderHistoryProperty()` (per shift / 50 terakhir), `BookOfflineCourt::getTransactionHistoryProperty()` & `JualMembership::getTransactionHistoryProperty()` (per tanggal).
- Jam operasional: `padel_courts.open_time` / `close_time` per lapangan.
- Tutup Hari butuh tabel baru (mis. `pos_business_days`: tanggal bisnis, status, ditutup oleh, waktu, rekonsiliasi per mesin, total selisih, catatan) dan data mesin EDC (nama, bank, loket pemilik).
