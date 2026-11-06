# PRD Modul 33 — Satu Akun Satu Nomor HP (Web, App & POS Walk-In)

| | |
|---|---|
| **Versi** | v0.3 — Tahap 1 DIBANGUN 6 Nov 2026 (belum di-commit); Tahap 2 ditunda (belum digabung, cukup daftar kandidat); Tahap 3 menunggu |
| **Tanggal** | 5 Nov 2026 |
| **Terkait** | Modul 01 (Auth), POS Walk-In (BookOfflineCourt / Jual Membership), Modul 12 (Sponsor) |

## 1. Masalah

Sistem tidak punya username. Satu orang dikenali dengan cara berbeda tergantung jalur daftarnya:

| Jalur | Data yang diminta | Cara sistem mengenali |
|---|---|---|
| Daftar di web (`RegisteredUserController`) | Nama, email, password. **Tidak ada nomor HP.** Tidak diberi role `customer`. | Email |
| Daftar di app Flutter (`Api\V1\Auth\AuthController::register`) | Nama, email, **nomor HP wajib & unik**, password | Email + HP |
| POS Walk-In Cepat (`findOrCreateWalkInCustomer`) | Nama, nomor HP, email opsional | **Nomor HP saja**. Tidak ketemu → akun baru (email buatan sistem, password 6 digit terakhir HP) |
| Edit Profil web (`ProfileUpdateRequest`) | Nama, email. **Nomor HP tidak bisa diisi.** | — |

Akibatnya:

1. Orang yang daftar di web lalu datang ke kasir → kasir memakai Walk-In Cepat → **akun kedua** dibuat. Kalau kasir
   mengetik email asli orang itu, sistem mendeteksi emailnya sudah dipakai lalu **diam-diam** memakai email buatan dan
   tetap membuat akun baru. Riwayat booking, membership, voucher, dan invoice terpecah di dua akun.
2. Kebalikannya (walk-in dulu, lalu daftar di web) juga menghasilkan dua akun.
3. Unique index `users(email, deleted_at)` dan `users(phone, deleted_at)` **tidak berlaku di MySQL** selama
   `deleted_at` NULL (NULL dianggap selalu berbeda). Keunikan hanya dijaga validasi aplikasi, sehingga klik ganda pada
   tombol Daftar bisa membuat dua akun dengan email yang sama.
4. Nomor yang salah ketik dengan awalan `0` + `62…` (mis. `062819…`) tidak dinormalisasi, jadi tidak pernah cocok
   dengan `0819…` dan menghasilkan akun baru. (Tidak dinormalisasi otomatis — lihat poin 6 di §3.)

**Nama sama tetapi orangnya berbeda tidak termasuk masalah.** Nama tidak pernah dipakai untuk mencocokkan akun dan
memang harus tetap terpisah.

### Kondisi produksi (data 5 Nov 2026, 132 akun)

- ±98 akun hasil daftar web, **semuanya tanpa nomor HP** dan tanpa role `customer`.
- 26 akun walk-in (punya nomor HP, email buatan sistem).
- ±12 pasangan kandidat dobel walk-in ↔ web dengan nama sama atau mirip, 2 orang yang mendaftar dua kali di web
  (email berbeda), dan 1 akun yang **tercatat dua kali dengan email yang sama di detik yang sama** (klik ganda).
- Daftar kandidat sengaja tidak ditulis di dokumen ini (data pribadi). Cek ulang dengan query di §6.

## 2. Prinsip

**Satu orang = satu nomor HP = satu akun.** Nomor HP (dinormalisasi `08…`) adalah identitas utama di semua jalur.
Email tetap wajib untuk daftar mandiri (web/app) dan tetap bisa dipakai untuk login. Nomor HP **tidak diverifikasi
dengan OTP** (sama seperti sekarang).

## 3. Tahap 1 — Mencegah akun dobel baru (tanpa migration)

