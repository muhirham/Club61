<?php

namespace App\Services\Padel;

use App\Models\Padel\PadelCourt;
use App\Models\Padel\PadelHoliday;
use App\Models\Padel\PadelPeakHourRule;
use App\Models\Padel\PadelPriceRule;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * SATU-SATUNYA penentu harga lapangan padel & jam peak (prime) vs reguler. Dipakai grid POS Walk-In, Booking System,
 * jadwal & hold booking customer, dan hitungan selisih reschedule — supaya harga yang tampil, yang di-hold, dan yang
 * ditagih selalu sama. Dulu aturan "akhir pekan ATAU jam >= 17" ditulis ulang di 7 tempat.
 *
 * Satu slot = satu jam; slot ikut peak kalau JAM MULAI-nya berada di salah satu rentang peak hari itu.
 * Tanggal merah memakai rentang hari Minggu.
 *
 * Harga satu jam (Modul 32): Harga Khusus per Periode yang cocok (PadelPriceRule) → kalau tidak ada, Harga Dasar
 * (tarif reguler / prime lapangan menurut jam peak). isPeak() tetap murni jam peak Master Data (dipakai aturan akses
 * membership); label Reguler / Prime di layar harga memakai rateFor().
 */
class PeakHourService
{
    public const CACHE_KEY = 'padel_peak_hour_schedule';

    public const PRICE_RULES_CACHE_KEY = 'padel_price_rules';

    public const HOLIDAY_DAY = 0; // Minggu

    /** @var array{rules: array<int, array<int, array{0: int, 1: int}>>, holidays: array<string, string>}|null */
    private ?array $schedule = null;

    /** @var array<int, array{name: string, courts: array<int, string>|null, from: string, until: string|null, days: array<int, int>, holidays: bool, start: int, end: int, price: float, prime: bool}>|null */
    private ?array $priceRules = null;

    public function isPeak(Carbon $slotStart): bool
    {
        $schedule = $this->schedule();
        $day = isset($schedule['holidays'][$slotStart->format('Y-m-d')]) ? self::HOLIDAY_DAY : $slotStart->dayOfWeek;
        $hour = $slotStart->hour;

        foreach ($schedule['rules'][$day] ?? [] as [$start, $end]) {
            if ($hour >= $start && $hour < $end) {
                return true;
            }
        }

        return false;
    }

    public function hourlyRate(PadelCourt $court, Carbon $slotStart): float
    {
        return $this->rateFor($court, $slotStart)['price'];
    }

    /**
     * Harga satu jam lapangan menurut TANGGAL & JAM MAIN: Harga Khusus aktif yang cocok (prioritas terkecil), kalau
     * tidak ada → Harga Dasar (tarif prime di jam peak, selain itu tarif reguler). rule = nama Harga Khusus / null.
     *
     * @return array{price: float, prime: bool, rule: string|null}
     */
    public function rateFor(PadelCourt $court, Carbon $slotStart): array
    {
        $rule = $this->matchingPriceRule($court, $slotStart);
        if ($rule !== null) {
            return ['price' => $rule['price'], 'prime' => $rule['prime'], 'rule' => $rule['name']];
        }

        $peak = $this->isPeak($slotStart);

        return ['price' => $peak ? (float) $court->hourly_rate_prime : (float) $court->hourly_rate_regular, 'prime' => $peak, 'rule' => null];
    }

    /**
     * Tarif lapangan untuk rentang [start, end) per jam (rentang yang melewati batas reguler/peak dihitung per jam).
     *
     * @return array{fee: float, has_peak: bool}
     */
    public function courtFee(PadelCourt $court, Carbon $start, Carbon $end): array
    {
        $fee = 0.0;
        $hasPeak = false;
        for ($cursor = $start->copy(); $cursor->lt($end); $cursor->addHour()) {
            $rate = $this->rateFor($court, $cursor);
            $hasPeak = $hasPeak || $rate['prime'];
            $fee += $rate['price'];
        }

        return ['fee' => $fee, 'has_peak' => $hasPeak];
    }

