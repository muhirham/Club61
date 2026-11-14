# Product Requirements Document (PRD)
## Modul 24 — Opsi B: Coaching via Academy (Sewa Lapangan Harga Khusus)
**Club 61 Sports & Social Club Central System**

---

| Metadata Dokumen | Spesifikasi |
| :--- | :--- |
| **Kode Dokumen** | `PRD-MODUL-24-COACHING-OPSI-B` |
| **Versi** | `v0.4.0` (11 Nov 2026 — Ganti Coach & toolbar tanggal Jadwal Coaching) |
| **Status** | **DIPILIH (23 Okt 2026).** Opsi A (`PRD_MODUL_24_COACHING.md`) tidak dikerjakan sekarang. Tahap 1 & 2 dibangun (belum di-commit). |
| **Sumber** | Diskusi WhatsApp PM, Head IT Mandau & pihak Club 61 (8 Okt 2026); request resepsionis/manager & keputusan owner aplikasi (22–23 Okt 2026). |
| **Dependensi** | POS Walk-In Booking (`BookOfflineCourt`), Buku Transaksi (Modul 17), Analytics, Reschedule/Refund (Modul 21), Role & Hak Akses (Modul 9). |

---

## 1. Perbedaan dengan Opsi A

| Aspek | Opsi A (`PRD_MODUL_24_COACHING.md`) | Opsi B (dokumen ini) |
| :--- | :--- | :--- |
| Uang coaching dari customer | Masuk ke Club 61, coach dibayar lewat payout | **Ke academy, di luar sistem** |
| Pemasukan Club 61 | Setoran dari harga sesi | **Sewa lapangan harga khusus (tarif setoran)** |
| Booking | Customer booking sendiri di app + kasir | **Resepsionis / manager yang input di POS Walk-In** |
| Payout & utang ke coach | Ada | **Tidak ada** |
| Portal / akun coach | Ada | **Tidak ada** |

---

## 2. Alur Bisnis

1. Customer daftar & bayar paket coaching ke **academy** (mis. SR Academy) lewat WhatsApp admin academy — di luar sistem.
2. Coach / admin academy meminta lapangan ke resepsionis Club 61.
3. Resepsionis di **POS Walk-In** memilih tipe **Coaching**, lapangan & jam, **coach utama** (wajib) + **coach tambahan** (opsional), atas nama, nama murid (maks. 2), dan catatan (mis. ballboy).
4. Harga tiap slot = **tarif setoran coaching** (bukan harga reguler), dihitung ulang di server.
5. Pembayaran sama dengan walk-in: EDC / QRIS / transfer di kasir, atau **Bayar Otomatis** (QR / VA payment gateway di layar kasir). Uang tetap masuk lewat kasir.
6. Booking tampil di **Jadwal Coaching** (jam, lapangan, atas nama, coach, catatan) dengan tombol **WhatsApp pengingat ke coach** (pesan siap kirim, resepsionis yang menekan).
7. Batal / reschedule / refund mengikuti aturan booking biasa (Modul 21) — reschedule memakai tarif coaching di jadwal baru dan mengecek jadwal coach.

---

## 3. Keputusan (23 Okt 2026)

| # | Topik | Keputusan |
| :--- | :--- | :--- |
| 1 | Cara bayar setoran | Seperti customer: langsung di kasir (EDC / QRIS / transfer) **atau** Bayar Otomatis payment gateway. Tidak ada tagihan rekap. |
| 2 | Tarif (termasuk weekend) | **Dinamis**: admin membuat aturan tarif per hari + rentang jam + harga/jam, bisa diubah kapan saja. |
| 3 | Coach tambahan di sesi yang sama | Pengaturan **"Biaya coach tambahan / jam"**, default **Rp0** (setoran tetap per jam lapangan). Diisi kalau Club mau menarik tambahan. |
| 4 | Ballboy | Hanya catatan (belum ada biaya). |
| 5 | Olahraga | **Padel saja.** |
| 6 | Academy | SR Academy dulu; admin bisa menambah academy / coach baru. |
| 7 | Batal / tidak datang / reschedule | Ikut aturan booking biasa. |
| — | Membership & voucher | Booking coaching **tidak** memakai kuota membership, voucher jam corporate, maupun kode promo (harga sudah khusus). |
| — | Booking oleh customer | Customer **tidak** bisa memilih coach / tarif coaching dari app. Parameter `coach_id` di API hold ditutup. |