| # | Perubahan | Rincian |
|---|---|---|
| 1 | **Daftar web wajib nomor HP** | Field "No. WhatsApp" di `auth/register`. Dinormalisasi `PhoneNumber::normalize`, unik di antara akun aktif. Akun baru diberi role `customer` (seragam dengan walk-in). Kalau nomornya sudah dimiliki akun lain, pendaftaran ditolak dengan pesan: "Nomor ini sudah punya akun Club 61. Masuk memakai nomor HP. Kalau akun dibuat di kasir, password awalnya 6 digit terakhir nomor HP." Ditambah tombol **WhatsApp resepsionis** (`CompanyProfileSetting::whatsappUrl`, nomor dari Konten Website; tombol tidak tampil kalau nomor kosong) untuk yang lupa password — akun kasir tidak punya email asli untuk reset. |
| 2 | **Edit Profil: isi / ubah nomor HP** | Field nomor HP di `profile/edit` (unik, dinormalisasi). Tercatat otomatis di Log Aktivitas (kolom `phone` sudah dipantau AuditRegistry). |
| 2b | **Banner "Lengkapi nomor WhatsApp"** | Muncul di **semua halaman customer** (layout customer) untuk akun tanpa nomor HP, dengan tautan ke Edit Profil. Hilang setelah diisi. Tidak tampil di halaman Edit Profil itu sendiri, panel admin, dan akun staf. |
| 3 | **POS Walk-In Cepat: email bentrok tidak lagi diam-diam** | Kalau email yang diketik kasir sudah dimiliki akun lain, transaksi tidak diproses dan kasir diberi tahu: "Email ini sudah terdaftar atas nama X. Pilih lewat Cari Akun." Berlaku di Booking Lapangan, Jual Membership, dan semua kasir yang memakai `ResolvesPosCustomer`. |
| 4 | **POS Cari Akun: kasir bisa mengisi nomor HP** | Kalau akun yang dipilih belum punya nomor HP, muncul isian nomor HP dan nomor itu disimpan ke akun tersebut (unik). Dengan ini akun web dan kunjungan kasir tersambung. |
| 5 | **Anti klik ganda saat daftar** | Tombol Daftar terkunci setelah diklik, dan server memakai `Cache::lock` per email/nomor HP selama proses daftar (web & app), lalu mengecek ulang keunikan di dalam kunci. |
| ~~6~~ | ~~Normalisasi `062…`~~ | **Dibatalkan.** `062x` adalah kode area telepon rumah yang sah di Sumatera Utara (mis. 0622 Pematang Siantar, 0623 Kisaran), jadi mengubahnya otomatis bisa merusak nomor asli. Nomor yang salah ketik dibetulkan manual lewat Data Pelanggan → Edit. |

| 7 | **Hapus akun mandiri ditiadakan** (permintaan user 6 Nov) | Tombol "Hapus Akun" di Edit Profil dibuang, rute `DELETE /profile` dihapus (server menolak 405). Riwayat booking, invoice, membership & voucher tetap melekat ke akun; penonaktifan / penghapusan hanya lewat admin. |
| 8 | **Kasir Jual Membership memakai jalur akun yang sama** | Akun walk-in dari Jual Membership dibuat lewat `findOrCreateWalkInCustomer` (sama dengan kasir lapangan). Dulu nomor HP dicari tanpa dinormalisasi (`0812-…` / `+62…` tidak cocok → akun dobel), email yang sudah dipakai tetap dibuatkan akun kedua, dan password-nya acak (tidak bisa login). Sekarang: nomor dinormalisasi, email bentrok ditolak, password awal 6 digit terakhir HP, role `customer`, `registration_source` WALK_IN. Akun `mbr_…` lama tidak diubah (di produksi per 5 Nov tidak ada). |

Yang tidak berubah: akun yang sudah ada, cara login (email / nomor HP), password walk-in 6 digit, aturan daftar app Flutter
(hanya ditambah kunci anti ketuk ganda), impor penjualan (`SalesImportService` tetap memakai perilaku lama).

**Implementasi (6 Nov 2026):** `App\Services\Customer\CustomerPhoneService` (cek pemilik nomor, kasir mengisi nomor ke
akun tanpa nomor — dikunci & dicek ulang, hanya akun customer, tidak menimpa nomor), `RegisteredUserController`
(nomor wajib + kunci `register:email:*` / `register:phone:*`), `Api\V1\Auth\AuthController::register` (kunci yang sama),
`ProfileUpdateRequest` + `ProfileController::update` (nomor `sometimes`: tidak dikirim = tetap; sudah ada = tidak bisa
dikosongkan; dikunci `customer-phone:*`), banner `data-phone-banner` di `layouts/app`, `ResolvesPosCustomer`
(`walkInEmailAvailable`, `saveSelectedCustomerPhone`; dipakai Booking Lapangan & Jual Membership), partial
`filament/partials/pos-add-customer-phone`, `findOrCreateWalkInCustomer(..., rejectTakenEmail: true)` untuk kasir.
Test: `tests/Feature/Auth/OneAccountPerPhoneTest.php`.

Test: daftar web dengan HP baru / HP milik walk-in (pesan + tombol WhatsApp) / HP format `+62`, klik ganda, edit
profil HP bentrok, Walk-In Cepat dengan email bentrok, Cari Akun mengisi HP, banner tampil di halaman customer &
hilang setelah HP diisi.

## 4. Tahap 2 — Alat "Gabungkan Akun" (untuk dobel yang sudah ada)

Lokasi: Data Pelanggan → aksi **Gabungkan Akun**. Permission baru `merge_customer_accounts` (masuk matriks, di-assign
manual oleh admin).

