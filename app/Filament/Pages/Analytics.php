<?php

namespace App\Filament\Pages;

use App\Models\Finance\LedgerEntry;
use App\Models\Padel\PadelBooking;
use App\Models\Pos\Payment;
use App\Services\Finance\LedgerAnalytics;
use App\Services\Finance\LedgerReport;
use App\Services\Padel\CourtOccupancy;
use App\Services\Padel\PadelBookingService;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use UnitEnum;

/**
 * Modul 17 Fase 3 — Analytics & Keuangan membaca dari Buku Transaksi (ledger_entries). Dulu omzet dihitung dari
 * padel_bookings (F&B tidak terhitung sama sekali, refund PENDING ikut mengurangi, tanggal memakai created_at
 * booking) sehingga angkanya tidak pernah sama dengan Buku Transaksi.
 */
class Analytics extends Page
{
    use HasPageShield;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'Analytics & Keuangan';

    protected static string|UnitEnum|null $navigationGroup = 'Keuangan';

    protected static ?string $title = 'Laporan Uang Masuk & Analytics';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.analytics';

    /** Hanya diubah lewat setPreset() — nilai kiriman browser yang diubah manual ditolak Livewire. */
    /**
     * Lini layanan di kartu "Rincian Pendapatan" (kode = kategori Buku Transaksi). `soon` = modulnya belum ada,
     * ditandai "Menyusul" selama belum ada transaksi. Coaching = kategori COACHING (sewa lapangan coaching, Modul 24 Opsi B).
     */
    public const SERVICE_LINES = [
        'SEWA_LAPANGAN' => ['label' => 'Sewa Lapangan Padel (Court Rental)', 'sub' => 'Sewa slot jam lapangan padel', 'soon' => false],
        'ADDON_PADEL' => ['label' => 'Sewa Alat & Add-on Bola (Equipment)', 'sub' => 'Sewa raket, bola & perlengkapan padel', 'soon' => false],
        'FNB' => ['label' => 'Food & Beverage (F&B)', 'sub' => 'Penjualan kafe / bar dari POS F&B', 'soon' => false],
        'MERCH' => ['label' => 'Merchandise', 'sub' => 'Penjualan merchandise di Kasir Pro Shop', 'soon' => false],
        'WELLNESS' => ['label' => 'Wellness', 'sub' => 'Akses wellness di luar paket membership', 'soon' => true],
        'COACHING' => ['label' => 'Pelatih & Coaching Session', 'sub' => 'Sewa lapangan harga khusus untuk sesi coaching academy', 'soon' => false],
    ];

    /** Sumber uang yang dibayar online lewat payment gateway (DOKU / Midtrans); sumber lain = dibayar di kasir. */
    public const ONLINE_SOURCES = ['ONLINE_PADEL', 'RESCHEDULE_DELTA_ONLINE', 'ONLINE_MEMBERSHIP'];

    /** Kasir per loket: frontdesk padel (walk-in, pelunasan selisih, jual membership) & kasir F&B. */
    public const COUNTER_SOURCES = [
        'padel' => ['POS_WALKIN_PADEL', 'RESCHEDULE_DELTA_POS', 'POS_MEMBERSHIP'],
        'fnb' => ['POS_FNB'],
    ];

    public const GATEWAY_LABELS = ['DOKU' => 'DOKU', 'MIDTRANS' => 'Midtrans'];

    #[Locked]
    public string $preset = LedgerReport::DEFAULT_PRESET;

    public ?string $dari = null;

    public ?string $sampai = null;

    public function setPreset(string $preset): void
    {
        $this->preset = array_key_exists($preset, LedgerReport::PRESETS) ? $preset : LedgerReport::DEFAULT_PRESET;
    }

    /**
     * Tanggal kustom dari input browser: selain Y-m-d yang valid diabaikan (teks bebas dulu → Carbon::parse error 500).
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function customDates(): array
    {
        $date = fn (?string $v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)) ? $v : null;

        return [$date($this->dari), $date($this->sampai)];
    }

    /** @return array{0: ?string, 1: ?string} */
    public function range(): array
    {
        return LedgerReport::resolveRange($this->preset, ...$this->customDates());
    }

    /** Tautan ke Buku Transaksi dengan periode yang sama (+ filter rincian yang diklik). */
    public function bukuUrl(array $filters = []): string
    {
        [$dari, $sampai] = $this->customDates();

        return BukuTransaksi::getUrl(array_filter([
            'periode' => $this->preset,
            'dari' => $this->preset === 'kustom' ? $dari : null,
            'sampai' => $this->preset === 'kustom' ? $sampai : null,
        ] + $filters, fn ($v) => filled($v)));
    }

