<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Batas tanggal main yang boleh dibooking
    |--------------------------------------------------------------------------
    |
    | open_until: tanggal main TERAKHIR yang boleh dibooking (format Y-m-d, mis. "2026-11-25"). Tanggal main sesudahnya
    | ditolak di semua jalur (customer online, POS Walk-In termasuk coaching, reschedule) — dipakai supaya harga lama
    | tidak dipesan untuk tanggal setelah harga baru berlaku. Kosong = tanpa batas (bawaan).
    |
    | closed_dates: tanggal main yang TUTUP sehari penuh untuk semua lapangan, pisahkan dengan koma
    | (mis. "2026-11-26"). Berlaku juga untuk Jadwal Tetap: minggu itu dilewati tanpa memotong kuota jam.
    | Tutup jam tertentu: "2026-11-04 06:00-13:00". Hanya satu lapangan: tambahkan "@Nama Lapangan", mis.
    | "2026-11-20 13:00-17:00 @Court Indosat" (lapangan lain & jam lain tetap bisa dibooking). Nilai yang memakai spasi
    | wajib diberi tanda kutip di .env. Keterangan closed_reason tidak dipakai untuk penutupan satu lapangan.
    | closed_reason: keterangan singkat yang ikut tampil di pesan (mis. "Grand Opening"). Boleh kosong.
    |
    | Jadwal Tetap (dibayar kuota jam membership, bukan harga lapangan) tidak terkena open_until — hanya closed_dates.
    | Setelah mengubah nilai di .env server jalankan `php artisan config:cache`, lalu cek dengan `php artisan booking:window`.
    |
    */

    'open_until' => env('BOOKING_OPEN_UNTIL'),

    'closed_dates' => env('BOOKING_CLOSED_DATES'),

    'closed_reason' => env('BOOKING_CLOSED_REASON'),

];
