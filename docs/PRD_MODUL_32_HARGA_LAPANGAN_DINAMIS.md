# PRD Modul 32 — Harga Lapangan Dinamis (Harga Khusus per Periode)

| | |
|---|---|
| **Versi** | v0.2 (dibangun 5 Nov 2026, migrasi `2026_11_04_100003_create_padel_price_rules_table`) |
| **Tanggal** | 4 Nov 2026 |
| **Asal** | WA klien 4 Nov 2026: *"Izin aktifin 27 nov start harga normal lapangan club 61 di website sampai seterusnya ya"* (lampiran: Proposal Harga Padel Court) + permintaan PM: harga lapangan dibuat dinamis. |
| **Dependensi** | Booking Padel (Modul 02), Walk-In POS (Modul 11), Master Data (tarif lapangan, jam peak, tanggal merah), Batas Tanggal Booking (`BOOKING_OPEN_UNTIL` / `BOOKING_CLOSED_DATES`) |
| **Production** | Sudah live dengan data nyata → migrasi **hanya menambah** tabel baru. Selama belum ada Harga Khusus yang diisi, harga **sama persis** dengan sekarang. |

---

## 1. Masalah

- Harga lapangan sekarang hanya **satu per lapangan** (tarif reguler + tarif prime di Master Data) dan jam peak hanya **satu jadwal** untuk semua tanggal.
- Harga promo sebelum Grand Opening Rp100.000/jam masih berlaku sampai main tanggal 25 Nov. Mulai main tanggal **27 Nov** klien minta harga normal sesuai proposal:

  | Hari / Waktu | Harga |
  |---|---|
  | Weekend (Sabtu & Minggu) | Rp200.000 / jam |
  | Weekday 06:00–17:00 | Rp200.000 / jam |
  | Weekday 17:00–00:00 | Rp300.000 / jam |

- Saat ini tanggal sesudah 25 Nov hanya **ditutup** lewat `BOOKING_OPEN_UNTIL`. Kalau dibuka sekarang, booking tanggal 27 ke atas tertagih Rp100.000. Kalau harga Master Data diganti sekarang, booking sampai 25 Nov ikut naik.
- Ke depan perlu harga yang bisa diatur per periode (promo, event, musim) tanpa ubah kode.

## 2. Keputusan

| # | Topik | Keputusan |
|---|---|---|
| 1 | Tempat mengatur | **Satu tempat**: halaman Master Data, tab **Harga Khusus** (di samping tab Tarif Lapangan & Jam Peak). Bukan menu terpisah. |
| 2 | Dua lapis harga | **Harga Dasar** (yang sudah ada: tarif reguler / prime per lapangan + jam peak) dan **Harga Khusus per Periode** (baru, pengecualian). Harga Khusus yang cocok menang; kalau tidak ada yang cocok → Harga Dasar. |
| 3 | Dasar perhitungan | **Tanggal & jam main**, bukan tanggal pesan. Dihitung per jam (booking 2 jam yang melewati batas aturan dihitung per jam). |
| 4 | Tanggal merah | Default **ikut aturan hari Minggu** (sama seperti sekarang). Bisa diubah nanti dengan Harga Khusus yang memilih hari **"Tanggal Merah"**. |
| 5 | Booking yang sudah ada | Tidak berubah — nominal booking yang sudah dibayar / di-hold sudah tersimpan. |
| 6 | Hak akses | Memakai izin yang sudah ada **`manage_court_pricing`** (Kelola Tarif Sewa Lapangan Per Jam). Tidak ada izin baru. |
| 7 | Yang tidak disentuh | Tarif setoran coaching (tabel sendiri), jam peak untuk aturan akses membership (peak / di luar peak), `BOOKING_CLOSED_DATES`, template impor penjualan. |

## 3. Harga Khusus per Periode

### 3.1 Isi satu aturan
| Field | Keterangan |
|---|---|
| Nama | mis. "Promo Pra-Grand Opening", "Tarif Turnamen". Tampil di Simulasi Harga & Log Aktivitas. |
| Lapangan | Semua lapangan, atau pilih lapangan tertentu. |
| Berlaku dari – sampai | Tanggal main. "Sampai" boleh kosong = berlaku seterusnya. |
| Hari | Senin–Minggu dan/atau **Tanggal Merah** (centang). |
| Jam | Jam mulai – jam selesai (jam bulat, jam selesai eksklusif; 24 = tengah malam). Slot ikut aturan kalau **jam mulai slot** ada di rentang. |
| Harga per jam | Wajib lebih dari Rp0. |
| Jenis jam | **Reguler** atau **Prime** — untuk label di grid POS / jadwal customer. |
| Prioritas | Angka kecil menang bila dua aturan sama-sama cocok. |
| Aktif | Nonaktif = diabaikan (tidak dihapus, riwayat tetap ada). |