---

## 4. Tahap 1 (dibangun 23 Okt 2026)

### 4.1 Menu admin "Coaching"
- **Academy** — CRUD: nama, PIC, no. WhatsApp, aktif.
- **Coach** — CRUD: nama, jabatan (Head Coach / Coach), academy, no. WhatsApp, email, catatan internal, aktif, urutan.
- **Tarif Setoran** — CRUD aturan: nama, hari berlaku (Sen–Min, bisa pilih), jam mulai, jam selesai, harga/jam, aktif, prioritas. Slot dihitung per jam memakai aturan aktif pertama (prioritas terkecil) yang cocok dengan hari & jam mulai. Jam tanpa tarif = tidak bisa dibooking sebagai coaching (kasir diberi tahu).
- **Pengaturan Coaching** — biaya coach tambahan / jam (default Rp0), no. WhatsApp admin coaching.
- **Jadwal Coaching** — daftar booking coaching per tanggal + tombol WhatsApp pengingat ke coach.

### 4.2 POS Walk-In
- Pilihan tipe **Reguler / Coaching** (Coaching hanya untuk izin `book_coaching`).
- Mode Coaching: pilih coach utama & coach tambahan (coach aktif saja), nama murid (maks. 2), catatan. Harga slot = tarif setoran + (biaya coach tambahan × jumlah coach tambahan) per jam. Membership / voucher / kode promo dimatikan.
- Coach tidak boleh punya dua sesi di jam yang sama (dicek di server, dengan kunci baris coach).
- Struk & jadwal: "Sewa Lapangan Coaching · nama lapangan".

### 4.3 Data
- `coaching_academies`, `coaches`, `coaching_rates`, `coaching_settings` (1 baris).
- `coaching_bookings` (1 per order): coach utama, academy (snapshot), murid, catatan, biaya coach tambahan (snapshot), rincian harga per slot (snapshot), pembuat.
- `coaching_booking_coaches` (coach utama + tambahan).
- `padel_bookings.coaching_booking_id` (nullable). Kolom lama `coach_id` → `staff_profiles` tidak dipakai & tidak diubah.

### 4.4 Laporan
- Slot coaching tetap item `PADEL` (alur bayar, struk, refund, reschedule tidak berubah). Buku Transaksi mengelompokkannya ke kategori baru **COACHING — "Sewa Lapangan Coaching"**, termasuk selisih reschedule.
- Analytics: baris "Pelatih & Coaching Session" terisi dari kategori itu.

### 4.5 Izin (Modul 9)
| Izin | Untuk |
| :--- | :--- |
| `View:JadwalCoaching` | Lihat Jadwal Coaching |
| `book_coaching` | Booking Coaching di POS Walk-In |
| `manage_coaching` | Kelola Academy & Coach |
| `manage_coaching_rates` | Kelola Tarif Setoran & Pengaturan Coaching (izin "pintu belakang" seperti tarif lapangan — super admin, bisa diberikan manual) |

Preset resepsionis: `View:JadwalCoaching`, `book_coaching`. Semua perubahan tercatat di Log Aktivitas.

---

