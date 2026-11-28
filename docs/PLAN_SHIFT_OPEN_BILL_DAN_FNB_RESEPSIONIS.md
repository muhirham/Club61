# Rencana: Aturan Shift, Tutup Otomatis, F&B di Resepsionis, Open Bill, Z-Report per POS

Status: **DRAFT untuk diskusi** (27 Nov 2026), belum ada yang dikerjakan. Bagian bertanda **Perlu keputusan** menunggu
jawaban PM / owner; isi "Usulan" = saran tim dev kalau tidak ada keberatan.

Latar: permintaan PM —
1. Akun yang membuka shift harus akun yang menutup.
2. Shift yang lupa ditutup ditutup otomatis (jam 00:00), kecuali ada tagihan menggantung / transaksi belum masuk / selisih.
3. Resepsionis bisa menjual F&B dari aplikasi yang sama dengan POS Padel (tidak buka 2 aplikasi), stok tetap satu.
4. Fitur open bill.
5. Struk tutup shift berisi total penjualan + daftar transaksinya.

---

## 0. Kondisi sistem sekarang (sudah berjalan)

| Bagian | Sekarang |
|---|---|
| Loket | **Frontdesk Padel** (POS Walk-In, Kasir Pro Shop, Jual Membership — satu shift) dan **Kasir F&B** (`/pos`). Akan dipecah: setiap POS shift sendiri (bagian 1). |
| Buka shift | Siapa saja yang punya izin `open_pos_shift`. Satu loket hanya boleh 1 shift terbuka. |
| Tutup shift | Siapa saja yang punya izin `close_pos_shift` — **tidak harus pembukanya**. Blind: kasir mengetik angka struk settlement EDC / aplikasi QRIS; selisih → Perlu Review admin. |
| Lupa tutup | Shift tetap terbuka; kasir berikutnya langsung memakai shift itu dan melihat peringatan "Shift … dibuka sejak …". |
| Tutup otomatis | Tidak ada. |
| F&B | Hanya di `/pos` (layar terpisah). Resepsionis bawaannya tidak punya izin F&B. |
| Bayar F&B | **Wajib lunas dulu.** Slip dapur/bar & potong stok baru terjadi setelah lunas (`FnbFulfillmentHandler`). Tidak ada bill sementara / open bill. |
| Z-Report | Rekap per metode bayar (sistem vs struk), per jenis transaksi (Frontdesk), total omset & selisih. **Tidak ada** daftar transaksi per nota / rekap menu terjual. |

---

## 1. Shift: setiap POS berdiri sendiri, yang membuka = yang menutup

**Keputusan PM (27 Nov)**
- **Setiap POS punya shift sendiri**, dengan buka & tutup masing-masing: POS Padel (Walk-In), POS F&B, Kasir Pro Shop
  (merchandise), Jual Membership — dan POS baru nanti (F&B Resepsionis, Wellness/Gym). Sekarang Padel + Pro Shop +
  Membership masih satu shift Frontdesk → dipisah.
- Shift tercatat atas nama pembukanya. Selama shift itu terbuka, **akun lain tidak bisa bertransaksi di POS itu**.
  Contoh: Irham buka shift F&B hari Rabu → kasir F&B lain tidak bisa memasukkan transaksi F&B.
- Irham lupa tutup → Kamis Andre login sebagai kasir → **Andre tidak bisa bertransaksi**. Pesannya menyebut nomor shift
  **dan nama pembukanya**: *"Shift SFT-FNB-…-0001 milik Irham belum ditutup. Transaksi tidak bisa dimasukkan sampai shift
  ini ditutup."*
