# PRD Modul 31 — Tagihan Coaching (Tagihan Berkala per Academy)

| | |
|---|---|
| **Versi** | v0.5 (Tahap 1–3 dibangun 31 Okt 2026; reschedule sesi tagihan 1 Nov 2026) |
| **Tanggal** | 30 Okt 2026 |
| **Asal** | Meeting training 28 Okt 2026 (PRD Modul 28 §3.3, Batch 4) + WA klien: *"Untuk bookingan coaching bisa dibantu bill gantung per shift? Jangan per transaksi. Soalnya jika bookingan coaching masuk, klo per transaksi harus qris, susah untuk resepsionis bantu booking."* |
| **Dependensi** | Coaching Opsi B (Modul 24), POS Walk-In (Modul 11), Shift Kasir & Tutup Shift (Modul 25), Buku Transaksi (LedgerEntry), Template Invoice |
| **Production** | Sudah live dengan data nyata → migrasi **hanya menambah**, academy lama tetap berperilaku seperti sekarang sampai diubah admin. |

---

## 1. Masalah

Sekarang booking coaching di POS Walk-In (tab Coaching) **wajib dibayar saat itu juga** (QRIS / EDC). Academy (mis. SR Academy) bisa booking banyak sesi per hari, jadi resepsionis harus menagih QRIS tiap transaksi. Klien minta tagihan bisa **digantung** lalu dibayar sekaligus.

## 2. Keputusan (sudah disetujui owner, 30 Okt 2026)

| # | Topik | Keputusan |
|---|---|---|
| 1 | Cara bayar | Diatur **per academy**: *Bayar Langsung* (setiap booking, seperti sekarang) atau *Tagihan Berkala*. Academy baru default **Tagihan Berkala – Per Shift**. |
| 2 | Periode Tagihan Berkala | **Per Shift / Harian / Mingguan / Dua Mingguan** — tinggal diatur. |
| 3 | Batal / tidak datang / pindah jadwal | Ikut aturan batas reschedule/batal global. Batal sebelum batas → sesi keluar dari tagihan (tanpa refund, karena belum dibayar). Lewat batas / tidak datang → **tetap ditagih**. Pindah jadwal (1 Nov 2026): sebelum batas waktu, maks. 3x; tagihan ikut tarif jadwal baru (naik / turun), tidak ada selisih yang ditagih atau hangus, jatuh tempo dihitung ulang. |
| 4 | Tagihan lewat jatuh tempo | Selalu **peringatan** ke resepsionis. Ada sakelar per academy **"Blok booking baru bila ada tagihan lewat jatuh tempo"** (default mati). |
| 5 | Pelunasan | **Satu tombol "Pelunasan Tagihan Coaching"** di POS Walk-In. Metode: QRIS, EDC, dan **Transfer Bank (baru)** dengan **wajib unggah bukti transfer** + nomor referensi. Boleh melunasi sebagian sesi. |
| 6 | Uang masuk ke mana | Tetap aturan lama: **uang hanya diterima di kasir POS**. Pelunasan masuk ke **Tutup Shift POS Walk-In Padel di shift saat dibayar** (bukan shift saat booking dibuat). Buku Transaksi kategori **Coaching**, tanggal = tanggal bayar. |
| 7 | Invoice | **PDF** dari halaman Tagihan Coaching memakai **template invoice yang sudah ada**; admin mengirim email ke academy secara manual. |

## 3. Alur

### 3.1 Booking (resepsionis, POS Walk-In → tab Coaching)
1. Pilih coach → sistem tahu academy-nya → cara bayar academy.
2. **Bayar Langsung:** alur lama, tidak berubah.
3. **Tagihan Berkala:** setelah slot & coach dipilih, tombol bayar diganti **"Catat ke Tagihan Berkala"**. Tidak ada langkah bayar.
   - Booking terkunci (tidak dilepas otomatis), status tampil **"Tagihan Berkala"**, coach bisa langsung check-in.
   - Bila academy punya tagihan lewat jatuh tempo: muncul peringatan (total & jumlah sesi). Bila sakelar blok aktif → booking ditolak di server, dengan pesan mengarahkan ke pelunasan.

