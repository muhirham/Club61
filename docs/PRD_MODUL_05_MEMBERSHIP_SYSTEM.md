# Product Requirements Document (PRD)
## Modul 05: Club 61 Membership — Sistem Keanggotaan Multi-Fasilitas (Padel, Gym, Sauna)
**Club 61 Sports & Social Club Central System**

---

| Metadata Dokumen | Spesifikasi |
| :--- | :--- |
| **Kode Dokumen** | `PRD-MODUL-05-MEMBERSHIP-SYSTEM` |
| **Versi** | `v2.0.0-FINAL` — **menggantikan (supersede) total** `v1.0.0-PROD-SPEC` |
| **Status** | **Final — Siap Eksekusi** |
| **Alasan Revisi** | Desain v1 (3 tier bundled Silver/Gold/Platinum) diganti total dengan desain **matrix benefit per-fasilitas**: setiap paket bisa mengaktifkan Padel, Gym, dan/atau Sauna secara independen dengan satuan kuota berbeda-beda (jam, sesi, atau diskon flat), termasuk paket berbasis jendela waktu (misal "After Hour", "Happy Hour"). Kode skeleton `gym_packages`/`gym_memberships`/`gym_checkins` yang sudah ada di repo **masih berupa stub kosong** (tidak ada business logic, tidak ada data production) — aman untuk dirombak total tanpa migrasi data. |
| **Target Pengguna** | Customer Web Portal, Kasir/Admin Frontdesk (Filament Panel), Super Admin |
| **Prinsip Utama** | **BENEFIT DIRANCANG SEBAGAI MATRIX PER-FASILITAS (BUKAN TIER FIX), HARGA & BENEFIT DI-SNAPSHOT SAAT PEMBELIAN (TIDAK PERNAH BERUBAH RETROAKTIF), SETIAP MUTASI KUOTA WAJIB TERCATAT DI AUDIT LOG** |

---

## 1. Latar Belakang & Keputusan Desain

### 1.1. Kenapa Desain v1 Diganti
Desain v1 mengasumsikan 3 tier tetap (Silver/Gold/Platinum) yang masing-masing membundel akses Padel + Gym + Sauna dengan rasio yang sudah dipatok di kode. Ini terlalu kaku untuk kebutuhan bisnis riil:
- Club 61 ingin memisahkan operasional **Padel dan Gym** (laporan keuangan & utilisasi tidak boleh campur), tapi tetap menawarkan **1 kartu member** yang bisa dipakai lintas fasilitas kalau customer memilih paket "All-Access".
- Referensi pasar (venue sejenis) menjual paket **berbasis jam/sesi** (10 Jam, 30 Jam, 100 Jam) dengan variasi **jendela waktu** (After Hour, Happy Hour) — bukan cuma diskon persentase flat bulanan.
- Admin butuh 1 halaman kerja untuk: menjual paket ke customer (termasuk walk-in), memberi diskon manual dengan alasan tercatat, dan membuat template paket baru — tanpa bongkar kode.

### 1.2. Prinsip Non-Negosiasi
1. **Satu Customer = Satu Kartu Member (`user_memberships`)**, tapi kuota tiap fasilitas (Padel/Gym/Sauna) disimpan sebagai baris terpisah (`user_membership_balances`) — supaya laporan keuangan/utilisasi per divisi tetap bersih, dan supaya 1 paket bisa punya kombinasi kuota berbeda satuan (jam untuk Padel, visit untuk Gym) dalam waktu bersamaan.
2. **Snapshot, Bukan Referensi Live**: begitu member membeli paket, seluruh benefit (kuota, diskon%, jendela waktu) **disalin** ke baris milik member tersebut. Kalau admin mengedit template paket besok, member yang sudah beli **tidak terpengaruh**. Ini invarian paling kritis di dokumen ini — lihat §5.1.
3. **Tidak Ada Mutasi Kuota Tanpa Log**: setiap penambahan/pengurangan kuota (`user_membership_balances.remaining_quota`) wajib menulis satu baris di `membership_usage_logs`. Tidak ada UPDATE langsung ke kolom kuota tanpa jejak audit.

---

## 2. Struktur Paket & Benefit (Matrix Design)