    protected function getViewData(): array
    {
        // Sinkronkan status tiket (PAID lewat jadwal → EXPIRED/COMPLETED) sebelum menghitung okupansi.
        app(PadelBookingService::class)->syncExpiredAndCompletedBookings();

        [$from, $until] = $this->range();
        $base = LedgerReport::applyDateRange(LedgerEntry::query(), $from, $until);

        $latest = LedgerReport::orderNewestFirst(LedgerReport::grouped(clone $base))->limit(10)->get();
        $byCategory = collect(LedgerAnalytics::breakdown($base, 'category'))->keyBy('key');
        $summary = LedgerReport::summary(clone $base, $from, $until);
        $membership = (float) ($byCategory['MEMBERSHIP']['money_net'] ?? 0);

        return [
            'periodLabel' => BukuTransaksi::periodLabel($this->preset, ...$this->customDates()),
            'summary' => $summary,
            'channels' => $this->channels(LedgerAnalytics::breakdown($base, 'source')),
            'gateways' => $this->gateways($base),
            'byMethod' => LedgerAnalytics::breakdown($base, 'method'),
            'serviceLines' => $this->serviceLines($byCategory->all()),
            'membership' => ['sales' => $membership, 'others' => $summary['money_net'] - $membership],
            'occupancy' => CourtOccupancy::calculate($from, $until),
            'memberUsage' => $this->memberUsage($from, $until),
            'coachingRecap' => $this->coachingRecap($from, $until),
            'latest' => $latest,
            'canSeeRefundQueue' => AntrianRefund::canAccess(),
        ];
    }

    /**
     * Uang masuk bersih per kanal: POS Frontdesk Padel, POS F&B, kasir lain (impor / lainnya), dan online.
     *
     * @param  list<array<string, mixed>>  $bySource
     * @return array<'padel'|'fnb'|'other'|'online', array{money_net: float, transactions: int}>
     */
    private function channels(array $bySource): array
    {
        $empty = ['money_net' => 0.0, 'transactions' => 0];
        $channels = ['padel' => $empty, 'fnb' => $empty, 'other' => $empty, 'online' => $empty];
        foreach ($bySource as $row) {
            $key = match (true) {
                in_array($row['key'], self::ONLINE_SOURCES, true) => 'online',
                in_array($row['key'], self::COUNTER_SOURCES['padel'], true) => 'padel',
                in_array($row['key'], self::COUNTER_SOURCES['fnb'], true) => 'fnb',
                default => 'other',
            };
            $channels[$key]['money_net'] += $row['money_net'];
            $channels[$key]['transactions'] += $row['transactions'];
        }

        return $channels;
    }