    /**
     * Harga Khusus yang berlaku untuk lapangan & jam ini. Aturan sudah urut prioritas, jadi yang pertama cocok menang.
     * Tanggal merah: aturan yang mencentang "Tanggal Merah" dulu; kalau tidak ada, diperlakukan sebagai hari Minggu.
     */
    private function matchingPriceRule(PadelCourt $court, Carbon $slotStart): ?array
    {
        $rules = $this->priceRules();
        if ($rules === []) {
            return null;
        }

        $date = $slotStart->format('Y-m-d');
        $hour = $slotStart->hour;
        $courtId = (string) $court->id;
        $candidates = array_values(array_filter($rules, fn (array $r) => $date >= $r['from']
            && ($r['until'] === null || $date <= $r['until'])
            && $hour >= $r['start'] && $hour < $r['end']
            && ($r['courts'] === null || in_array($courtId, $r['courts'], true))));
        if ($candidates === []) {
            return null;
        }

        $day = $slotStart->dayOfWeek;
        if (isset($this->schedule()['holidays'][$date])) {
            foreach ($candidates as $rule) {
                if ($rule['holidays']) {
                    return $rule;
                }
            }
            $day = self::HOLIDAY_DAY;
        }

        foreach ($candidates as $rule) {
            if (in_array($day, $rule['days'], true)) {
                return $rule;
            }
        }

        return null;
    }

    public function holidayName(Carbon $date): ?string
    {
        return $this->schedule()['holidays'][$date->format('Y-m-d')] ?? null;
    }

    /** @return array<int, array<int, array{0: int, 1: int}>> rentang [jam mulai, jam selesai) per hari */
    public function rules(): array
    {
        return $this->schedule()['rules'];
    }

    /** Wajib dipanggil setiap kali aturan jam peak / tanggal merah / Harga Khusus diubah. */
    public function flush(): void
    {
        $this->schedule = null;
        $this->priceRules = null;
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::PRICE_RULES_CACHE_KEY);
    }

    /** Harga Khusus aktif yang belum lewat, urut prioritas (kecil dulu). */
    private function priceRules(): array
    {
        if ($this->priceRules !== null) {
            return $this->priceRules;
        }

        try {
            return $this->priceRules = Cache::rememberForever(self::PRICE_RULES_CACHE_KEY, fn () => PadelPriceRule::query()
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()->subDay()->toDateString()))
                ->orderBy('priority')->orderByDesc('valid_from')->orderBy('id')
                ->get()
                ->map(fn (PadelPriceRule $r) => [
                    'name' => (string) $r->name,
                    'courts' => is_array($r->court_ids) && $r->court_ids !== [] ? array_map('strval', $r->court_ids) : null,
                    'from' => $r->valid_from->format('Y-m-d'),
                    'until' => $r->valid_until?->format('Y-m-d'),
                    'days' => array_map('intval', (array) $r->days),
                    'holidays' => (bool) $r->include_holidays,
                    'start' => (int) $r->start_hour,
                    'end' => (int) $r->end_hour,
                    'price' => (float) $r->price_per_hour,
                    'prime' => (bool) $r->is_prime,
                ])
                ->all());
        } catch (\Illuminate\Database\QueryException $e) {
            // Tabel belum ada (deploy belum `php artisan migrate`): Harga Dasar saja — harga sama seperti sebelum Modul 32.
            // Sengaja TIDAK di-cache.
            report($e);

            return $this->priceRules = [];
        }
    }

    private function schedule(): array
    {
        if ($this->schedule !== null) {
            return $this->schedule;
        }

        try {
            return $this->schedule = $this->loadSchedule();
        } catch (\Illuminate\Database\QueryException $e) {
            // Tabel belum ada (deploy lupa `php artisan migrate`): pakai aturan lama supaya halaman booking & kasir
            // tetap jalan dengan harga yang sama seperti sebelumnya. Sengaja TIDAK di-cache.
            report($e);

            return [
                'rules' => [0 => [[0, 24]], 1 => [[17, 24]], 2 => [[17, 24]], 3 => [[17, 24]], 4 => [[17, 24]], 5 => [[17, 24]], 6 => [[0, 24]]],
                'holidays' => [],
            ];
        }
    }

    private function loadSchedule(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $rules = [];
            foreach (PadelPeakHourRule::orderBy('day_of_week')->orderBy('start_hour')->get() as $rule) {
                $rules[$rule->day_of_week][] = [$rule->start_hour, $rule->end_hour];
            }

            return [
                'rules' => $rules,
                // Hanya tanggal merah yang relevan (mulai kemarin) — daftar lama tidak perlu ikut dimuat.
                'holidays' => PadelHoliday::where('date', '>=', now()->subDay()->toDateString())
                    ->orderBy('date')->get()
                    ->mapWithKeys(fn (PadelHoliday $h) => [$h->date->format('Y-m-d') => $h->name])
                    ->all(),
            ];
        });
    }
}