```
                    ┌───────────────────────────────┐
                    │      MEMBERSHIP_PLANS          │
                    │   (Template paket, bisa diedit  │
                    │    admin — TIDAK menyimpan      │
                    │    kuota milik siapa pun)        │
                    └────────────────┬────────────────┘
                                     │ 1..N baris benefit
                                     ▼
                    ┌───────────────────────────────┐
                    │  MEMBERSHIP_PLAN_BENEFITS       │
                    │  (matrix: 1 baris = 1 fasilitas)│
                    │  facility: PADEL | GYM | SAUNA  │
                    │  quota_type: HOURS|VISITS|NONE  │
                    │  discount_percent, time_window  │
                    └───────────────────────────────┘

   Saat dibeli → disalin (snapshot) menjadi:

                    ┌───────────────────────────────┐
                    │      USER_MEMBERSHIPS           │
                    │  (1 kartu = 1 transaksi beli)   │
                    └────────────────┬────────────────┘
                                     │ 1..N baris (sesuai facility yg aktif di plan)
                                     ▼
                    ┌───────────────────────────────┐
                    │  USER_MEMBERSHIP_BALANCES       │
                    │  (kuota riil milik member ini,   │
                    │   independen per fasilitas)      │
                    └────────────────┬────────────────┘
                                     │ setiap pemakaian/reversal
                                     ▼
                    ┌───────────────────────────────┐
                    │   MEMBERSHIP_USAGE_LOGS         │
                    │  (audit trail wajib, immutable)  │
                    └───────────────────────────────┘
```

### Contoh Konkret

**"Quantum 30 Jam (Happy Hour)"** — 1 plan, 1 baris benefit:
- `facility = PADEL`, `quota_type = HOURS`, `quota_value = 30`, `time_window_start = 10:00`, `time_window_end = 15:00`, `duration_days = 365`.

**"Club 61 All-Access VIP"** — 1 plan, 3 baris benefit:
- `facility = PADEL`, `quota_type = HOURS`, `quota_value = 20`, `discount_percent = 25`, `booking_priority_days = 14`.
- `facility = GYM`, `quota_type = NONE` (unlimited, tidak didekremen), `discount_percent = 0`.
- `facility = SAUNA`, `quota_type = VISITS`, `quota_value = 8`.

**"Silver Padel Only"** — 1 plan, 1 baris benefit:
- `facility = PADEL`, `quota_type = NONE`, `discount_percent = 10` (diskon flat, tanpa kuota jam — mirip tier v1 lama, tetap bisa direpresentasikan lewat matrix yang sama).

---

## 3. Arsitektur Data & Skema Database

> **Migrasi dari skeleton lama**: tabel `gym_packages`, `gym_memberships`, `gym_checkins` (migration `2026_09_03_150005_create_gym_tables.php`) di-**drop dan diganti** dengan skema di bawah — bukan `ALTER`, karena masih stub kosong tanpa data. Model `App\Models\Gym\*` dan `GymController` dihapus, diganti namespace `App\Models\Membership\*`.

### 3.1. Tabel `membership_plans` (Template Paket — dikelola admin)
| Kolom | Tipe | Keterangan |
| :--- | :--- | :--- |
| `id` | ULID, PK | |
| `code` | string(30), unique | Kode internal, misal `QTM-30-HH`. |
| `name` | string(150) | Nama tampil, misal "Quantum 30 Jam (Happy Hour)". |
| `ownership_type` | string(15), default `INDIVIDUAL`: `INDIVIDUAL`\|`ORGANIZATIONAL` | `ORGANIZATIONAL` = paket blok jam untuk entitas sponsor/korporat (lihat §8) — **tidak pernah** ditampilkan di katalog self-checkout customer (FR-01), hanya bisa dijual lewat halaman staf "Kelola Sponsor Korporat" (Modul 12). |
| `duration_days` | integer | Masa aktif sejak `start_date`. |
| `price` | decimal(12,2) | Harga jual dasar (sebelum diskon manual saat penjualan). |
| `is_active` | boolean, default true | Nonaktif = tidak muncul di katalog penjualan baru, **tidak memengaruhi member existing**. |
| `created_by` | ULID, FK → `users.id`, nullable | Admin yang membuat template. |
| `timestamps`, `softDeletes` | | |

### 3.2. Tabel `membership_plan_benefits` (Matrix Benefit per Fasilitas)
| Kolom | Tipe | Keterangan |
| :--- | :--- | :--- |
| `id` | ULID, PK | |
| `plan_id` | ULID, FK → `membership_plans.id`, cascade delete | |
| `facility` | string(10): `PADEL`\|`GYM`\|`SAUNA` | Baris hanya dibuat untuk fasilitas yang **diaktifkan**; fasilitas yang tidak diaktifkan tidak punya baris (bukan `is_enabled=false`). |
| `quota_type` | string(10): `HOURS`\|`VISITS`\|`NONE` | `NONE` = akses tanpa dekremen kuota (unlimited atau diskon-only). |
| `quota_value` | decimal(8,2), nullable | Wajib diisi jika `quota_type != NONE`. Satuan jam mendukung desimal (misal 1.5 jam). |
| `discount_percent` | decimal(5,2), default 0 | Diskon tarif fasilitas ini saat kuota terpakai atau untuk akses `NONE`. |
| `booking_priority_days` | integer, default 0 | Khusus Padel: berapa hari lebih awal member ini boleh booking dibanding customer reguler. |
| `time_window_start` / `time_window_end` | time, nullable | Jika diisi, benefit **hanya berlaku** bila jam booking/checkin berada penuh di dalam jendela ini (lihat FR-08 — tidak ada proporsi parsial). |
| `extra_benefits` | JSON, nullable | Perk tampilan/non-kritis yang tidak dipakai di validasi keuangan inti (misal `{"free_racket_qty": 1, "locker_access": true, "towel_service": true}`). **Tidak boleh** dipakai untuk logika yang memotong uang — hanya display & perk operasional ringan. |
| `timestamps` | | |
| *Unique* | `(plan_id, facility)` | Satu plan tidak boleh punya 2 baris benefit untuk fasilitas yang sama. |