    /**
     * Uang masuk online per payment gateway (dari pembayaran asal baris buku), supaya transaksi lama Midtrans & DOKU
     * terlihat terpisah. Baris tanpa pembayaran yang dikenali masuk "Lainnya".
     *
     * @return list<array{label: string, money_net: float, transactions: int}>
     */
    private function gateways(Builder $base): array
    {
        $online = (clone $base)->whereIn('source', self::ONLINE_SOURCES);
        $sum = fn (Builder $q) => (clone $q)->toBase()->selectRaw("COALESCE(SUM(total_amount), 0) as money_net,
            COUNT(DISTINCT CASE WHEN entry_type <> 'REFUND' THEN payment_id END) as transactions")->first();

        $rows = [];
        $known = ['money_net' => 0.0, 'transactions' => 0];
        $gateways = Payment::query()->whereIn('id', (clone $online)->select('payment_id'))->distinct()->pluck('payment_gateway');
        foreach ($gateways as $gateway) {
            $r = $sum((clone $online)->whereIn('payment_id', Payment::query()->where('payment_gateway', $gateway)->select('id')));
            $rows[] = [
                'label' => self::GATEWAY_LABELS[$gateway] ?? ucfirst(strtolower((string) $gateway)),
                'money_net' => (float) $r->money_net,
                'transactions' => (int) $r->transactions,
            ];
            $known['money_net'] += (float) $r->money_net;
            $known['transactions'] += (int) $r->transactions;
        }

        $all = $sum($online);
        if (abs((float) $all->money_net - $known['money_net']) >= 0.005 || (int) $all->transactions > $known['transactions']) {
            $rows[] = [
                'label' => 'Lainnya',
                'money_net' => (float) $all->money_net - $known['money_net'],
                'transactions' => max(0, (int) $all->transactions - $known['transactions']),
            ];
        }

        return collect($rows)->sortByDesc('money_net')->values()->all();
    }

    /**
     * Baris "Rincian Pendapatan per Lini Layanan": lini tetap + "Lainnya" (gym, merchandise, salon, dll.) bila ada.
     * Membership punya kartu sendiri. Jumlah semua baris + membership = Pendapatan Bersih.
     *
     * @param  array<string, array<string, mixed>>  $byCategory
     * @return list<array{code: string, label: string, sub: string, money_net: float, transactions: int, soon: bool, filter: list<string>}>
     */
    private function serviceLines(array $byCategory): array
    {
        $lines = [];
        foreach (self::SERVICE_LINES as $code => $line) {
            $row = $byCategory[$code] ?? null;
            $lines[] = [
                'code' => $code,
                'label' => $line['label'],
                'sub' => $line['sub'],
                'money_net' => (float) ($row['money_net'] ?? 0),
                'transactions' => (int) ($row['transactions'] ?? 0),
                'soon' => $line['soon'] && $row === null,
                'filter' => array_key_exists($code, LedgerEntry::CATEGORIES) ? [$code] : [],
            ];
        }

        $others = collect($byCategory)->except([...array_keys(self::SERVICE_LINES), 'MEMBERSHIP']);
        if ($others->isNotEmpty()) {
            $lines[] = [
                'code' => 'LAINNYA',
                'label' => 'Lainnya ('.$others->pluck('label')->implode(', ').')',
                'sub' => 'Penjualan di luar lini utama',
                'money_net' => (float) $others->sum('money_net'),
                'transactions' => (int) $others->sum('transactions'),
                'soon' => false,
                'filter' => $others->keys()->all(),
            ];
        }

        return $lines;
    }

    /**
     * Rekap coaching per academy & per coach (Modul 24 Opsi B Tahap 2) dari slot lapangan coaching yang terpakai di
     * periode (tanggal main, status sama dengan okupansi). Nilai sewa = tarif setoran slot (court_fee) — informasi
     * pemakaian, BUKAN uang masuk (uang masuk tetap baris "Pelatih & Coaching Session" dari Buku Transaksi).
     *
     * @return array{academies: list<array{name: string, sessions: int, hours: float, value: float}>, coaches: list<array{name: string, academy: ?string, head: int, extra: int, hours: float}>}
     */
    private function coachingRecap(?string $from, ?string $until): array
    {
        $bookings = PadelBooking::query()->whereNotNull('coaching_booking_id')
            ->whereIn('status', CourtOccupancy::OCCUPYING_STATUSES)
            ->when($from, fn ($q) => $q->whereDate('booking_date', '>=', $from))
            ->when($until, fn ($q) => $q->whereDate('booking_date', '<=', $until))
            ->with('coachingBooking.coaches.academy:id,name')
            ->get(['id', 'coaching_booking_id', 'start_time', 'end_time', 'court_fee']);

        $academies = [];
        $coaches = [];
        foreach ($bookings as $booking) {
            $coaching = $booking->coachingBooking;
            if (! $coaching) {
                continue;
            }
            $hours = round(abs($booking->end_time->diffInMinutes($booking->start_time)) / 60, 2);

            $academy = $coaching->academy_name ?: 'Coach independen';
            $academies[$academy] ??= ['name' => $academy, 'sessions' => 0, 'hours' => 0.0, 'value' => 0.0];
            $academies[$academy]['sessions']++;
            $academies[$academy]['hours'] += $hours;
            $academies[$academy]['value'] += (float) $booking->court_fee;

            foreach ($coaching->coaches as $coach) {
                $coaches[$coach->id] ??= ['name' => $coach->name, 'academy' => $coach->academy?->name, 'head' => 0, 'extra' => 0, 'hours' => 0.0];
                $coaches[$coach->id][$coach->pivot->role === 'HEAD' ? 'head' : 'extra']++;
                $coaches[$coach->id]['hours'] += $hours;
            }
        }

        return [
            'academies' => collect($academies)->sortByDesc('hours')->values()->all(),
            'coaches' => collect($coaches)->sortByDesc('hours')->values()->all(),
        ];
    }

    /** Pemakaian kuota membership di lapangan (informasi utilisasi — bukan uang masuk baru). */
    private function memberUsage(?string $from, ?string $until): array
    {
        $row = PadelBooking::query()->whereIn('status', CourtOccupancy::OCCUPYING_STATUSES)
            ->when($from, fn ($q) => $q->whereDate('booking_date', '>=', $from))
            ->when($until, fn ($q) => $q->whereDate('booking_date', '<=', $until))
            ->toBase()
            ->selectRaw('COALESCE(SUM(member_discount_court), 0) as value, COALESCE(SUM(member_hours_consumed), 0) as hours,
                COUNT(CASE WHEN membership_balance_id IS NOT NULL THEN 1 END) as bookings')
            ->first();

        return ['value' => (float) $row->value, 'hours' => (float) $row->hours, 'bookings' => (int) $row->bookings];
    }

    public static function rupiah(float|int|null $value): string
    {
        return BukuTransaksi::rupiah($value);
    }
}
