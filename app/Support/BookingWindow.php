<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Batas tanggal main yang boleh dibooking (config/booking_window.php → BOOKING_OPEN_UNTIL, BOOKING_CLOSED_DATES,
 * BOOKING_CLOSED_REASON). Dicek di server pada setiap jalur yang membuat / memindah booking; tampilan jadwal customer &
 * POS menampilkan slotnya sebagai Tutup beserta pesannya.
 */
final class BookingWindow
{
    /** Tanggal main terakhir yang boleh dibooking (Y-m-d), atau null bila tanpa batas / nilainya tidak valid. */
    public static function openUntil(): ?string
    {
        return self::validDate(trim((string) config('booking_window.open_until', '')));
    }

    /**
     * Isi BOOKING_CLOSED_DATES, dipisah koma: "2026-11-26" = tutup sehari penuh, "2026-11-04 06:00-13:00" = tutup
     * jam itu saja (jam lain tetap bisa dibooking). Entri yang formatnya salah diabaikan.
     *
     * @return list<array{date: string, start: ?string, end: ?string}>
     */
    public static function closures(): array
    {
        $closures = [];
        foreach (explode(',', (string) config('booking_window.closed_dates', '')) as $entry) {
            if (! preg_match('/^(\S+)(?:\s+([01]\d|2[0-3]):([0-5]\d)\s*-\s*(?:([01]\d|2[0-3]):([0-5]\d)|(24):(00)))?$/', trim($entry), $m)
                || ! ($date = self::validDate($m[1]))) {
                continue;
            }
            if (! isset($m[2]) || $m[2] === '') {
                $closures[] = ['date' => $date, 'start' => null, 'end' => null];

                continue;
            }
            $start = "{$m[2]}:{$m[3]}";
            $end = ($m[4] ?? '') !== '' ? "{$m[4]}:{$m[5]}" : '24:00';
            if ($start < $end) {
                $closures[] = ['date' => $date, 'start' => $start, 'end' => $end];
            }
        }

        return $closures;
    }

    /** @return list<string> tanggal tutup sehari penuh (Y-m-d) */
    public static function closedDates(): array
    {
        $dates = array_map(fn ($c) => $c['date'], array_filter(self::closures(), fn ($c) => $c['start'] === null));

        return array_values(array_unique($dates));
    }

    /** @return list<array{start: string, end: string}> jam tutup (bukan sehari penuh) pada tanggal main ini */
    public static function closedHours(CarbonInterface|string $date): array
    {
        $date = self::dateString($date);
        $hours = array_filter(self::closures(), fn ($c) => $c['date'] === $date && $c['start'] !== null);

        return array_values(array_map(fn ($c) => ['start' => $c['start'], 'end' => $c['end']], $hours));
    }

    /**
     * Alasan jam main ini tidak bisa dibooking karena bentrok jam tutup, atau null bila boleh. $start / $end: "H:i",
     * "H:i:s", tanggal-jam lengkap, atau Carbon. Tanggal tutup sehari penuh dicek terpisah lewat blockedReason().
     */
    public static function closedHoursReason(CarbonInterface|string $date, CarbonInterface|string $start, CarbonInterface|string $end): ?string
    {
        $from = self::hourMinute($start);
        $until = self::hourMinute($end);
        if ($until <= $from) {
            $until = '24:00'; // slot terakhir berakhir tengah malam ("00:00")
        }

        foreach (self::closedHours($date) as $closed) {
            if ($closed['start'] < $until && $closed['end'] > $from) {
                return self::hoursMessage('booking.hours_closed', $date);
            }
        }

        return null;
    }

    public static function assertHoursOpen(CarbonInterface|string $date, CarbonInterface|string $start, CarbonInterface|string $end): void
    {
        if ($reason = self::closedHoursReason($date, $start, $end)) {
            throw new HttpException(422, $reason);
        }
    }

    /** Pesan banner jadwal: tanggal tidak bisa dibooking, atau jam tutup di tanggal ini (jam lain tetap bisa). */
    public static function dayNotice(CarbonInterface|string $date): ?string
    {
        return self::blockedReason($date) ?? (self::closedHours($date) ? self::hoursMessage('booking.hours_closed_notice', $date) : null);
    }

    public static function closedReason(): ?string
    {
        $reason = trim((string) config('booking_window.closed_reason', ''));

        return $reason === '' ? null : mb_substr($reason, 0, 60);
    }

    public static function isClosed(CarbonInterface|string $date): bool
    {
        return in_array(self::dateString($date), self::closedDates(), true);
    }

    /**
     * Alasan tanggal main ini tidak bisa dibooking (pesan untuk customer / staf), atau null bila boleh.
     * $closedOnly = Jadwal Tetap (dibayar kuota jam, bukan harga lapangan): hanya tanggal tutup yang berlaku.
     */
    public static function blockedReason(CarbonInterface|string $date, bool $closedOnly = false): ?string
    {
        $date = self::dateString($date);

        if (in_array($date, self::closedDates(), true)) {
            $reason = self::closedReason();

            return SiteLocale::text('booking.date_closed', ['date' => self::label($date), 'reason' => $reason ? " ({$reason})" : '']);
        }

        $until = self::openUntil();
        if (! $closedOnly && $until !== null && $date > $until) {
            return SiteLocale::text('booking.date_not_open', ['date' => self::label($until)]);
        }

        return null;
    }

    public static function assertBookable(CarbonInterface|string $date, bool $closedOnly = false): void
    {
        if ($reason = self::blockedReason($date, $closedOnly)) {
            throw new HttpException(422, $reason);
        }
    }

    /** Mis. "Kamis, 26 November 2026" (bahasa mengikuti pesan customer). */
    public static function label(string $date): string
    {
        return Carbon::parse($date)->locale(SiteLocale::forMessages())->translatedFormat('l, j F Y');
    }

    private static function hoursMessage(string $key, CarbonInterface|string $date): string
    {
        $date = self::dateString($date);
        $hours = implode(', ', array_map(fn ($h) => "{$h['start']}–{$h['end']}", self::closedHours($date)));
        $reason = self::closedReason();

        return SiteLocale::text($key, ['date' => self::label($date), 'hours' => $hours, 'reason' => $reason ? " ({$reason})" : '']);
    }

    private static function hourMinute(CarbonInterface|string $time): string
    {
        if ($time instanceof CarbonInterface) {
            return $time->format('H:i');
        }
        $time = trim($time);

        // "2026-11-04 06:00:00" / ISO → ambil jamnya; "06:00" / "06:00:00" → 5 karakter pertama.
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $time) ? Carbon::parse($time)->format('H:i') : substr($time, 0, 5);
    }

    private static function dateString(CarbonInterface|string $date): string
    {
        return $date instanceof CarbonInterface ? $date->toDateString() : substr(trim($date), 0, 10);
    }

    private static function validDate(string $value): ?string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }
}