### 3.2 Jatuh tempo per periode
| Periode | Sesi dikelompokkan menurut | Jatuh tempo |
|---|---|---|
| Per Shift | shift kasir saat booking dibuat | saat shift itu ditutup — layar Tutup Shift memberi peringatan bila masih ada tagihan academy dari shift itu |
| Harian | tanggal main | akhir hari tanggal main (23:59 WIB) |
| Mingguan | minggu tanggal main (Senin–Minggu) | Minggu 23:59 WIB |
| 2 Mingguan | 2 minggu berjalan sejak **tanggal mulai siklus** academy | akhir siklus |

Jatuh tempo hanya untuk peringatan / blok & isi invoice. Resepsionis **boleh melunasi kapan saja** (sebelum jatuh tempo pun boleh).

### 3.3 Pelunasan (POS Walk-In → "Pelunasan Tagihan Coaching")
1. Pilih academy → daftar semua sesi belum lunas (tanggal, jam, lapangan, coach, tarif), dikelompokkan per periode; default semua tercentang.
2. Pilih metode:
   - **QRIS / EDC:** isian bukti seperti sekarang (RRN / approval code).
   - **Transfer Bank:** unggah bukti (gambar, disimpan di disk privat seperti nota Pengeluaran F&B) + nomor referensi + nama pengirim.
3. Simpan → satu **Pelunasan** (nomor `TC-yymmdd-XXXXX`) yang melunasi semua sesi terpilih sekaligus. Struk pelunasan bisa dicetak.

### 3.4 Tutup Shift
- QRIS / EDC pelunasan masuk baris metode masing-masing (EDC tetap per batch mesin) — dihitung seperti transaksi lain.
- **Transfer Bank** muncul sebagai baris **"Transfer Bank — cek mutasi rekening"** yang **tidak diketik kasir** (uangnya tidak lewat mesin di meja kasir), sama seperti baris pembayaran online. Admin / finance mencocokkan ke mutasi bank; bukti bisa dibuka dari Buku Transaksi & Tagihan Coaching.
- Periode **Per Shift**: peringatan bila ada tagihan academy dari shift ini yang belum lunas (tidak memblok tutup shift).

### 3.5 Halaman admin "Tagihan Coaching"
- Filter academy & periode. Per academy: sesi, status (Belum Lunas / Lunas / Batal), total belum lunas, jatuh tempo, riwayat pelunasan + bukti.
- **Unduh Invoice PDF** per academy per periode (template invoice yang ada).
- Tidak ada tombol terima uang di halaman ini (aturan: uang hanya di kasir) — tautan "Lunasi di POS Walk-In".

### 3.6 Pengaturan academy (menu Coaching → Academy)
- Metode Pembayaran: Bayar Langsung / Tagihan Berkala.
- Periode: Per Shift / Harian / Mingguan / Dua Mingguan (+ tanggal mulai siklus untuk 2 Mingguan).
- Sakelar "Blok booking baru bila ada tagihan lewat jatuh tempo".
- Perubahan cara bayar hanya berlaku untuk booking **berikutnya**; sesi yang sudah masuk tagihan tetap di tagihannya.

## 4. Rancangan teknis

### 4.1 Prinsip
- **Alur Bayar Langsung tidak diubah sama sekali.**
- Booking "Tagihan Berkala" tetap memakai status yang sudah ada **`PENDING_PAYMENT`** (jujur: memang belum dibayar) dengan order `UNPAID` seperti checkout biasa — sehingga cek bentrok slot, jadwal, monitoring, dan pembatalan tanpa refund otomatis tetap benar tanpa menyentuh ±30 file yang memakai daftar status. Penandanya: `coaching_bookings.billing = TAB`.
- Pengecualian khusus booking bertanda TAB (dicek satu helper, bukan tersebar):
  1. **Pembersih otomatis** booking tidak dibayar → **dilewati** (tidak dibatalkan / dilepas).
  2. **Check-in** → diizinkan walau belum lunas.
  3. **Reschedule** → Kelola Pemesanan seperti booking lain (izin `reschedule_padel_booking`, batas waktu global, maks. 3x, coach harus kosong). Karena belum dibayar, hitungan selisih bayar / hangus tidak berlaku: tarif sesi diganti tarif coaching jadwal baru, item order ikut, total order dihitung ulang (pajak & biaya layanan sekali per transaksi), status tetap `PENDING_PAYMENT`, jatuh tempo dihitung ulang dari jam main paling awal (Per Shift tetap shift saat dicatat). Order dikunci sebelum sesi, sama dengan pelunasan & batal.
  4. **Bayar online** (invoice customer / gateway) → disembunyikan & ditolak di server; pelunasan hanya lewat POS.
