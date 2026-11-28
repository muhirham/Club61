<?php

namespace App\Support\Monitoring;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Str;
use Laravel\Pulse\Facades\Pulse;

/**
 * Mencatat setiap pesan log berawalan "[ALERT]" ke Pulse (tipe `club61_alert`) supaya tampil di kartu Pembayaran Club 61.
 * Selama ini peringatan itu (mis. "Notifikasi DOKU 'SUCCESS' DITOLAK ...") hanya tertulis di laravel.log / doku.log.
 *
 * Kode pembayaran tidak disentuh: cukup mendengarkan event log. Satu peringatan yang ditulis ke dua channel sekaligus
 * (DokuClient::alert → log utama + doku.log) hanya dihitung sekali. Gagal mencatat tidak boleh mengganggu proses asal.
 */
final class LogAlertRecorder
{
    public const TYPE = 'club61_alert';

    /** Jendela penggabungan pesan kembar (detik). */
    private const DEDUPE_SECONDS = 5;

    /** @var array<string, int> md5 pesan => waktu terakhir dicatat */
    private static array $recent = [];

    public static function handle(MessageLogged $event): void
    {
        $message = $event->message;
        if (! is_string($message) || ! str_starts_with($message, '[ALERT]')) {
            return;
        }

        try {
            $key = Str::limit(preg_replace('/\s+/', ' ', trim(Str::after($message, '[ALERT]'))), 190, '…');
            if ($key === '') {
                return;
            }

            $hash = md5($key);
            $now = time();
            if (isset(self::$recent[$hash]) && $now - self::$recent[$hash] < self::DEDUPE_SECONDS) {
                return;
            }
            self::$recent[$hash] = $now;
            if (count(self::$recent) > 200) {
                self::$recent = array_filter(self::$recent, fn (int $at) => $now - $at < self::DEDUPE_SECONDS);
            }

            Pulse::record(self::TYPE, $key)->count();
        } catch (\Throwable) {
            // Monitoring tidak boleh menggagalkan proses yang sedang menulis log.
        }
    }

    /** Untuk test. */
    public static function flushDedupe(): void
    {
        self::$recent = [];
    }
}
