<?php

namespace App\Services\Membership;

use App\Models\Membership\UserMembership;
use App\Models\Membership\UserMembershipBalance;
use App\Models\Padel\PadelBooking;
use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Padel\PadelBookingService;
use App\Services\Padel\PeakHourService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Modul 34 — Tagihan Bulanan Perusahaan (docs/PRD_MODUL_34_TAGIHAN_BULANAN_MEMBERSHIP.md). Paket Organisasi dengan
 * fasilitas Padel bertanda "Tagihan Bulanan": booking PIC (Jadwal Tetap, booking online, dicatat resepsionis) langsung
 * terkonfirmasi Rp0 — sistem hanya merekap jam main per bulan. Harga per jam ditentukan & ditagihkan Finance di luar
 * sistem; uang yang ditransfer perusahaan dicatat dari Kelola Sponsor (MonthlyBillingPayment).
 *
 * Penandanya kartu yang dipakai booking (padel_bookings.membership_balance_id); pengaturan disimpan di
 * extra_benefits.monthly_billing fasilitas PADEL paket dan disalin ke kartu — tanpa kolom baru. Kartu tagihan bulanan
 * tidak punya kuota jam & diskon persen, sehingga booking hanya memakai kartu ini lewat jalur tagihan.
 */
class MonthlyBilling
{
    public const KEY = 'monthly_billing';

    /** Path JSON penanda kartu tagihan bulanan untuk query. */
    public const MARKER = 'extra_benefits->'.self::KEY.'->enabled';

    /** Metode / gateway pembayaran Rp0 booking yang dicatat pada tagihan bulanan. */
    public const METHOD = 'MONTHLY_BILLING';

    public const METHOD_LABEL = 'Tagihan Bulanan — ditagihkan oleh Club 61';

    /** Status booking yang masuk rekap jam main (belum main, sedang / sudah main, tidak hadir = tetap dihitung). */
    public const RECAP_STATUSES = ['PAID', 'CHECKED_IN', 'COMPLETED', 'EXPIRED'];

    public const STATUS_LABELS = [
        'PAID' => 'Belum Main',
        'CHECKED_IN' => 'Main',
        'COMPLETED' => 'Main',
        'EXPIRED' => 'Tidak Hadir',
    ];

    /** Pengaturan tagihan bulanan dari extra_benefits paket / kartu. null = bukan paket tagihan bulanan. */
    public static function settings(?array $extraBenefits): ?array
    {
        $raw = $extraBenefits[self::KEY] ?? null;

        return is_array($raw) && ! empty($raw['enabled']) ? ['enabled' => true] : null;
    }

    public static function forBalance(?UserMembershipBalance $balance): ?array
    {
        return $balance && $balance->facility === 'PADEL' ? self::settings($balance->extra_benefits) : null;
    }

    /** Kartu Padel tagihan bulanan milik membership ini (null = membership biasa). */
    public static function balanceOf(?UserMembership $membership): ?UserMembershipBalance
    {
        $balance = $membership?->balanceFor('PADEL');

        return self::forBalance($balance) ? $balance : null;
    }

    /** Label bulan, mis. "Desember 2026". */
    public static function monthLabel(CarbonInterface $month): string
    {
        return Carbon::parse($month->format('Y-m-01'))->locale('id')->translatedFormat('F Y');
    }

    /** Booking yang tercatat pada tagihan bulanan (status apa pun — dicek pemanggil). */
    public static function isTabBooking(PadelBooking $booking): bool
    {
        if (! $booking->membership_balance_id || $booking->coaching_booking_id) {
            return false;
        }
        $balance = $booking->relationLoaded('membershipBalance') ? $booking->membershipBalance
            : UserMembershipBalance::find($booking->membership_balance_id, ['id', 'facility', 'extra_benefits']);

        return self::forBalance($balance) !== null;
    }

    /** Booking tagihan bulanan — semua kartu, atau satu kartu. */
    public static function tabBookings(?string $balanceId = null): Builder
    {
        return PadelBooking::query()
            ->whereNull('coaching_booking_id')
            ->when($balanceId, fn (Builder $q) => $q->where('membership_balance_id', $balanceId))
            ->whereHas('membershipBalance', fn (Builder $b) => $b->whereNotNull(self::MARKER));
    }

    /**
     * Semua kartu tagihan bulanan (aktif maupun tidak), terbaru dulu. Tagihan Bulanan hanya untuk paket Organisasi, jadi
     * kartu dicari dari kartu milik perusahaan di Kelola Sponsor (tabel kecil, user_membership_id unik) — penanda JSON
     * hanya dicek pada baris itu, bukan memindai seluruh kartu membership.
     */
    public static function cards(): Builder
    {
        return UserMembershipBalance::query()
            ->whereIn('user_membership_id', \App\Models\Sponsor\SponsorOrganization::query()->select('user_membership_id'))
            ->where('facility', 'PADEL')->whereNotNull(self::MARKER)
            ->with(['membership.sponsorOrganization', 'membership.user:id,name'])->latest();
    }