- **Pelunasan banyak sesi = satu pembayaran per order** (order checkout yang sudah ada), semua di-link ke satu Pelunasan dan memakai bukti yang sama. Dengan begitu Buku Transaksi, laporan, refund, dan rekap Tutup Shift tetap memakai jalur `PaymentOrchestratorService` yang sama. Cek "bukti dipakai dua kali" dikecualikan **hanya** di dalam pelunasan yang sama.

### 4.2 Migrasi baru (hanya menambah)
| Tabel | Perubahan |
|---|---|
| `coaching_academies` | `billing_mode` (PER_BOOKING / TAB, default **PER_BOOKING** untuk data lama), `billing_cycle` (SHIFT / DAILY / WEEKLY / BIWEEKLY, nullable), `billing_anchor_date` (nullable, untuk 2 Mingguan), `block_when_overdue` (boolean, default false) |
| `coaching_bookings` | `academy_id` (nullable — sekarang hanya ada `academy_name`), `billing` (PER_BOOKING / TAB, default PER_BOOKING), `billing_cycle` (snapshot), `cashier_shift_id` (nullable, untuk Per Shift), `due_at` (nullable), `settlement_id` (nullable) |
| **baru** `coaching_settlements` | nomor `TC-…` unik, academy_id, metode, total, jumlah sesi, nomor referensi, nama pengirim, `proof_path` (disk privat), shift kasir, dibuat oleh, waktu; void (alasan, oleh, waktu) |

Academy lama tetap **Bayar Langsung** sampai admin mengubahnya. Default "Tagihan Berkala – Per Shift" berlaku untuk academy **baru**.

### 4.3 Izin baru
| Izin | Untuk | Preset |
|---|---|---|
| `settle_coaching_bill` | Pelunasan Tagihan Coaching di POS Walk-In | resepsionis, admin |
| `View:TagihanCoaching` | Halaman Tagihan Coaching + unduh invoice | admin |
| (memakai `manage_coaching`) | Pengaturan cara bayar academy | sudah ada |

### 4.4 Titik yang disentuh (dicek satu per satu saat membangun)
- `ManagesCheckoutAndPayments::processWalkInCheckout` — cabang "Catat ke Tagihan Berkala" (tanpa pembayaran), cek blok jatuh tempo.
- `ManagesScheduleAndSlots` pembersih booking kedaluwarsa — kecualikan TAB.
- `ManagesCheckInAndTurnstile::checkIn` — izinkan TAB belum lunas.
- `ManagesRescheduleAndCashier` — reschedule TAB: tagihan ikut tarif jadwal baru (`CoachingBillingService::repriceForReschedule` + `refreshDueAt`).
- `releaseSlots` (keranjang customer) — sesi TAB dilewati; pembatalan hanya lewat `CoachingBillingService::cancelTab` (izin `book_coaching`, batas waktu global).
- Kelola Pemesanan — label "Masuk Tagihan · academy", tombol Batalkan Sesi Tagihan, tombol cek gateway disembunyikan untuk TAB.
- Invoice customer / `OnlineGatewayRouter` — tolak bayar online untuk TAB.
- `PosPaymentProof` — pengecualian bukti sama dalam satu pelunasan.
- `PosCashierShift::categorize` + `ShiftClosingService` — metode `BANK_TRANSFER` = baris informasi; peringatan Per Shift.
- `BookOfflineCourt` (+ blade) — tombol "Catat ke Tagihan Berkala", "Pelunasan Tagihan Coaching", modal pelunasan.
- Kelola Pemesanan / Monitoring / Jadwal Coaching — label "Tagihan Berkala".
- Halaman baru `TagihanCoaching` + PDF invoice; Log Aktivitas untuk simpan tagihan, pelunasan, void pelunasan.