### 3.3. Tabel `user_memberships` (Kartu Member — 1 baris = 1 transaksi pembelian)
| Kolom | Tipe | Keterangan |
| :--- | :--- | :--- |
| `id` | ULID, PK | |
| `membership_code` | string(30), unique | Format `MBR-YYYY-NNNN`. |
| `owner_type` | string(15) | **Disalin** dari `membership_plans.ownership_type` saat pembelian (`INDIVIDUAL`\|`ORGANIZATIONAL`) — snapshot, konsisten dengan invarian §5.1. |
| `user_id` | ULID, FK → `users.id` | Untuk `owner_type = INDIVIDUAL`: pemilik/pemakai kartu. Untuk `owner_type = ORGANIZATIONAL`: **PIC/Admin Sponsor** yang tercatat di `sponsor_organizations.sponsor_admin_user_id` — bukan pemakai kuota langsung (lihat §8, siapa yang boleh memakai diatur lewat tabel alokasi Modul 12, bukan lewat kolom ini). Termasuk user walk-in yang dibuat lewat alur Modul 11 (Walk-in Booking). |
| `plan_id` | ULID, FK → `membership_plans.id` | Referensi ke template asal (untuk display nama paket saja — **bukan** sumber kuota aktif). |
| `order_id` | ULID, FK → `orders.id` | Audit pembayaran. |
| `start_date` / `end_date` | date | |
| `status` | string(20): `ACTIVE`\|`EXPIRED`\|`FROZEN`\|`UPGRADED`\|`CANCELLED` | |
| `qr_pass_hash` | string(100), unique, nullable | `Str::random(40)`, diikat ke `user_id`. |
| `purchase_price_snapshot` | decimal(12,2) | Harga riil yang dibayar (setelah diskon manual jika ada) — **tidak pernah** dihitung ulang dari `membership_plans.price` yang live. |
| `manual_discount_percent` | decimal(5,2), default 0 | Diskon tambahan yang diberikan admin saat penjualan (bukan diskon bawaan plan). |
| `manual_discount_reason` | string(255), nullable | **Wajib diisi** jika `manual_discount_percent > 0` (lihat FR-09). |
| `sold_by_admin_id` | ULID, FK → `users.id`, nullable | Null jika pembelian online self-service oleh customer. |
| `timestamps`, `softDeletes` | | |

### 3.4. Tabel `user_membership_balances` (Kuota Riil per Fasilitas — Snapshot)
| Kolom | Tipe | Keterangan |
| :--- | :--- | :--- |
| `id` | ULID, PK | |
| `user_membership_id` | ULID, FK → `user_memberships.id`, cascade delete | |
| `facility` | string(10): `PADEL`\|`GYM`\|`SAUNA` | |
| `quota_type` | string(10): `HOURS`\|`VISITS`\|`NONE` | **Disalin** dari `membership_plan_benefits` saat pembelian. |
| `initial_quota` | decimal(8,2), nullable | Nilai awal (untuk keperluan laporan "sisa vs total"). |
| `remaining_quota` | decimal(8,2), nullable | Nilai yang didekremen berjalan. `NULL` jika `quota_type = NONE`. |
| `discount_percent` | decimal(5,2) | Disalin dari plan benefit. |
| `booking_priority_days` | integer | Disalin. |
| `time_window_start` / `time_window_end` | time, nullable | Disalin. |
| `extra_benefits` | JSON, nullable | Disalin. |
| `timestamps` | | |
| *Unique* | `(user_membership_id, facility)` | |

### 3.5. Tabel `membership_usage_logs` (Audit Trail Wajib — Immutable, Tidak Ada `update()`)
| Kolom | Tipe | Keterangan |
| :--- | :--- | :--- |
| `id` | ULID, PK | |
| `balance_id` | ULID, FK → `user_membership_balances.id` | |
| `change_type` | string(20): `DECREMENT`\|`REVERSAL`\|`MANUAL_ADJUSTMENT` | |
| `quantity` | decimal(8,2) | Selalu positif; arah (tambah/kurang) ditentukan `change_type`. |
| `related_type` / `related_id` | string / ULID, nullable | Polymorphic ke `PadelBooking` atau `FacilityCheckin` yang memicu mutasi ini. |
| `performed_by` | ULID, FK → `users.id`, nullable | Null jika dipicu sistem otomatis (misal scheduler). |
| `notes` | string(255), nullable | Wajib diisi untuk `MANUAL_ADJUSTMENT`. |
| `created_at` | | Tidak ada `updated_at` — baris ini **immutable**, sengaja tidak punya kolom update. |

