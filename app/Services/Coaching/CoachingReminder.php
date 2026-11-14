<?php

namespace App\Services\Coaching;

use App\Models\Coaching\Coach;
use App\Models\Padel\PadelBooking;

/** Pesan WhatsApp pengingat jadwal ke coach (resepsionis yang menekan tombolnya — tidak ada pengiriman otomatis). */
class CoachingReminder
{
    /** Nomor HP → format wa.me (62…), null kalau kosong / tidak masuk akal. */
    public static function waDigits(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        $digits = match (true) {
            str_starts_with($digits, '0') => '62'.substr($digits, 1),
            str_starts_with($digits, '8') => '62'.$digits,
            default => $digits,
        };

        return strlen($digits) >= 10 && strlen($digits) <= 15 ? $digits : null;
    }

    public static function message(Coach $coach, PadelBooking $booking): string
    {
        $coaching = $booking->coachingBooking;
        // Coach utama disebut lebih dulu (setelah Ganti Coach, coach pengganti tercatat paling akhir).
        $coachNames = $coaching?->sortedCoaches()->pluck('name')->implode(' & ') ?: $coach->name;
        $customer = $booking->user;
        $lines = [
            "Halo Coach {$coach->name}, pengingat jadwal coaching di Club 61:",
            '',
            'Tanggal: '.$booking->start_time->translatedFormat('l, d F Y'),
            'Jam: '.$booking->start_time->format('H:i').' - '.$booking->end_time->format('H:i').' WIB',
            'Lapangan: '.($booking->court?->name ?? '-'),
            'Coach: '.$coachNames,
            'Atas nama: '.($customer?->name ?? '-'),
        ];
        if ($students = (array) ($coaching?->students ?? [])) {
            $lines[] = 'Murid: '.implode(', ', $students);
        }
        if ($coaching?->notes) {
            $lines[] = 'Catatan: '.$coaching->notes;
        }
        $lines[] = '';
        $lines[] = 'Terima kasih.';

        return implode("\n", $lines);
    }

    public static function url(Coach $coach, PadelBooking $booking): ?string
    {
        $digits = self::waDigits($coach->phone);

        return $digits ? 'https://wa.me/'.$digits.'?text='.rawurlencode(self::message($coach, $booking)) : null;
    }
}