## 5. Tahapan
| Tahap | Isi |
|---|---|
| **1** | Migrasi + pengaturan academy; booking "Catat ke Tagihan Berkala" (status, pengecualian pembersih / check-in / reschedule / bayar online); peringatan & blok jatuh tempo; tes. |
| **2** | "Pelunasan Tagihan Coaching" di POS (QRIS / EDC / Transfer + bukti), Tutup Shift (baris transfer + peringatan Per Shift), struk pelunasan; tes. |
| **3** | Halaman Tagihan Coaching + Invoice PDF (template yang ada), label status di halaman lain, Log Aktivitas; tes + full suite. |

## 6. Tes yang wajib ada
- Academy Bayar Langsung: alur lama tidak berubah (test lama tetap hijau).
- Booking TAB tidak dilepas pembersih otomatis, bisa check-in, slotnya tetap terkunci, tidak bisa dibayar online.
- Batal sebelum batas → keluar dari tagihan tanpa refund; lewat batas → tetap ditagih.
- Peringatan & blok jatuh tempo per periode (Per Shift, Harian, Mingguan, 2 Mingguan) — server menolak walau tombol disembunyikan.
- Pelunasan sebagian & penuh: semua sesi terpilih lunas, satu nomor pelunasan, Buku Transaksi kategori Coaching, Tutup Shift: QRIS/EDC terhitung, Transfer = baris informasi.
- Bukti transfer di disk privat, hanya bisa dibuka pemegang izin; bukti QRIS/EDC tidak bisa dipakai lagi di transaksi lain.
- Izin dicek di server untuk setiap aksi.

## 7. Catatan
- Kelas kids "per class" tetap ditunda (PRD Modul 28 Q9). Angka tarif 120 / 80 / 170 diisi di Tarif Setoran setelah final.
- Rekap per academy / coach di Analytics tetap menghitung per sesi (tidak bergantung pada tanggal bayar).

## Riwayat
| Versi | Tanggal | Catatan |
|---|---|---|
| v0.1 | 30 Okt 2026 | Rencana awal dari keputusan owner (cara bayar per academy, periode, batal ikut aturan global, peringatan + sakelar blok, Transfer Bank + bukti, invoice PDF template lama). |
| v0.2 | 31 Okt 2026 | Tahap 1 dibangun (migrasi `2026_10_31_100001`). Reschedule sesi tagihan = batal sebelum batas + booking ulang. Izin `settle_coaching_bill` & `View:TagihanCoaching` sudah didaftarkan di migrasi yang sama. |
| v0.3 | 31 Okt 2026 | Tahap 2 dibangun: Pelunasan Tagihan Coaching di POS (QRIS / EDC BCA / Transfer Bank + foto bukti), satu pembayaran per order dalam satu pelunasan, baris info transfer di Tutup Shift **khusus pelunasan coaching** (transfer selisih reschedule tidak berubah), peringatan Per Shift. |
| v0.4 | 31 Okt 2026 | Tahap 3 dibangun: halaman Tagihan Coaching + riwayat pelunasan + bukti transfer, Invoice PDF (sesi belum lunas, template invoice yang sama dengan isian opsional), sesi tagihan tampil sebagai booking di POS / Monitoring / Mingguan / Jadwal Coaching. |
| v0.5 | 1 Nov 2026 | Sesi tagihan bisa di-reschedule dengan aturan global (tagihan ikut tarif baru, tanpa selisih / hangus, jatuh tempo dihitung ulang). Academy tidak bisa dihapus selama masih punya sesi belum lunas. Bukti transfer di modal pelunasan menampilkan pratinjau foto + nama file. Omzet Walk-In di POS hanya order lunas. |