### 3.6. Tabel `facility_checkins` (Checkin Gym — Generik, Bukan Booking Slot)
> **Koreksi Penting**: Sauna **BUKAN** fasilitas checkin sederhana seperti Gym. Skema yang sudah ada di repo (`wellness_facilities`, `wellness_slots`, `wellness_bookings`, `wellness_waitlists` — migration `2026_09_03_150003_create_wellness_tables.php`) menunjukkan Sauna/Cold Plunge sudah dirancang sebagai **sistem booking slot berkapasitas terbatas**, persis seperti lapangan Padel — bukan sekadar tap-masuk. Oleh karena itu tabel `facility_checkins` di bawah ini **hanya berlaku untuk `GYM`**; usage Sauna mengikuti pola §3.7b (menumpang di `wellness_bookings`, bukan tabel checkin generik).

| Kolom | Tipe | Keterangan |
| :--- | :--- | :--- |
| `id` | ULID, PK | |
| `facility` | string(10), selalu `GYM` | Padel dan Sauna **tidak** memakai tabel ini — usage-nya tercatat lewat `padel_bookings` (§3.7a) dan `wellness_bookings` (§3.7b) yang sudah ada. |
| `balance_id` | ULID, FK → `user_membership_balances.id` | |
| `user_id` | ULID, FK → `users.id` | |
| `staff_id` | ULID, FK → `users.id`, nullable | Kasir/staf yang memindai. |
| `checkin_at` | datetime | |
| `timestamps` | | |

### 3.7a. Relasi ke `padel_bookings` (Existing Table — Tambahan Kolom)
- `membership_balance_id` (ULID, nullable, FK → `user_membership_balances.id`) — menggantikan rencana `membership_id` di v1.
- `member_discount_court` (decimal 12,2, default 0.00) — tetap seperti v1.
- `member_hours_consumed` (decimal 8,2, nullable) — jumlah jam yang didekremen dari `remaining_quota` untuk booking ini (dibutuhkan agar reversal saat cancel tahu **persis** berapa yang harus dikembalikan, termasuk kasus durasi booking non-integer seperti 1.5 jam).

### 3.7b. Relasi ke `wellness_bookings` (Existing Table — Tambahan Kolom, Benefit Sauna)
- `membership_balance_id` (ULID, nullable, FK → `user_membership_balances.id`) — mengikuti pola yang sama seperti Padel, **bukan** lewat `facility_checkins`.
- `member_discount_amount` (decimal 12,2, default 0.00) — diskon dari `discount_percent` benefit Sauna, dihitung dari `wellness_slots.facility.price_per_person`.
- `member_sessions_consumed` (integer, nullable) — jumlah sesi yang didekremen dari `remaining_quota` (`quota_type = VISITS`) untuk booking Sauna ini; dipakai saat reversal agar tahu persis berapa yang harus dikembalikan jika `wellness_bookings.status` dibatalkan.
- Validasi konsumsi kuota Sauna mengikuti alur yang **identik** dengan FR-03 (Padel), hanya bedanya dipicu di titik checkout `wellness_bookings` bukan `padel_bookings`, dan `quota_type` yang relevan untuk Sauna secara default adalah `VISITS` (bukan `HOURS`), karena satuan booking Sauna adalah per-sesi, bukan per-jam.

---

## 4. Spesifikasi Kebutuhan Fungsional

### FR-01: Halaman Admin "Jual/Booking Membership" (Filament — Panel Baru, Layout Berbeda dari Referensi Pasar)
1. Katalog paket **dikelompokkan per tab fasilitas** (Padel / Gym / Sauna / All-Access) — bukan satu grid rata tanpa kategori seperti pola umum di pasar — supaya kasir langsung tahu konteks fasilitas yang dijual tanpa membaca deskripsi kartu satu-satu.
2. Klik "Pilih Paket" pada satu kartu membuka **slide-over panel** (bukan panel statis permanen di sisi kanan) berisi: pencarian pelanggan (dengan tombol "Walk-in" yang reuse alur pembuatan customer minimal dari Modul 11), tanggal mulai, ringkasan benefit paket (dirender dari `membership_plan_benefits`, bukan teks statis), field diskon manual (tersembunyi kecuali admin punya permission — lihat FR-09), dan total harga akhir yang dihitung backend secara real-time (bukan dihitung di JS).
3. Submit menghasilkan: 1 baris `orders` (status `PAID` jika tunai/EDC/QRIS di tempat, `PENDING` jika link pembayaran online), 1 baris `user_memberships`, N baris `user_membership_balances` (disalin dari `membership_plan_benefits` milik plan tersebut), `membership_code` & `qr_pass_hash` baru.
4. Halaman ini menggunakan komponen visual yang sama dengan halaman Filament existing (`BookOfflineCourt.php`) — bukan skin/tema baru — supaya konsisten dengan identitas visual admin Club 61, bukan tempelan template generik.

