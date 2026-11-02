<?php

namespace App\Services\Padel;

use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Tampilan Mingguan & Bulanan di Monitoring Lapangan (Modul 28 Batch 3): siapa booking jam berapa dalam seminggu /
 * sebulan, plus Rekap Pemain. Hanya baca — tidak mengubah booking. Status yang dihitung sama dengan tampilan harian
 * (menunggu bayar, lunas, check-in, selesai).
 */
class ScheduleOverview
{
    public const STATUSES = ['LOCKED', 'PENDING_PAYMENT', 'PENDING', 'PAID', 'CHECKED_IN', 'COMPLETED'];

    public const PENDING_STATUSES = ['LOCKED', 'PENDING_PAYMENT', 'PENDING'];

    /** Jenis booking untuk warna & filter (urutan = prioritas saat satu booking cocok lebih dari satu). */
    public const TYPES = [
        'COACHING' => 'Coaching',
        'RECURRING' => 'Jadwal Tetap',
        'CORPORATE' => 'Voucher Corporate',
        'MEMBER' => 'Member',
        'WALK_IN' => 'Walk-In',
        'ONLINE' => 'Online',
    ];

    public static function type(PadelBooking $booking): string
    {
        return match (true) {
            (bool) $booking->coaching_booking_id => 'COACHING',
            (bool) $booking->recurring_schedule_id => 'RECURRING',
            (bool) $booking->sponsor_member_voucher_id || (bool) $booking->sponsor_organization_id => 'CORPORATE',
            (bool) $booking->membership_balance_id => 'MEMBER',
            $booking->order?->order_type === 'WALK_IN' => 'WALK_IN',
            default => 'ONLINE',
        };
    }