    /** Akun yang boleh memakai tagihan kartu ini: PIC aktif untuk kartu perusahaan, pemilik kartu untuk perorangan. */
    public static function bookerId(UserMembership $membership): ?string
    {
        if ($membership->owner_type === 'ORGANIZATIONAL' && $membership->sponsorOrganization) {
            return $membership->sponsorOrganization->sponsor_admin_user_id;
        }

        return $membership->user_id;
    }

    /**
     * Kartu tagihan bulanan aktif yang boleh dipakai akun ini untuk booking (akun = PIC aktif perusahaan). Dipanggil di
     * setiap checkout / preview / layar kasir → dicari lewat index sponsor_admin_user_id, bukan memuat semua kartu.
     */
    public static function cardForBooker(User $user): ?UserMembershipBalance
    {
        return UserMembershipBalance::query()
            ->whereIn('user_membership_id', \App\Models\Sponsor\SponsorOrganization::query()
                ->where('sponsor_admin_user_id', $user->id)->select('user_membership_id'))
            ->where('facility', 'PADEL')->whereNotNull(self::MARKER)
            ->whereHas('membership', fn (Builder $m) => $m->where('status', 'ACTIVE'))
            ->with(['membership.sponsorOrganization', 'membership.user:id,name'])
            ->latest()
            ->get()
            ->first(fn (UserMembershipBalance $b) => $b->membership && self::bookerId($b->membership) === (string) $user->id);
    }

    /** Nama penanggung tagihan: nama perusahaan untuk kartu perusahaan, nama pemilik untuk perorangan. */
    public static function billedName(UserMembership $membership): string
    {
        return $membership->sponsorOrganization?->name ?? $membership->user?->name ?? '-';
    }

    /** Label kartu untuk pilihan di layar admin, mis. "Huawei — MBR-123". */
    public static function cardLabel(UserMembershipBalance $balance): string
    {
        $membership = $balance->membership;

        return ($membership ? self::billedName($membership) : '-').($membership?->membership_code ? ' — '.$membership->membership_code : '');
    }

    /** Alasan kartu tidak dapat dipakai untuk jam main ini (null = dapat dipakai). */
    public static function unusableReason(UserMembershipBalance $balance, Carbon $start, Carbon $end): ?string
    {
        $membership = $balance->membership;
        if (! $membership || ! $membership->isActive()) {
            return 'Membership tagihan bulanan sudah tidak aktif.';
        }
        if ($membership->start_date && $start->toDateString() < $membership->start_date->toDateString()) {
            return 'Tanggal main sebelum masa aktif membership ('.$membership->start_date->translatedFormat('d M Y').').';
        }
        if ($membership->end_date && $start->toDateString() > $membership->end_date->toDateString()) {
            return 'Tanggal main melewati masa aktif membership ('.$membership->end_date->translatedFormat('d M Y').').';
        }
        if ($membership->sponsorOrganization?->isSuspended()) {
            return 'Perusahaan pemilik membership sedang dinonaktifkan.';
        }
        if (PadelAccessRule::violation($balance, $start, $end)) {
            return 'Jam main di luar ketentuan paket (berlaku: '.PadelAccessRule::label($balance).').';
        }

        return null;
    }