### FR-02: CRUD Template Paket (`membership_plans` + Matrix Benefit)
1. Admin membuat/mengedit plan: isi `name`, `code`, `duration_days`, `price`, lalu untuk tiap fasilitas (Padel/Gym/Sauna) toggle aktif/nonaktif — jika aktif, isi `quota_type`, `quota_value`, `discount_percent`, opsional `time_window`, opsional `booking_priority_days`, opsional `extra_benefits`.
2. **Mengedit plan yang sudah punya member aktif TIDAK mengubah** `user_membership_balances` milik member yang sudah beli (lihat invarian §5.1) — perubahan hanya berlaku untuk pembelian baru setelah tanggal edit.
3. Menonaktifkan plan (`is_active = false`) hanya menyembunyikannya dari katalog FR-01 — tidak membekukan member existing yang memakainya.

### FR-03: Konsumsi Kuota Otomatis Saat Checkout Padel (Generalisasi FR-02 v1)
1. Saat `POST /api/v1/padel/bookings/checkout`, backend mencari `user_membership_balances` milik user yang login dengan `facility = PADEL`, `user_membership.status = ACTIVE`, dan `user_membership.end_date >= booking_date`.
2. Jika `quota_type = HOURS`: hitung durasi booking dalam jam (mendukung desimal), tolak jika `remaining_quota < durasi_booking` (tidak boleh minus). Dekremen sejumlah durasi persis, tulis `membership_usage_logs` (`DECREMENT`), simpan `member_hours_consumed` di baris `padel_bookings`.
3. Jika `quota_type = VISITS`: dekremen `1` per booking terlepas dari durasi.
4. Jika `quota_type = NONE`: tidak ada dekremen, hanya terapkan `discount_percent` ke tarif lapangan.
5. Jika `remaining_quota` mencapai `0` (untuk `HOURS`/`VISITS`), **hanya balance fasilitas Padel milik member ini** yang berhenti memberi benefit — balance Gym/Sauna miliknya (jika ada, dari plan All-Access) **tetap aktif** selama belum habis sendiri.
6. **Guard Mutual-Exclusivity dengan Sponsor Quota**: jika `payment_method` pada request checkout adalah `SPONSOR_QUOTA` (Modul 12), langkah 1-5 di atas **dilewati sepenuhnya** — konsumsi kuota untuk booking tersebut terjadi lewat jalur `bookWithSponsorQuota()` (Modul 12 §8), bukan lewat membership pribadi user. Tanpa guard ini, user yang kebetulan punya membership pribadi **dan** terdaftar sebagai anggota sponsor akan kena dekremen dobel dari dua balance berbeda untuk satu booking yang sama.

### FR-04a: Checkin Gym (via `facility_checkins`)
1. Staf memindai QR pass atau mencari member secara manual di desk Gym.
2. Backend mencari `user_membership_balances` dengan `facility = GYM`, `user_membership.status = ACTIVE`, `end_date >= today()`.
3. Jika `quota_type = VISITS`: tolak checkin bila `remaining_quota <= 0`; jika lolos, dekremen `1`, tulis `facility_checkins` + `membership_usage_logs` (`DECREMENT`).
4. Jika `quota_type = NONE`: checkin selalu diizinkan (dicatat untuk statistik utilisasi, tanpa dekremen).

### FR-04b: Konsumsi Kuota Otomatis Saat Checkout Sauna (via `wellness_bookings`, Bukan Checkin)
1. Saat customer/kasir membuat `wellness_bookings` (memilih `wellness_slots` yang tersedia), backend mencari `user_membership_balances` milik user tersebut dengan `facility = SAUNA`, `user_membership.status = ACTIVE`, `end_date >= session_date`.
2. Jika `quota_type = VISITS`: tolak jika `remaining_quota <= 0`; jika lolos, dekremen `1`, tulis `membership_usage_logs` (`DECREMENT`), simpan `member_sessions_consumed = 1` di baris `wellness_bookings`.
3. Jika `quota_type = NONE`: tidak ada dekremen, hanya terapkan `discount_percent` ke `price_per_person`.
4. Kapasitas slot (`wellness_slots.max_capacity`/`booked_count`) tetap ditegakkan seperti biasa **terlepas** dari status membership — member tidak melewati antrian kapasitas, hanya mendapat potongan kuota/harga.