**Alur**

1. Pilih **akun utama** (biasanya akun web karena email asli) dan **akun dobel**.
2. **Pratinjau**: jumlah data yang akan dipindah per jenis, plus daftar bentrok kalau ada.
3. Konfirmasi dengan mengetik alasan (wajib).
4. Semua data dipindah ke akun utama **dalam satu transaksi** (gagal satu = batal semua).
5. Nomor HP akun dobel dipindah ke akun utama kalau akun utama belum punya.
6. Akun dobel di-**soft delete** (bisa dipulihkan), token/sesi login-nya dicabut.
7. Log Aktivitas **CRITICAL**: siapa, kapan, alasan, jumlah data yang dipindah per tabel.

**Data yang dipindah** (kolom `user_id` / sejenis yang menunjuk ke `users`):

- Relasi resmi: `padel_bookings`, `orders`, `user_memberships`, `voucher_claims`, `vouchers`, `equipment_loans`
  (`borrower_user_id`), `sponsor_organization_members`, `sponsor_organizations` (`sponsor_admin_user_id`),
  `facility_checkins`, `wellness_bookings`, `wellness_waitlists`, `gym_memberships`, `gym_checkins`,
  `salon_appointments`.
- Tanpa relasi resmi: `ledger_entries.customer_id`, notifikasi (`notifications`), dan kolom lain yang masih harus dipetakan
  sebelum dibangun.
- Tidak dipindah: `staff_profiles`, role/permission, kolom "dibuat oleh staf" (`issued_by_user_id`,
  `created_by_user_id`), Log Aktivitas lama (tetap mencatat akun lama apa adanya).

**Ditolak (tidak digabung) kalau:**

- Salah satu akun adalah akun staf (punya role selain `customer`).
- Keduanya anggota di perusahaan sponsor yang sama (`uniq_sponsor_org_member`).
- Keduanya sudah klaim voucher kampanye yang sama (`voucher_claims` unik per voucher+user).
- Akun dobel sedang punya transaksi menunggu bayar (order PENDING) — selesaikan dulu.

**Aturan operasional:** hanya digabung setelah dikonfirmasi ke customer (telepon/WA atau saat datang). Nama mirip saja
bukan bukti orang yang sama.

## 5. Tahap 3 — Keunikan di level database (migration aditif)

Setelah dobel email/HP dibereskan lewat Tahap 2, tambahkan kolom generated (aditif):

- `active_email` = `LOWER(email)` kalau `deleted_at` NULL, selain itu NULL → **UNIQUE**
- `active_phone` = `phone` kalau `deleted_at` NULL, selain itu NULL → **UNIQUE**

Index lama tidak disentuh. Migration menolak berjalan (pesan jelas) kalau masih ada dobel, supaya tidak gagal di
tengah jalan di produksi. Kompatibilitas SQLite (test) dicek saat dibangun.

## 6. Query cek produksi (read-only)

```sql
-- Email dobel di antara akun aktif (mis. klik ganda saat daftar)
SELECT LOWER(email) e, COUNT(*) c FROM users WHERE deleted_at IS NULL GROUP BY e HAVING c > 1;

-- Nomor HP dobel di antara akun aktif
SELECT phone, COUNT(*) c FROM users WHERE deleted_at IS NULL AND phone IS NOT NULL GROUP BY phone HAVING c > 1;

-- Kandidat orang sama (nama sama, sebagian tanpa HP)
SELECT LOWER(TRIM(name)) nama, COUNT(*) jumlah, SUM(phone IS NULL) tanpa_hp, GROUP_CONCAT(registration_source) sumber
FROM users WHERE deleted_at IS NULL GROUP BY nama HAVING jumlah > 1;
```

## 7. Urutan & status

| Tahap | Isi | Migration | Status |
|---|---|---|---|
| 1 | Pencegahan (poin 1–5, 7, 8) | Tidak | **Dibangun 6 Nov 2026**, belum di-commit |
| 2 | Gabungkan Akun | Tidak | **Ditunda** (keputusan user 6 Nov): akun dobel belum digabung, cukup daftar kandidat untuk dicek manual (query §6) |
| 3 | Unique index aktif | Ya (aditif) | Setelah dobel bersih |

## 8. Keputusan (5 Nov 2026)

1. Pesan penolakan daftar web untuk nomor yang sudah dipakai: **arahan login + tombol WhatsApp resepsionis**.
2. Penggabungan akun: permission `merge_customer_accounts` hanya ditambahkan ke matriks; user meng-assign ke role
   secara manual (sama seperti permission lain).
3. Banner "Lengkapi nomor WhatsApp": **semua halaman customer**.
4. Normalisasi `062…` dibatalkan (lihat §3 poin 6).