## 5. Tahap 2 (dibangun 23 Okt 2026)
- **Monitoring Lapangan** (Operasional Harian): sel timetable sesi coaching menampilkan baris coach ("Coach Nalda +1" = nama depan coach utama + jumlah coach tambahan); popup detail menampilkan bagian **Sesi Coaching** (coach utama / tambahan + tombol WA pengingat, academy, murid, catatan termasuk ballboy).
- **Profil coach untuk customer** — portal customer `/coaching` (menu Coaching; di layar < 1280px & HP lewat dropdown akun): foto, nama, jabatan, olahraga, academy, poin keunggulan (maks. 6) + tombol **WhatsApp admin coaching** (umum & per coach). Informasi saja. Diisi admin di menu Coach → bagian "Profil untuk Customer"; tampil hanya kalau coach **aktif** dan **Tampilkan di profil** dinyalakan (default mati). No. WA, email, dan catatan internal coach tidak pernah ditampilkan. Foto diproses ulang `SecureImageUploader` (JPEG baru, EXIF dibuang).
- **API aplikasi**: `GET /api/v1/coaching/coaches` (publik, read-only) — data sama dengan halaman web + `whatsapp_url` per coach & `admin_whatsapp_url`.
- **Halaman link bio Instagram** `/links` (publik, tanpa login). Tombol diatur di **Marketing & Event → Link Bio Instagram** (izin `manage_link_bio`, preset admin): Book Court, Lokasi (alamat Konten Website atau link Google Maps), Admin Coaching (WA dari Pengaturan Coaching), Pricelist (gambar unggahan — nonaktif sampai diunggah), Admin Club 61 (WA dari Konten Website), Profil Coach, atau link lain (wajib http/https). Media sosial otomatis dari Konten Website. Tombol yang tujuannya belum diisi tidak tampil.
- **Analytics**: Rekap Coaching per Academy (sesi, jam, nilai sewa = tarif setoran slot) dan per Coach (sesi sebagai coach utama / tambahan, jam). Ini rekap pemakaian; uang masuk tetap baris "Pelatih & Coaching Session".
- Data: migrasi `2026_10_23_140001` — kolom `coaches.photo_path / sport / highlights / show_on_profile`, tabel `link_bio_links`; migrasi `2026_10_23_150001` — `coaches.motto` (opsional, tampil sebagai kutipan; kosong = tidak tampil). Foto profil selalu tampil utuh (tidak dipotong).

---

## 6. Ganti Coach & Tampilan Jadwal Coaching (11 Nov 2026, tanpa migrasi)
Latar: coach diganti di lapangan tetapi data tidak bisa diubah, sehingga resepsionis mengingatkan coach yang salah.

- **Ganti Coach** — tombol per sesi di Jadwal Coaching (izin `book_coaching`, dicek ulang di server). Satu pilihan per coach sesi (coach utama / tambahan). Pengganti wajib coach **aktif** dari **academy yang sama** (setoran & tagihan berkala tetap ke academy itu), tidak boleh dipilih dua kali, dan tidak boleh punya sesi lain di jam sesi ini. Berlaku untuk seluruh jam sesi; hanya sesi yang masih berjalan (belum bayar tagihan academy, lunas, sedang main), bukan yang selesai / batal. Alasan opsional.
- Jumlah coach tetap → harga, pembayaran, Buku Transaksi & setoran tidak berubah. Peran (utama / tambahan) ikut ke coach pengganti.
- Setelah diganti: Jadwal Coaching, Monitoring Lapangan, pesan pengingat WhatsApp, dan rekap per coach di Analytics memakai coach pengganti. Coach utama selalu disebut lebih dulu.
- Tercatat di Log Aktivitas (`coaching.coach_changed`: coach lama → baru, jadwal, alasan).
- **Toolbar tanggal** menggantikan panel filter: **Hari Ini / Besok / Semua Mendatang** + pilihan tanggal, langsung berubah tanpa tombol terapkan, dengan label tanggal & jumlah sesi. Kolom jam & lapangan digabung supaya tombol aksi muat di layar tablet. Keterangan halaman di atas tabel dihapus.

---

## 7. Di Luar Lingkup
Payout & utang ke coach, akun/portal coach, booking coach oleh customer, pembagian uang coaching, pengingat WhatsApp otomatis (butuh WA gateway), biaya ballboy (belum ada kepastian).