### FR-05: Reversal Kuota Saat Pembatalan/Refund (Wajib Ada Log, Tidak Boleh Silent)
1. **Padel**: saat `adminCancelAndRefund()` (Modul 02) membatalkan booking yang punya `membership_balance_id` terisi, sistem mengembalikan **persis** `member_hours_consumed` (bukan flat 1 unit) ke `remaining_quota`, tulis `membership_usage_logs` (`REVERSAL`) dengan `related_type/id` menunjuk booking tersebut.
2. **Sauna**: saat `wellness_bookings.status` dibatalkan (dan slot dikembalikan ke kapasitas), reversal `+member_sessions_consumed` ke balance Sauna, log serupa.
3. **Gym**: staf dapat membatalkan sebuah `facility_checkins` (misal salah scan) dalam window koreksi yang sama, memicu reversal `+1` dengan log serupa.
4. Jika status `user_membership` sebelumnya berubah jadi `EXPIRED` akibat kuota habis, reversal mengembalikannya ke `ACTIVE` **hanya jika** `end_date >= today()`.

### FR-06: Validasi Jendela Waktu (Time Window — After Hour/Happy Hour)
- Benefit dengan `time_window_start`/`time_window_end` terisi **hanya berlaku** jika seluruh rentang booking (`start_time` s.d. `end_time`) berada di dalam jendela tersebut.
- **Tidak ada proporsi parsial**: booking yang menyentuh sebagian di luar jendela (misal booking 17:30–19:00 untuk plan "Happy Hour 10:00–15:00") **ditolak benefitnya secara penuh** (fallback ke tarif reguler tanpa diskon/kuota), bukan dihitung sebagian — mencegah ambiguitas kalkulasi finansial.

### FR-07: Diskon Manual oleh Admin Saat Penjualan (Audit Wajib)
1. Field `manual_discount_percent` di FR-01 hanya muncul untuk role yang memiliki permission `membership.apply-manual-discount` (didefinisikan lewat RBAC Modul 09 — bukan hardcode role name).
2. Jika `manual_discount_percent > 0`, `manual_discount_reason` **wajib** diisi (validasi backend menolak request tanpa alasan) — direkam permanen di `user_memberships`, tidak bisa dihapus setelah tersimpan.
3. `purchase_price_snapshot` dihitung backend sebagai `plan.price * (1 - manual_discount_percent/100)` **pada saat transaksi**, disimpan sebagai nilai final — tidak pernah dihitung ulang dari `plan.price` yang mungkin sudah berubah di kemudian hari.

### FR-08: Auto-Expiry Scheduler (Generalisasi)
- `php artisan membership:sync-expired` berjalan harian, mengevaluasi tiap `user_memberships` berstatus `ACTIVE`:
  - Set `EXPIRED` jika `end_date < today()`.
  - **ATAU** jika **seluruh** balance bertipe `HOURS`/`VISITS` miliknya sudah `remaining_quota <= 0` (balance `NONE` diabaikan dari kondisi ini karena tidak pernah "habis") **dan** tidak ada lagi booking padel / wellness mendatang yang memakai kuota kartu itu (sejak 12 Nov 2026: kuota yang sudah terpotong Jadwal Tetap untuk booking yang belum dimainkan tidak membuat kartu EXPIRED lebih awal).
- Kartu yang `EXPIRED` otomatis kembali ke tarif reguler tanpa diskon/kuota di seluruh fasilitas.

