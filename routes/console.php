<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Cek BOOKING_OPEN_UNTIL / BOOKING_CLOSED_DATES setelah mengubah .env + `php artisan config:cache` di server.
Artisan::command('booking:window', function () {
    $until = \App\Support\BookingWindow::openUntil();
    $closed = \App\Support\BookingWindow::closedDates();
    $rawUntil = trim((string) config('booking_window.open_until', ''));

    $this->line('Booking dibuka sampai tanggal main : '.($until ? \App\Support\BookingWindow::label($until) : 'tanpa batas'));
    if ($rawUntil !== '' && ! $until) {
        $this->error("BOOKING_OPEN_UNTIL \"{$rawUntil}\" tidak valid (format Y-m-d, mis. 2026-11-25) — batas TIDAK aktif.");
    }
    $this->line('Tanggal tutup sehari penuh         : '.($closed ? implode(', ', array_map(fn ($d) => \App\Support\BookingWindow::label($d), $closed)) : '-'));
    $hours = array_filter(\App\Support\BookingWindow::closures(), fn ($c) => $c['start'] !== null);
    $this->line('Tutup jam tertentu                 : '.($hours ? implode(', ', array_map(fn ($c) => \App\Support\BookingWindow::label($c['date'])." {$c['start']}–{$c['end']}", $hours)) : '-'));
    $entries = array_filter(array_map('trim', explode(',', (string) config('booking_window.closed_dates', ''))));
    if (count($entries) > count(\App\Support\BookingWindow::closures())) {
        $this->error('Ada isi BOOKING_CLOSED_DATES yang formatnya salah dan DIABAIKAN. Format: 2026-11-26 atau 2026-11-04 06:00-13:00');
    }
    $this->line('Keterangan tutup                   : '.(\App\Support\BookingWindow::closedReason() ?? '-'));
})->purpose('Tampilkan batas tanggal booking & tanggal tutup yang sedang berlaku');

// Tugas yang memanggil Midtrans / DOKU jalan di latar (runInBackground): kalau gateway lambat, tugas lain di menit yang
// sama tidak ikut menunggu. withoutOverlapping: satu proses per tugas walau jalan sebelumnya belum selesai.
\Illuminate\Support\Facades\Schedule::command('padel:release-expired-slots')->everyMinute()->withoutOverlapping(5)->runInBackground();
\Illuminate\Support\Facades\Schedule::command('membership:sync-expired')->dailyAt('00:01');
// Cadangan webhook Midtrans: order yang sudah dibayar tapi notifikasinya tidak sampai tetap lunas otomatis.
// Mutex 10 menit (default 24 jam): kalau proses mati di tengah jalan (deploy/OOM), rekonsiliasi tidak ikut mati seharian.
\Illuminate\Support\Facades\Schedule::command('payment:reconcile-midtrans')->everyFiveMinutes()->withoutOverlapping(10)->runInBackground();
// Cadangan webhook DOKU (Modul 27): notifikasi yang belum tuntas & tagihan DOKU yang masih menunggu. Tanpa kunci = dilewati.
\Illuminate\Support\Facades\Schedule::command('payment:reconcile-doku')->everyFiveMinutes()->withoutOverlapping(10)->runInBackground();
// Jejak notifikasi DOKU disimpan 90 hari; log akses panduan karyawan 180 hari (GuideAccessLog::KEEP_DAYS).
\Illuminate\Support\Facades\Schedule::command('model:prune', ['--model' => [\App\Models\Payment\DokuNotification::class, \App\Models\Payment\DokuSnapVaNumber::class, \App\Models\Staff\GuideAccessLog::class]])->dailyAt('02:45')->withoutOverlapping();
// Bayar Otomatis kasir (QR / VA) yang ditinggal sampai kedaluwarsa → order ikut dibatalkan (bukan UNPAID selamanya).
\Illuminate\Support\Facades\Schedule::command('pos:sweep-auto-payments')->everyFiveMinutes()->withoutOverlapping(10)->runInBackground();
// Retensi Log Aktivitas (Modul 16) — default simpan 24 bulan, atur lewat AUDIT_RETENTION_MONTHS.
\Illuminate\Support\Facades\Schedule::command('audit:prune')->dailyAt('02:30')->withoutOverlapping();
// Digital Signage: file yang tidak dipakai & tidak tayang di TV selama 7 hari (yang masih tayang tidak pernah dihapus).
\Illuminate\Support\Facades\Schedule::command('signature:clean-files')->dailyAt('03:00')->withoutOverlapping();

// Modul 17: cocokkan Buku Transaksi dengan pembayaran & refund kemarin (selisih → Log Aktivitas KRITIS).
\Illuminate\Support\Facades\Schedule::command('ledger:verify')->dailyAt('01:15')->withoutOverlapping();
// Jadwal Tetap Mingguan: booking rutin (corporate / member) dibuat otomatis sampai N minggu ke depan, dipotong kuota jam.
\Illuminate\Support\Facades\Schedule::command('padel:generate-recurring')->dailyAt('00:20')->withoutOverlapping(30);