    /**
     * Catat booking yang baru ditahan (LOCKED, dikunci pemanggil, relasi court termuat) pada tagihan bulanan: kartu dicek
     * (aktif, masa berlaku, perusahaan tidak dinonaktifkan, aturan akses paket), harga sewa Rp0, order Rp0 lunas dengan
     * metode "Tagihan Bulanan Perusahaan", booking langsung terkonfirmasi (PAID + tiket QR). Tidak ada uang masuk.
     *
     * @param  Collection<int, PadelBooking>  $bookings
     * @param  string  $orderType  WALK_IN (Jadwal Tetap / resepsionis) | ONLINE_BOOKING (PIC dari web / aplikasi)
     * @param  array<string, mixed>  $bookingAttributes  kolom tambahan booking (mis. recurring_schedule_id)
     */
    public static function recordBookings(Collection $bookings, UserMembershipBalance $balance, User $owner, ?User $actor, string $orderType, string $itemLabel, string $source, array $bookingAttributes = []): Order
    {
        if (! self::forBalance($balance)) {
            throw new HttpException(422, 'Membership ini tidak memakai Tagihan Bulanan.');
        }

        foreach ($bookings as $booking) {
            if ($reason = self::unusableReason($balance, Carbon::parse($booking->start_time), Carbon::parse($booking->end_time))) {
                throw new HttpException(422, $reason);
            }
            $booking->forceFill([
                'membership_balance_id' => $balance->id,
                'member_hours_consumed' => 0,
                'member_discount_court' => 0,
                'total_amount' => max(0, (float) $booking->total_amount - (float) $booking->court_fee),
                'court_fee' => 0,
            ])->save();
        }

        $order = Order::create([
            'order_number' => 'ORD-PAD-'.strtoupper(Str::random(10)),
            'user_id' => $owner->id,
            'cashier_id' => $orderType === 'ONLINE_BOOKING' ? null : $actor?->id,
            'order_type' => $orderType,
            'subtotal' => 0,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'service_charge' => 0,
            'grand_total' => 0,
            'payment_status' => 'UNPAID',
        ]);

        foreach ($bookings as $booking) {
            $order->items()->create([
                'item_type' => 'PADEL',
                'reference_id' => $booking->id,
                'item_name' => 'Sewa '.($booking->court?->name ?? 'Court Padel').' · '.$itemLabel,
                'quantity' => 1,
                'unit_price' => 0,
                'subtotal' => 0,
            ]);

            $booking->forceFill($bookingAttributes + [
                'order_id' => $order->id,
                'status' => 'PAID',
                'expires_at' => null,
                'qr_code_hash' => 'VNT-TICKET-'.strtoupper(bin2hex(random_bytes(16))),
            ])->save();
        }

        $membership = $balance->membership;
        app(\App\Services\Payment\PaymentOrchestratorService::class)->markOrderAsPaid($order, [
            'payment_gateway' => self::METHOD,
            'payment_method' => self::METHOD,
            'transaction_id' => $order->order_number,
            'amount' => 0.0,
            'payload_log' => [
                'covered_by' => self::METHOD,
                'source' => $source,
                'monthly_balance_id' => $balance->id,
                'monthly_billed_name' => $membership ? self::billedName($membership) : null,
                'created_by' => $actor?->name ?? $owner->name,
            ],
        ]);

        return $order;
    }

    /**
     * Batalkan satu transaksi booking tagihan bulanan (semua jamnya): keluar dari rekap, tanpa refund (tidak ada uang).
     * Pembatalan staf ($enforceDeadline) hanya sebelum batas waktu reschedule — lewat batas / sesi sudah dimulai /
     * sudah check-in = tetap tercatat. Lewati tanggal & hapus Jadwal Tetap (keputusan Club 61) tidak memakai batas
     * waktu, tetapi sesi yang sudah dimulai tetap tidak disentuh.
     */
    public function cancel(PadelBooking $booking, string $reason, User $by, bool $enforceDeadline = true): void
    {
        $reason = trim(mb_substr($reason, 0, 255));
        if ($enforceDeadline && mb_strlen($reason) < 5) {
            throw new HttpException(422, 'Alasan pembatalan wajib diisi (minimal 5 karakter).');
        }
        $reason = $reason !== '' ? $reason : 'Dibatalkan dari Jadwal Tetap';

        $bookingService = app(PadelBookingService::class);

        DB::transaction(function () use ($booking, $reason, $by, $enforceDeadline, $bookingService) {
            Order::whereKey($booking->order_id)->lockForUpdate()->first();
            $sessions = PadelBooking::where('order_id', $booking->order_id)->whereNotNull('order_id')->lockForUpdate()->get();

            if ($sessions->isEmpty() || ! self::isTabBooking($booking)) {
                throw new HttpException(422, 'Booking ini bukan booking Tagihan Bulanan.');
            }
            $active = $sessions->filter(fn (PadelBooking $b) => ! in_array($b->status, ['CANCELLED', 'EXPIRED'], true));
            if ($active->contains(fn (PadelBooking $b) => $b->status !== 'PAID' || $b->start_time->lte(now()))) {
                throw new HttpException(422, 'Sesi sudah dimulai, check-in, atau selesai sehingga tetap tercatat pada Tagihan Bulanan.');
            }
            if ($active->isEmpty()) {
                throw new HttpException(422, 'Booking ini sudah dibatalkan.');
            }
            if ($enforceDeadline) {
                $deadline = $active->map(fn (PadelBooking $b) => PadelBookingService::rescheduleDeadline($b))->min();
                if (now()->gte($deadline)) {
                    throw new HttpException(422, 'Batas pembatalan sudah terlewati ('.PadelBookingService::rescheduleCutoffLabel($active->first())
                        .' sebelum jam main). Sesi tetap tercatat pada Tagihan Bulanan.');
                }
            }

            foreach ($active as $session) {
                $bookingService->requestCancelAndRefund($session->id, 'TAGIHAN_BULANAN', $reason, $by);
            }

            $first = $active->sortBy('start_time')->first();
            ActivityLogger::record('PADEL', 'monthly_bill.cancelled',
                "Membatalkan booking Tagihan Bulanan {$first->booking_code} (".$active->count().' slot)',
                subject: $first,
                meta: ['alasan' => $reason, 'jadwal' => $first->start_time->format('d/m/Y H:i')],
                severity: ActivityLogger::WARNING,
                causer: $by,
                subjectLabel: $first->booking_code,
            );
        });

        \Illuminate\Support\Facades\Cache::forget('kelola_pemesanan_tab_counts');
    }