### 3.2 Cara menentukan harga satu jam
1. Tanggal main tanggal merah? → cari aturan aktif yang mencentang **Tanggal Merah**. Kalau tidak ada → perlakukan sebagai **hari Minggu**.
2. Cari aturan aktif yang cocok: lapangan, tanggal di rentang berlaku, hari, jam. Ambil prioritas terkecil.
3. Tidak ada yang cocok → **Harga Dasar** Master Data (tarif reguler / prime menurut jam peak), persis seperti sekarang.

### 3.3 Pengaman
- **Aturan bentrok dengan prioritas sama** (lapangan, tanggal, hari, dan jam beririsan) → ditolak saat simpan, supaya tidak ada harga yang ambigu.
- **Simulasi Harga**: pilih tanggal + lapangan → tabel harga tiap jam beserta sumbernya (nama Harga Khusus / Harga Dasar). Dipakai untuk mengecek sebelum dibuka ke customer.
- Semua tambah / ubah / nonaktifkan tercatat di **Log Aktivitas** (sebelum → sesudah).
- Harga Khusus yang sudah lewat tanggal berlakunya tetap tersimpan sebagai riwayat.

## 4. Yang berubah di sistem

| Bagian | Perubahan |
|---|---|
| Database | 1 tabel baru `padel_price_rules` (additive). Tidak ada kolom lama yang diubah. |
| `PeakHourService` | `hourlyRate()` & `courtFee()` menjadi satu-satunya penentu harga (Harga Khusus → Harga Dasar). Label Reguler / Prime ikut aturan yang dipakai. |
| 4 titik yang membaca tarif langsung | Grid POS Walk-In, Booking System (2 tempat), jadwal booking customer (web / API) → dialihkan ke `PeakHourService`. |
| Otomatis ikut (sudah lewat `courtFee`) | Hold & checkout online, kasir Walk-In, selisih reschedule, Jadwal Tetap Mingguan, benefit membership, voucher. |
| Master Data → tab Harga Khusus | Tab baru: **Harga Khusus per Periode** + **Simulasi Harga**. |
| Tidak berubah | Booking lama, coaching, aturan akses membership, batas tanggal booking. |

## 5. Penerapan untuk Grand Opening

Langkah setelah fitur dideploy:

1. **Harga Khusus** "Promo Pra-Grand Opening": semua lapangan, berlaku s.d. **25 Nov 2026**, semua hari + Tanggal Merah, jam buka–tutup, Rp100.000, Reguler.
2. **Harga Dasar** diganti ke harga normal: reguler Rp200.000, prime Rp300.000; jam peak **Senin–Jumat 17:00–24:00** saja (Sabtu, Minggu, tanggal merah = reguler).
3. **Simulasi Harga** cek tanggal 25 Nov (harus Rp100.000) dan 27 Nov (Rp200.000 / Rp300.000 sesuai jam).
4. Kosongkan `BOOKING_OPEN_UNTIL` (biarkan `BOOKING_CLOSED_DATES=2026-11-26`), lalu `php artisan config:cache`.

Hasilnya: main s.d. 25 Nov tetap Rp100.000, 26 Nov tutup (event Grand Opening), main 27 Nov ke atas pakai harga normal — dan tidak ada yang perlu diubah manual pada tanggal 26.

> **Catatan jam peak & membership:** langkah 2 mengubah jam peak di Master Data, yang juga dipakai aturan akses kartu member "hanya di luar jam peak" / "hanya di jam peak". Sebelum langkah 2, cek apakah ada paket membership aktif yang memakai aturan itu; kalau ada, akhir pekan akan terhitung **di luar jam peak** sejak saat itu.

## 6. Pertanyaan terbuka

| # | Pertanyaan | Status |
|---|---|---|
| 1 | Harga semua lapangan sama, atau berbeda (mis. indoor / outdoor)? | Menunggu klien — fitur mendukung keduanya. |
| 2 | Tanggal merah sesudah GO | **Ikut hari Minggu** (Rp200.000); bisa diubah nanti lewat Harga Khusus "Tanggal Merah". |

## 7. Pengujian

- Tanpa Harga Khusus: harga semua alur sama dengan sekarang (regresi).
- Harga Khusus cocok vs tidak cocok; batas tanggal berlaku (hari terakhir, tanpa tanggal akhir); booking yang melewati batas jam dihitung per jam.
- Prioritas; bentrok prioritas sama ditolak; aturan nonaktif diabaikan.
- Tanggal merah: tanpa aturan khusus = Minggu; dengan aturan "Tanggal Merah" = aturan itu.
- Harga yang tampil (grid POS, jadwal customer) = yang di-hold = yang ditagih; selisih reschedule dari tanggal promo ke tanggal normal.
- Hak akses: tanpa `manage_court_pricing` tidak bisa tambah / ubah, termasuk lewat pemanggilan method Livewire langsung.