- **Menutup shift orang lain** (mis. Andre menutup shift Irham) hanya bisa dengan:
  - **sandi akun Irham** (pembuka shift) dimasukkan di layar itu, atau
  - **Tutup Paksa** oleh **admin / super_admin**: masukkan sandi admin. Wajib alasan.
  Keduanya tercatat di Log Aktivitas dan tampil di Z-Report ("Ditutup oleh … atas nama Irham" / "Ditutup paksa oleh … —
  alasan …").
- Masuk **Rekap Shift Kasir**: kolom POS, pembuka, penutup, cara ditutup (normal / dengan sandi pembuka / paksa admin /
  otomatis).

**Usulan teknis**
- Akun lain **tetap bisa login** (supaya bisa memakai menu lain, mis. jadwal / check-in), hanya **layar transaksi POS itu
  yang terkunci**. Kalau PM memang mau akun lain tidak bisa login sama sekali selama shift terbuka, sebutkan — tapi itu
  juga mengunci menu non-kasir mereka.
- Isian sandi memakai pengecekan sandi akun biasa (tidak disimpan, salah berkali-kali → dikunci sementara seperti login).
- Kalau pembuka shift = satu-satunya kasir yang bertugas, alurnya tidak berubah: dia buka, dia tutup.

**Perlu keputusan**
- 1a. **EDC / QRIS dipakai bersama** beberapa POS di meja frontdesk (Padel, Pro Shop, Membership): struk settlement EDC
  keluar **per mesin, tidak bisa dipecah per POS**. Kalau setiap POS tutup shift sendiri-sendiri, angka yang diketik kasir
  per POS tidak bisa dicocokkan ke struk settlement. Pilihan:
  - (i) Penutupan per POS **tanpa mengetik angka EDC** (angka sistem + daftar transaksi saja); pencocokan settlement EDC
    dilakukan sekali per mesin di **Tutup Hari** / oleh admin — *usulan*;
  - (ii) Kasir mencocokkan per transaksi (centang slip EDC satu per satu) per POS;
  - (iii) Setiap POS punya mesin EDC / QRIS sendiri (hardware).
- 1b. Kasir yang sama memegang beberapa POS sekaligus (mis. resepsionis jual booking + merch + membership): harus buka
  3 shift? — *usulan: ya, tapi satu tombol "Buka semua POS saya" untuk memudahkan.*

---

## 2. Tutup otomatis shift yang lupa ditutup

**Masalah dasar:** tutup shift itu blind — angka struk EDC / QRIS manual harus diketik manusia. Jam 00:00 tidak ada yang
mengetik, jadi sistem **tidak bisa tahu ada selisih atau tidak** untuk transaksi EDC / QRIS manual.

**Usulan aturan**
- Tugas terjadwal tiap malam (jam bisa diatur di `.env`) memeriksa setiap shift yang masih terbuka sejak hari sebelumnya.
- **Ditutup otomatis** hanya jika SEMUA terpenuhi:
  - tidak ada QR / VA otomatis yang masih menunggu bayar;
  - tidak ada open bill yang belum dibayar (lihat bagian 4);
  - tidak ada tagihan Per Shift coaching yang belum dibayar;
  - semua transaksinya sudah terverifikasi sistem (QRIS otomatis, VA, kuota/voucher) **atau** shift kosong.
- Selain itu **tidak ditutup**, tetapi ditandai **"Lupa ditutup"**: muncul di Rekap Shift Kasir + notifikasi ke supervisor
  → supervisor memakai Tutup Paksa.
- Z-Report shift yang ditutup otomatis bertanda "DITUTUP OTOMATIS oleh sistem".

**Perlu keputusan**
- 2a. Shift berisi transaksi EDC / QRIS manual (hampir setiap hari): **tidak ditutup + notifikasi** (usulan, sesuai syarat PM
  "tidak auto kalau ada selisih"), atau **tetap ditutup dengan status "Perlu Review"** dan admin mengisi angka struk
  belakangan (fitur Koreksi Angka Struk)?
- 2b. Jam: venue buka sampai 24:00, kasir bisa masih menyelesaikan transaksi terakhir. — *Usulan: bisa diatur, bawaan 02:00.*

---

## 3. F&B di Resepsionis

**Keputusan yang sudah diambil**
- Menu F&B dapat 2 centang: **Tampil di Kasir F&B** dan **Tampil di Resepsionis** (boleh dua-duanya).
- Menu yang sudah ada: tetap tampil di Kasir F&B, **belum** tampil di Resepsionis — admin mencentang sendiri.
- Perubahan database disetujui (kolom baru, hanya menambah).
- Slip pesanan otomatis ke printer stasiun sesuai stasiun menunya (kopi → Bar, makanan → Kitchen), sama seperti kasir F&B.

**Rencana**
- Halaman baru **Kasir F&B** di panel admin (sidebar yang sama dengan POS Walk-In, Kasir Pro Shop, Jual Membership) —
  resepsionis cukup satu aplikasi, pindah lewat menu.
- Mengikuti keputusan bagian 1 (setiap POS berdiri sendiri): **F&B Resepsionis punya shift sendiri** (buka & tutup
  sendiri, masuk Rekap Shift Kasir sebagai POS "F&B Resepsionis"). Lihat 1a soal EDC yang dipakai bersama.
- Stok: satu stok global per menu (sudah begitu sekarang).
- Laporan F&B (omzet, HPP) ikut menghitung penjualan dari resepsionis.
- Izin baru untuk jual F&B di resepsionis (bisa diatur per role).

**Catatan teknis**
- Perangkat resepsionis harus bisa mengirim ke printer LAN stasiun: PC/laptop di browser perlu **Print Agent** (.exe) seperti
  kasir F&B; tablet dengan APK sudah otomatis.

---

## 4. Open bill

**Pemahaman dari catatan PM**
- Buka bill (meja / nama customer) → item bisa **ditambah dan dikurangi** berkali-kali → **sekali bayar** di akhir
  = **satu transaksi** (bukan banyak transaksi kecil).
- **Riwayat:** siapa menambah / mengurangi item, jam berapa.
- **Batal bill:** wajib alasan + **approval** supervisor.
- Bill tanpa item **bukan open bill** — tidak tersimpan / tidak masuk laporan.

**Perubahan terbesar:** sekarang slip dapur & potong stok **dikunci harus lunas**. Open bill membutuhkan slip dikirim
**sebelum bayar** (dapur harus membuat pesanan dulu).

**Usulan**
- Slip ke stasiun dikirim **setiap kali item ditambah** (hanya item barunya).
- Mengurangi item yang **slipnya sudah tercetak** (sudah dibuat dapur) = wajib alasan + approval; yang belum tercetak boleh
  langsung dikurangi.
- Tombol **Cetak Bill** (tagihan sementara, bukan struk lunas) untuk ditunjukkan ke customer.
- Open bill yang belum dibayar **memblokir tutup shift** dan tutup otomatis.
- Daftar open bill aktif terlihat di layar kasir (per meja / nama, total, sudah berapa lama).

**Perlu keputusan**
- 4a. Stok dikurangi saat **item ditambah** (usulan — barang sudah keluar) atau saat **bill dibayar**?
- 4b. Saat ganti shift ada open bill belum dibayar: **dipindah ke shift berikutnya** (usulan — customer masih duduk) atau
  **wajib lunas dulu**? (Kalau dipindah, uangnya masuk shift yang menerima pembayaran.)
- 4c. Open bill untuk POS mana: **F&B saja** (Kasir F&B + Resepsionis) — usulan — atau Padel juga (booking + sewa alat + F&B
  dalam satu tagihan)?
- 4d. Siapa yang boleh approve batal / kurangi item yang sudah dibuat? — *Usulan: admin & super_admin.*

---

## 5. Z-Report: total + daftar transaksi, per POS

- Karena setiap POS punya shift sendiri (bagian 1), **setiap POS otomatis punya Z-Report sendiri** (Padel, F&B, Pro Shop,
  Membership, F&B Resepsionis, Wellness/Gym nanti) — masing-masing jumlah transaksi + total.
- **Daftar transaksi per nota** (jam, nomor order, metode, nominal), dikelompokkan per tanggal — shift yang lupa ditutup
  kemarin ikut tercetak di bawah tanggalnya.
- **Rekap menu/barang terjual** (jumlah per menu, mis. Aqua ×5) untuk F&B & Pro Shop.
- Cetak otomatis setelah tutup shift di aplikasi kasir (APK); di browser lewat tombol.

**Perlu keputusan**
- 5a. ~~Satu struk panjang atau terpisah per POS~~ — terjawab: terpisah, karena shift per POS.
- 5b. Daftar transaksi per nota ditulis lengkap, atau cukup total per POS + rekap menu?
- 5c. Wellness / Gym nanti: di meja frontdesk (jadi bagian Z-Report Frontdesk) atau meja + EDC sendiri (loket baru)?

---

## 6. Perubahan database (semua hanya menambah, data lama tidak diubah)

| Tabel | Tambahan | Untuk |
|---|---|---|
| `fnb_menus` | `show_in_fnb_pos` (bawaan ya), `show_in_frontdesk` (bawaan tidak) | Bagian 3 |
| `pos_cashier_shifts` | cara ditutup (normal / sandi pembuka / paksa admin / otomatis), siapa yang memasukkan sandi, alasan tutup paksa. Kolom `counter` sudah ada — POS baru cukup nilai baru (`PROSHOP_COUNTER`, `MEMBERSHIP_COUNTER`, `FNB_FRONTDESK`), shift lama Frontdesk tetap seperti apa adanya | Bagian 1–2 |
| `orders` / tabel baru | status open bill, riwayat tambah/kurang item, permintaan batal + approval | Bagian 4 |
| `kitchen_tickets` | slip per tambahan item (bukan sekali per order) | Bagian 4 |

Izin baru (lewat migration, seperti `view_server_health`): Tutup Paksa, jual F&B di resepsionis, approve batal open bill.

---

## 7. Urutan kerja (masing-masing PR terpisah)

1. **Shift per POS + pembuka = penutup (sandi pembuka / Tutup Paksa admin) + Rekap Shift Kasir** — dasar untuk yang lain.
   Perlu jawaban 1a (EDC bersama) sebelum mulai.
2. **Tutup otomatis + tanda "Lupa ditutup".**
3. **F&B Resepsionis.**
4. **Open bill** — paling besar (checkout, slip, stok, shift, laporan).
5. **Z-Report per POS + daftar transaksi + rekap menu.**

Setiap tahap: rencana detail ditunjukkan dulu → "gas" → dikerjakan → test → PR. Migration diuji di sandbox sebelum production.