    /**
     * @param  array{court?: string, type?: string, search?: string}  $filters  court = all / id lapangan
     * @return Collection<int, PadelBooking> booking aktif di rentang tanggal, sudah tersaring
     */
    public function bookings(Carbon $from, Carbon $until, array $filters = []): Collection
    {
        $court = $filters['court'] ?? 'all';
        $type = $filters['type'] ?? 'all';
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));

        return PadelBooking::with(['court:id,name,type', 'user:id,name,phone', 'order:id,order_type', 'coachingBooking:id,billing'])
            ->whereDate('booking_date', '>=', $from->toDateString())
            ->whereDate('booking_date', '<=', $until->toDateString())
            ->whereIn('status', self::STATUSES)
            ->whereHas('court', fn ($q) => $q->where('is_active', true)->when($court !== 'all', fn ($q) => $q->whereKey($court)))
            ->orderBy('start_time')
            ->get()
            ->filter(fn (PadelBooking $b) => $type === 'all' || self::type($b) === $type)
            ->filter(fn (PadelBooking $b) => $search === '' || str_contains(mb_strtolower(implode(' ', [
                $b->user?->name, $b->user?->phone, $b->booking_code,
            ])), $search))
            ->values();
    }

    /** Lapangan aktif sesuai filter (untuk jam buka & kapasitas). */
    public function courts(string $court = 'all'): Collection
    {
        return PadelCourt::where('is_active', true)
            ->when($court !== 'all', fn ($q) => $q->whereKey($court))
            ->ordered()->get();
    }

    /** @return array{0: int, 1: int} jam buka paling awal & tutup paling akhir (sama dengan grid harian) */
    public static function hourRange(Collection $courts): array
    {
        if ($courts->isEmpty()) {
            return [6, 23];
        }
        $open = (int) $courts->min(fn ($c) => (int) substr($c->open_time ?: '06:00', 0, 2));
        $close = (int) $courts->max(function ($c) {
            $val = $c->close_time ?: '23:00';

            return in_array($val, ['00:00', '24:00'], true) ? 24 : (int) substr($val, 0, 2);
        });

        return [max(0, min($open, 23)), max($open + 1, min($close, 24))];
    }

    /**
     * Mingguan (Senin–Minggu): baris jam × kolom hari, tiap sel berisi booking yang memakai jam itu (booking beberapa
     * jam tampil di setiap jamnya; jam lanjutan ditandai).
     *
     * @return array{days: list<array{date: string, label: string, is_today: bool}>, hours: list<int>, cells: array<string, array<int, list<array>>>, bookings: Collection}
     */
    public function week(Carbon $anyDay, array $filters = []): array
    {
        $from = $anyDay->copy()->startOfWeek(Carbon::MONDAY);
        $until = $from->copy()->addDays(6);
        $bookings = $this->bookings($from, $until, $filters);
        [$open, $close] = self::hourRange($this->courts($filters['court'] ?? 'all'));

        $cells = [];
        foreach ($bookings as $b) {
            $date = $b->booking_date->toDateString();
            for ($cursor = $b->start_time->copy(); $cursor->lt($b->end_time); $cursor->addHour()) {
                $cells[$date][$cursor->hour][] = $this->chip($b, $cursor->ne($b->start_time));
            }
        }

        return [
            'days' => collect(range(0, 6))->map(fn (int $i) => [
                'date' => ($d = $from->copy()->addDays($i))->toDateString(),
                'label' => $d->translatedFormat('D d M'),
                'is_today' => $d->isToday(),
            ])->all(),
            'hours' => range($open, $close - 1),
            'cells' => $cells,
            'bookings' => $bookings,
        ];
    }

    /**
     * Bulanan: kalender Senin–Minggu yang mencakup bulan itu; tiap tanggal berisi jumlah booking & okupansi.
     *
     * @return array{weeks: list<list<array{date: string, day: int, in_month: bool, is_today: bool, count: int, hours: float, rate: int}>>, bookings: Collection}
     */
    public function month(Carbon $anyDay, array $filters = []): array
    {
        $first = $anyDay->copy()->startOfMonth();
        $last = $anyDay->copy()->endOfMonth();
        $bookings = $this->bookings($first, $last, $filters);
        $capacity = $this->courts($filters['court'] ?? 'all')->sum(fn (PadelCourt $c) => CourtOccupancy::openHours($c));
        $byDate = $bookings->groupBy(fn (PadelBooking $b) => $b->booking_date->toDateString());

        $weeks = [];
        $index = 0;
        for ($day = $first->copy()->startOfWeek(Carbon::MONDAY); $day->lte($last->copy()->endOfWeek(Carbon::SUNDAY)); $day->addDay(), $index++) {
            $dayBookings = $byDate->get($day->toDateString(), collect());
            $hours = round((float) $dayBookings->sum(fn (PadelBooking $b) => $b->start_time->diffInMinutes($b->end_time) / 60), 1);
            $weeks[intdiv($index, 7)][] = [
                'date' => $day->toDateString(),
                'day' => $day->day,
                'in_month' => $day->month === $first->month,
                'is_today' => $day->isToday(),
                'count' => $dayBookings->count(),
                'hours' => $hours,
                'rate' => $capacity > 0 ? (int) min(100, round($hours / $capacity * 100)) : 0,
            ];
        }

        return ['weeks' => $weeks, 'bookings' => $bookings];
    }

    /** Daftar booking satu tanggal (klik tanggal di kalender bulanan). */
    public function dayList(Carbon $date, array $filters = []): array
    {
        return $this->bookings($date, $date, $filters)->map(fn (PadelBooking $b) => $this->chip($b) + [
            'time' => $b->start_time->format('H:i').'–'.$b->end_time->format('H:i'),
            'status' => $b->status,
        ])->all();
    }

    /**
     * Rekap Pemain: siapa saja yang main dalam periode, berapa kali & berapa jam. Urut jam terbanyak.
     *
     * @return list<array{name: string, phone: ?string, types: string, count: int, hours: float, last: string}>
     */
    public function players(Collection $bookings): array
    {
        return $bookings->groupBy(fn (PadelBooking $b) => $b->user_id ?? 'guest')
            ->map(function (Collection $group) {
                $first = $group->first();

                return [
                    'name' => $first->user?->name ?? 'Guest',
                    'phone' => $first->user?->phone,
                    'types' => $group->map(fn (PadelBooking $b) => self::TYPES[self::type($b)])->unique()->implode(', '),
                    'count' => $group->count(),
                    'hours' => round((float) $group->sum(fn (PadelBooking $b) => $b->start_time->diffInMinutes($b->end_time) / 60), 1),
                    'last' => $group->max('start_time')->translatedFormat('D d M, H:i'),
                ];
            })
            ->sortByDesc('hours')
            ->values()
            ->all();
    }

    /** @return array{id: string, court: string, name: string, type: string, type_label: string, pending: bool, completed: bool, continued: bool} */
    private function chip(PadelBooking $b, bool $continued = false): array
    {
        $type = self::type($b);

        return [
            'id' => $b->id,
            'court' => trim((string) preg_replace('/^court\s+/i', '', (string) $b->court?->name)) ?: '-',
            'name' => $b->user?->name ?? 'Guest',
            'type' => $type,
            'type_label' => self::TYPES[$type],
            // Sesi tagihan berkala coaching (Modul 31) terkunci tetap — tidak digambar sebagai "menunggu bayar".
            'pending' => in_array($b->status, self::PENDING_STATUSES, true) && ! ($b->status === 'PENDING_PAYMENT' && $b->coachingBooking?->isTab()),
            'completed' => $b->status === 'COMPLETED',
            'continued' => $continued,
        ];
    }
}
