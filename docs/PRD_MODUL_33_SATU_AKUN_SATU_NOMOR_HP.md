# PRD Modul 33 — Satu Akun Satu Nomor HP (Web, App & POS Walk-In)

| | |
|---|---|
| **Versi** | v0.1 — PLAN, belum dibangun (menunggu persetujuan) |
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
   dengan `0819…` dan menghasilkan akun baru.

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
| 1 | **Daftar web wajib nomor HP** | Field "No. WhatsApp" di `auth/register`. Dinormalisasi `PhoneNumber::normalize`, unik di antara akun aktif. Akun baru diberi role `customer` (seragam dengan walk-in). Kalau nomornya sudah dimiliki akun lain, pendaftaran ditolak dengan pesan: "Nomor ini sudah punya akun Club 61. Masuk memakai nomor HP. Kalau akun dibuat di kasir, password awalnya 6 digit terakhir nomor HP." |
| 2 | **Edit Profil: isi / ubah nomor HP** | Field nomor HP di `profile/edit` (unik, dinormalisasi). Tercatat otomatis di Log Aktivitas (kolom `phone` sudah dipantau AuditRegistry). |
| 2b | **Banner "Lengkapi nomor WhatsApp"** | Muncul di halaman customer untuk akun tanpa nomor HP, dengan tautan ke Edit Profil. Hilang setelah diisi. |
| 3 | **POS Walk-In Cepat: email bentrok tidak lagi diam-diam** | Kalau email yang diketik kasir sudah dimiliki akun lain, transaksi tidak diproses dan kasir diberi tahu: "Email ini sudah terdaftar atas nama X. Pilih lewat Cari Member." Berlaku di Booking Lapangan, Jual Membership, dan semua kasir yang memakai `ResolvesPosCustomer`. |
| 4 | **POS Cari Member: kasir bisa mengisi nomor HP** | Kalau akun yang dipilih belum punya nomor HP, muncul isian nomor HP dan nomor itu disimpan ke akun tersebut (unik). Dengan ini akun web dan kunjungan kasir tersambung. |
| 5 | **Anti klik ganda saat daftar** | Tombol Daftar terkunci setelah diklik, dan server memakai `Cache::lock` per email/nomor HP selama proses daftar (web & app), lalu mengecek ulang keunikan di dalam kunci. |
| 6 | **Normalisasi `062…`** | `062` + nomor seluler (`0628…` dst. dengan panjang nomor HP) dinormalisasi ke `08…`. Akan dicek dulu supaya tidak mengenai kode area telepon rumah. |

Yang tidak berubah: akun yang sudah ada, cara login (email / nomor HP), password walk-in 6 digit, alur app Flutter.

Test: daftar web dengan HP baru / HP milik walk-in / HP format `+62`, klik ganda, edit profil HP bentrok, Walk-In Cepat
dengan email bentrok, Cari Member mengisi HP, banner tampil/hilang.

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
| 1 | Pencegahan (poin 1–6) | Tidak | Menunggu persetujuan |
| 2 | Gabungkan Akun | Tidak | Menunggu Tahap 1 + pemetaan kolom lengkap |
| 3 | Unique index aktif | Ya (aditif) | Setelah dobel bersih |

## 8. Pertanyaan terbuka

1. Pesan penolakan daftar web untuk nomor yang sudah dipakai: cukup arahan login, atau ditambah kontak WhatsApp
   resepsionis?
2. Siapa yang boleh menggabungkan akun (permission `merge_customer_accounts` diberikan ke role apa)?
3. Banner "Lengkapi nomor WhatsApp": cukup di Dashboard & Invoice, atau semua halaman customer?
