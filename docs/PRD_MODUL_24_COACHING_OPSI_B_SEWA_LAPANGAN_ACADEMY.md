# Product Requirements Document (PRD)
## Modul 24 — Opsi B: Coaching via Academy (SR Academy Sewa Lapangan)
**Club 61 Sports & Social Club Central System**

---

| Metadata Dokumen | Spesifikasi |
| :--- | :--- |
| **Kode Dokumen** | `PRD-MODUL-24-COACHING-OPSI-B` |
| **Versi** | `v0.1.0-DISKUSI` |
| **Status** | **Opsi diskusi — belum dipilih.** Tidak menggantikan `PRD_MODUL_24_COACHING.md` (Opsi A). Opsi yang dipakai diputuskan PM. |
| **Sumber** | Diskusi WhatsApp PM, Head IT Mandau & pihak Club 61, 8 Okt 2026. |
| **Dependensi** | POS Walk-In Booking (`BookOfflineCourt`), Buku Transaksi (Modul 17), Analytics, Role & Hak Akses (Modul 9). |

---

## 1. Perbedaan dengan Opsi A

| Aspek | Opsi A (`PRD_MODUL_24_COACHING.md`) | Opsi B (dokumen ini) |
| :--- | :--- | :--- |
| Uang dari customer | Masuk ke Club 61 (Midtrans / kasir), coach dibayar lewat payout | **Ke SR Academy, di luar sistem** |
| Pemasukan Club 61 | Setoran lapangan dari harga sesi | **Sewa lapangan harga khusus yang disetor SR Academy** |
| Booking coaching | Customer booking sendiri di app + kasir | **Customer lewat WhatsApp admin coaching; resepsionis yang input ke sistem** |
| Payout & utang ke coach | Ada | **Tidak ada** |
| Portal / akun coach | Ada | **Tidak ada** |
| Tampilan ke customer | Daftar coach + booking sesi | **Profil coach saja + tombol WhatsApp** |

---

## 2. Alur Bisnis (sesuai diskusi)

1. Customer menghubungi **admin coaching (SR Academy)** lewat WhatsApp dan membayar coaching ke SR Academy. Paket coaching sudah **all-in** (termasuk lapangan).
2. SR Academy **menyewa lapangan** ke Club 61 dengan **harga khusus (setoran)**, lalu membayar fee ke coach-nya sendiri. Club 61 tidak ikut membagi uang coach.
3. Resepsionis Club 61 membookingkan lapangan di **POS Walk-In**, lalu memasang **add-on coach** (pilih nama coach). Harga slot berubah dari harga normal ke **harga khusus coaching**.
4. Nama coach tersimpan di booking supaya resepsionis bisa **mengingatkan coach** soal jadwalnya.
5. Add-on coach **tidak tampil** di booking online customer.

---

## 3. Fitur

### 3.1 Data Coach (admin)
Nama, academy (mis. SR Academy), nomor WhatsApp, foto, bio singkat, poin keunggulan (daftar bebas), olahraga, status aktif, urutan tampil. Plus satu pengaturan: **nomor WhatsApp admin coaching**.

### 3.2 Tarif setoran coaching
Disimpan sebagai pengaturan, bisa diubah admin. Angka dari daftar harga PM:

| Jam mulai | Tarif setoran / jam |
| :--- | ---: |
| 06:00–16:59 | Rp100.000 |
| 17:00–23:59 | Rp150.000 |

### 3.3 Add-on coach di POS Walk-In
- Pilihan **Coaching (pilih coach)** di panel checkout POS Walk-In.
- Saat dipilih, harga tiap slot memakai tarif setoran, bukan harga normal.
- Booking tampil di jadwal sebagai **"Coaching · nama coach"**; struk menulis **"Sewa lapangan coaching"**.
- Pembayaran tetap lewat metode POS yang sudah ada (EDC / QRIS).
- Hanya staf dengan izin khusus yang bisa memasang add-on, dan wajib memilih coach terdaftar — mencegah harga khusus dipakai untuk main biasa.

### 3.4 Pengingat coach
Daftar **Jadwal Coaching Hari Ini** (jam, lapangan, coach) dengan tombol WhatsApp ke coach berisi pesan siap kirim. Resepsionis yang menekan tombolnya.

### 3.5 Halaman customer
- Ikon **Coach** di dashboard customer → halaman profil coach (foto, nama, olahraga, poin keunggulan). **Hanya informasi, tidak ada booking.** Isi boleh kosong dulu.
- Tombol WhatsApp melayang ke admin coaching.
- Endpoint API daftar coach untuk aplikasi Flutter.

### 3.6 Halaman link bio Instagram
Halaman tautan bertema brand: **Book Court**, **Lokasi (Google Maps)**, **Admin Coaching (WhatsApp)**, **Pricelist**, **Admin Club 61 (WhatsApp)**, dan tautan media sosial.

### 3.7 Laporan
- Buku Transaksi: kategori **Sewa Lapangan Coaching**.
- Analytics: baris "Pelatih & Coaching Session" diisi jumlah jam & pendapatan sewa coaching, dengan rekap per coach / academy.

### 3.8 Keamanan
API hold booking customer saat ini menerima `coach_id`. Pada opsi ini parameter itu **ditutup** — coach hanya bisa dipasang oleh staf di POS.

---

## 4. Di Luar Lingkup Opsi Ini
Payout & utang ke coach, akun/portal coach, booking coach oleh customer, pembagian uang coaching, pengingat WhatsApp otomatis (butuh WA gateway).

---

## 5. Pertanyaan Terbuka

| # | Pertanyaan | Dampak ke desain |
| :--- | :--- | :--- |
| 1 | Setoran SR dibayar **langsung per booking di kasir**, atau **dicatat dulu lalu ditagih rekap** (mingguan / bulanan)? | Rekap butuh status "belum dibayar" + tagihan per academy |
| 2 | Weekend: tarif tetap ikut jam (100rb / 150rb), atau weekend selalu 150rb? | Aturan hitung tarif |
| 3 | Tarif sama untuk semua lapangan (indoor / outdoor)? | Tarif per lapangan atau global |
| 4 | Hanya SR Academy, atau nanti ada coach independen / academy lain? | Perlu data academy terpisah atau cukup teks |
| 5 | Pengingat coach cukup tombol WhatsApp manual, atau otomatis? | Otomatis butuh WA gateway |
| 6 | Nama murid perlu dicatat di booking? | Kolom tambahan di POS |
| 7 | Booking coaching yang batal / tidak datang ikut aturan booking biasa? | Aturan refund & reschedule |
| 8 | Pricelist di link bio: gambar / PDF upload admin, atau halaman harga otomatis? | Sumber konten |