### FR-08a: Batalkan Membership (12 Nov 2026, tanpa migrasi)
Untuk kartu yang terjual karena kesalahan (mis. harga paket salah input). Tombol **Batalkan Membership** di detail kartu Data Member, izin `cancel_membership` (pintu belakang: bawaan super admin saja, dapat diberikan lewat matrix Roles).
- Hanya kartu **individu** berstatus **ACTIVE**. Kartu corporate / sponsor dan Tagihan Bulanan dikelola lewat Kelola Sponsor.
- **Ditolak** selama kartu masih dipakai — admin menyelesaikannya dulu: booking padel / wellness mendatang yang memakai kuota (termasuk yang masih ditahan / menunggu bayar), Jadwal Tetap yang belum dihentikan (aktif / dijeda), dan pembelian perpanjangan yang menunggu bayar (perpanjangan yang lunas mengaktifkan kembali kartu induknya).
- Alasan wajib. Hasil: status `CANCELLED` permanen; sisa kuota tiap fasilitas dihanguskan lewat `MANUAL_ADJUSTMENT` di Audit Log Mutasi Kuota (`related_type = MEMBERSHIP_CANCELLED`, catatan "Kartu dibatalkan: alasan", petugas) — sumber keterangan "dibatalkan oleh … pada … alasan" di detail kartu; Log Aktivitas `membership.cancelled` (Peringatan). Kuota awal tidak diubah.
- **Uang tidak disentuh**: tanpa refund & tanpa voucher saldo; penjualan kartu tetap tercatat di Buku Transaksi & Analytics.
- Kartu batal tidak bisa dipakai di checkout web / aplikasi, kasir, check-in, maupun wellness (semua mensyaratkan `ACTIVE`), tidak diubah sinkronisasi harian, Terapkan Kuota / Aturan, maupun notifikasi pembayaran yang terlambat. Pembelian berikutnya oleh customer yang sama menjadi kartu baru (bayar penuh).
- Data Member: status **DIBATALKAN** dan filter "Hanya DIBATALKAN".
- Sejak 12 Nov 2026 daftar Data Member (dulu "Data Pelanggan") hanya berisi kartu yang pernah aktif (punya tanggal mulai = lunas). Pembelian yang menunggu bayar, batal sebelum bayar, dan catatan perpanjangan yang sudah digabung ke kartu induk tidak tampil (filter "PENDING PAYMENT" dihapus); angka "member baru bulan ini" menghitung kartu yang mulai aktif bulan ini. Riwayatnya tetap di Log Aktivitas: batal oleh customer (`membership.online_order_cancelled`) dan batal otomatis setelah 24 jam (`membership.online_order_expired`, pelaku Sistem). Kolom Saluran Beli menampilkan gateway pembayaran yang lunas ("Online · DOKU" / "Online · Midtrans"), bukan lagi selalu "Online Midtrans".
- Daftar Data Member memakai pagination (25 / 50 / 100 per halaman) dan filter rentang **Mulai Aktif** (tanggal mulai kartu), bisa digabung dengan status & pencarian; kotak "Paket Membership Aktif" menghitung paket yang aktif dijual dari Paket Membership. Tanpa index baru: pencarian nama / email / HP berpola "mengandung kata" (tidak terbantu index) dan cukup cepat dengan pagination; index `start_date` baru dipertimbangkan bila kartu sudah puluhan ribu.

### FR-09: Kartu Member Digital di Customer Portal
- `/dashboard` menampilkan kartu dinamis: nama paket aktif, kode member, QR pass, tanggal berakhir, dan **rincian sisa kuota per fasilitas** (bukan cuma satu angka) — misal "Padel: 12.5 jam tersisa · Gym: Unlimited · Sauna: 3 sesi tersisa".
- `/my-club` menampilkan riwayat pemakaian (`membership_usage_logs` milik user tersebut) sebagai timeline transparan.

---

## 5. Invarian QA & Keamanan Data (Architectural Invariants)

| No | Invarian | Mekanisme Proteksi Backend |
| :--- | :--- | :--- |
| 5.1 | **Snapshot Immutability** | `user_membership_balances` disalin dari `membership_plan_benefits` **hanya sekali**, saat pembelian. Tidak ada kode manapun yang membaca `membership_plan_benefits` untuk menghitung entitlement member yang sudah aktif — hanya dipakai saat (a) render katalog untuk pembelian baru, (b) proses penyalinan saat pembelian. Melanggar ini = bug kelas "time bomb" paling berbahaya di modul ini (perubahan harga besok diam-diam mengubah hak member kemarin). |
| 5.2 | **Zero Silent Quota Mutation** | Setiap perubahan `remaining_quota` wajib dibungkus transaksi DB yang juga menulis `membership_usage_logs` di baris yang sama. Tidak ada method service yang mengizinkan `update(['remaining_quota' => ...])` tanpa memanggil logger — ditegakkan lewat 1 method terpusat `MembershipBalanceService::adjustQuota()` yang menjadi **satu-satunya** jalur mutasi. |
| 5.3 | **Anti-Race Condition Dekremen** | `adjustQuota()` memakai row-level lock (`lockForUpdate()`) pada baris `user_membership_balances` yang sama, sejalan dengan pola `Cache::lock()` atomic slot-locking yang sudah dipakai di Booking Engine — mencegah 2 booking simultan mendekremen kuota yang sama menjadi negatif. |
| 5.4 | **Reversal Presisi, Bukan Flat Unit** | Reversal Padel selalu memakai nilai `member_hours_consumed` yang tersimpan di transaksi asal, bukan konstanta 1 — mencegah kebocoran kuota pada booking berdurasi pecahan jam. |
| 5.5 | **Isolasi Antar-Fasilitas dalam 1 Kartu** | Kuota Padel habis pada member All-Access **tidak** membekukan balance Gym/Sauna miliknya — evaluasi status `EXPIRED` di FR-08 memeriksa seluruh balance, tidak berhenti di balance pertama yang habis. |
| 5.6 | **Anti-Manipulasi Diskon** | `purchase_price_snapshot` dan seluruh kalkulasi diskon dihitung backend dari data server (`membership_plan_benefits` + `manual_discount_percent` bervalidasi permission) — payload harga dari frontend/JS tidak pernah dipercaya. |
| 5.7 | **Jendela Waktu Tanpa Ambiguitas** | Validasi `time_window` bersifat all-or-nothing (FR-06) — tidak ada jalur kode yang menghitung diskon proporsional parsial, menghindari kelas bug pembulatan/ambiguitas finansial. |
| 5.8 | **Audit Trail Immutable** | Tabel `membership_usage_logs` tidak memiliki `updated_at` dan tidak ada method `update()`/`delete()` yang diekspos di model — koreksi kesalahan hanya lewat baris `REVERSAL`/`MANUAL_ADJUSTMENT` baru, never mutate history. |