    /**
     * Rekap jam main satu kartu pada satu bulan (menurut tanggal main), untuk Finance. Tanpa harga.
     *
     * @param  string  $month  Y-m
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array{bookings: int, hours: float, regular_hours: float, peak_hours: float, played_hours: float, no_show_hours: float, scheduled_hours: float}}
     */
    public static function recap(UserMembershipBalance $balance, string $month): array
    {
        $start = Carbon::parse($month.'-01')->startOfMonth();
        $peak = app(PeakHourService::class);

        // Order harus lunas: booking yang sewa alatnya tidak jadi dibayar (hangus sebelum terkonfirmasi) tidak dihitung.
        $rows = self::tabBookings($balance->id)
            ->whereIn('status', self::RECAP_STATUSES)
            ->whereHas('order', fn (Builder $o) => $o->where('payment_status', 'PAID'))
            ->whereBetween('booking_date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->with(['court:id,name', 'order:id,order_type'])
            ->orderBy('start_time')
            ->get()
            ->map(function (PadelBooking $b) use ($peak) {
                $peakMinutes = 0;
                for ($cursor = $b->start_time->copy(); $cursor->lt($b->end_time); $cursor = $next) {
                    $next = $cursor->copy()->addHour()->min($b->end_time);
                    if ($peak->isPeak($cursor)) {
                        $peakMinutes += $cursor->diffInMinutes($next);
                    }
                }
                $hours = round($b->start_time->diffInMinutes($b->end_time) / 60, 2);

                return [
                    'code' => $b->booking_code,
                    'date' => $b->start_time->locale('id')->translatedFormat('D, d M Y'),
                    'time' => $b->start_time->format('H:i').'–'.$b->end_time->format('H:i'),
                    'court' => $b->court?->name ?? '-',
                    'hours' => $hours,
                    'peak_hours' => round($peakMinutes / 60, 2),
                    'regular_hours' => round($hours - $peakMinutes / 60, 2),
                    'status' => $b->status,
                    'status_label' => self::STATUS_LABELS[$b->status] ?? $b->status,
                    'source' => match (true) {
                        $b->recurring_schedule_id !== null => 'Jadwal Tetap',
                        $b->order?->order_type === 'ONLINE_BOOKING' => 'Booking Online PIC',
                        default => 'Walk-in Kasir',
                    },
                ];
            });

        $sum = fn (string $key, array $statuses = []) => round((float) $rows
            ->filter(fn ($r) => $statuses === [] || in_array($r['status'], $statuses, true))->sum($key), 2);

        return [
            'rows' => $rows,
            'totals' => [
                'bookings' => $rows->count(),
                'hours' => $sum('hours'),
                'regular_hours' => $sum('regular_hours'),
                'peak_hours' => $sum('peak_hours'),
                'played_hours' => $sum('hours', ['CHECKED_IN', 'COMPLETED']),
                'no_show_hours' => $sum('hours', ['EXPIRED']),
                'scheduled_hours' => $sum('hours', ['PAID']),
            ],
        ];
    }

    /** Format jam, mis. 3 → "3", 1.5 → "1,5". */
    public static function hoursLabel(float $hours): string
    {
        return rtrim(rtrim(number_format($hours, 2, ',', '.'), '0'), ',');
    }

    /** @return list<string> periode (Y-m) kartu ini yang pembayarannya sudah tercatat (belum dibatalkan) — satu query. */
    public static function paidPeriods(string $balanceId): array
    {
        return MonthlyBillingPayment::payments()->where('payload_log->monthly_balance_id', $balanceId)->get(['id', 'payload_log'])
            ->reject(fn (Payment $p) => ! empty($p->payload_log['voided']))
            ->map(fn (Payment $p) => (string) ($p->payload_log['monthly_period'] ?? ''))
            ->unique()->values()->all();
    }

    /** Pembayaran tagihan bulanan yang tercatat (belum dibatalkan) untuk kartu & bulan ini. */
    public static function paymentFor(string $balanceId, string $month): ?Payment
    {
        // Disaring di database (index payments.payment_gateway) — bukan memuat seluruh riwayat pembayaran.
        return MonthlyBillingPayment::payments()
            ->where('payload_log->monthly_balance_id', $balanceId)
            ->where('payload_log->monthly_period', $month)
            ->get()
            ->first(fn (Payment $p) => empty($p->payload_log['voided']));
    }
}