---

## 6. Relasi ke Modul 12 (Sponsor Korporat) — Membership Sebagai Fondasi Tunggal Kuota

Sponsor Korporat (Modul 12) **bukan sistem kuota terpisah**. Sebuah organisasi sponsor pada dasarnya adalah pembeli 1 `user_memberships` dengan `owner_type = ORGANIZATIONAL` — angka jam yang dibeli sponsor tersebut **hidup sebagai `user_membership_balances`** milik membership itu, sama seperti member individual.

- `sponsor_organizations` (Modul 12) hanyalah **wrapper metadata tipis**: `user_membership_id` (FK 1:1 ke membership yang dibeli), nama perusahaan, PIC. Tabel ini **tidak** menyimpan angka `hours_remaining` sendiri.
- `sponsor_organization_members` (Modul 12) adalah **lapisan alokasi/otorisasi**, bukan ledger kedua: `allocated_hours`/`hours_used` di tabel itu menentukan siapa boleh pakai berapa jam sebagai plafon, tapi pemotongan yang sah selalu memanggil `MembershipBalanceService::adjustQuota()` pada **satu-satunya baris real** `user_membership_balances` milik membership sponsor tersebut.
- **Invarian wajib**: total `allocated_hours` seluruh anggota sebuah sponsor tidak boleh melebihi `remaining_quota` membership induknya pada saat alokasi dibuat/diubah — dicek di service Modul 12, bukan diasumsikan.
- Kenapa ini penting: kalau kuota sponsor disimpan sebagai angka duplikat di tabel sendiri (desain v1 Modul 12 yang lama), angka itu bisa **drift** dari kuota yang sebenarnya tercatat di sistem Membership begitu ada bug kecil di salah satu sisi. Dengan 1 sumber kebenaran (`user_membership_balances.remaining_quota`), kelas bug itu tertutup secara struktural.

---

## 7. Rencana Pengujian

1. `test_editing_plan_benefit_does_not_affect_existing_member_balances` — **invarian 5.1**, test paling kritis di modul ini.
2. `test_hour_quota_decrements_by_exact_fractional_booking_duration`.
3. `test_booking_partially_outside_time_window_gets_zero_benefit_not_partial`.
4. `test_cancellation_reverses_exact_consumed_hours_and_writes_reversal_log`.
5. `test_manual_discount_rejected_without_reason_or_without_permission`.
6. `test_concurrent_bookings_on_same_balance_do_not_produce_negative_quota` (race condition, row lock).
7. `test_facility_checkin_only_affects_its_own_facility_balance` (isolasi Padel vs Gym vs Sauna dalam 1 kartu All-Access).
8. `test_auto_expiry_triggers_on_end_date_or_all_quota_exhausted_whichever_first`.
9. `test_walk_in_customer_can_purchase_membership_reusing_existing_walkin_user_flow`.
10. `test_qr_pass_hash_unique_and_bound_to_user_id`.
11. `test_usage_log_table_has_no_update_path` (proteksi arsitektural, bukan cuma unit test data).
12. `test_sauna_booking_consumes_visit_quota_via_wellness_bookings_not_facility_checkins` — memastikan Sauna tidak salah dirute ke tabel checkin generik.
13. `test_checkout_with_sponsor_quota_payment_method_skips_personal_membership_deduction` — **guard mutual-exclusivity** FR-03 poin 6, mencegah dekremen dobel saat user punya membership pribadi sekaligus anggota sponsor.

---

## 8. Ruang Lingkup & Batasan (Out of Scope v1)

- **Refund tunai atas sisa kuota yang belum terpakai** — v1 hanya mendukung pembatalan booking individual (FR-05), bukan pencairan sisa saldo kartu membership secara keseluruhan.
- **Konversi kuota antar fasilitas** (misal tukar sisa jam Padel jadi sesi Gym) — tidak didukung, tiap balance terkunci ke fasilitasnya.
- **Freeze/pause mandiri oleh customer** — status `FROZEN` hanya bisa diubah oleh admin (reuse mekanisme FR-04 v1 lama), belum ada tombol self-service.
- **Scoping multi-cabang** — skema ini didesain untuk 1 database (single-tenant). Saat Modul 13 (Multi-Branch Tenancy) berjalan, tabel-tabel modul ini otomatis terisolasi per database cabang tanpa kolom `branch_id` tambahan (sejalan dengan prinsip arsitektur Modul 13) — tidak perlu perubahan skema.
