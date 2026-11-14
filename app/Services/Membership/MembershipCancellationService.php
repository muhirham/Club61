<?php

namespace App\Services\Membership;

use App\Models\Membership\MembershipUsageLog;
use App\Models\Membership\UserMembership;
use App\Models\Membership\UserMembershipBalance;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelRecurringSchedule;
use App\Models\User;
use App\Models\Wellness\WellnessBooking;
use App\Services\Audit\ActivityLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Batalkan Membership (izin cancel_membership, pintu belakang): kartu individu yang terjual karena kesalahan (mis. harga
 * paket salah input) dihentikan permanen — status CANCELLED, sisa kuota dihanguskan. Uang tidak disentuh: penjualan kartu
 * tetap tercatat di Buku Transaksi (tanpa refund / voucher). Checkout, kasir, check-in & wellness sudah mensyaratkan
 * status ACTIVE, jadi kartu langsung tidak bisa dipakai.
 *
 * Ditolak selama kartu masih punya pemakaian yang berjalan, supaya tidak ada booking Rp0 yang menggantung di kartu batal
 * dan kartu tidak hidup lagi: booking padel / wellness mendatang yang memakai kuota, Jadwal Tetap yang belum dihentikan,
 * dan pembelian perpanjangan yang menunggu bayar (perpanjangan yang lunas mengaktifkan kembali kartu induknya).
 */
class MembershipCancellationService
{
    public const PERMISSION = 'cancel_membership';

    /** Penanda baris Audit Log Mutasi Kuota pembatalan (related_type) — sumber keterangan "dibatalkan oleh" di detail kartu. */
    public const LOG_MARKER = 'MEMBERSHIP_CANCELLED';

    private const NOTE_PREFIX = 'Kartu dibatalkan: ';

    /** Booking padel yang masih memakai jam kartu: ditahan, menunggu bayar, lunas, sedang main. */
    private const PADEL_LIVE_STATUSES = ['LOCKED', 'PENDING_PAYMENT', 'PENDING', 'PAID', 'CHECKED_IN'];

    public function __construct(private MembershipBalanceService $balances) {}

    /**
     * Kode booking padel & wellness mendatang yang memakai kuota kartu ini. Dipakai juga sinkronisasi kartu habis: kuota
     * yang sudah terpotong oleh booking yang belum dimainkan (mis. Jadwal Tetap) belum berarti kartunya selesai.
     *
     * @return list<string>
     */
    public static function upcomingQuotaBookings(UserMembership $membership): array
    {
        $balanceIds = $membership->balances()->pluck('id');
        if ($balanceIds->isEmpty()) {
            return [];
        }

        $padel = PadelBooking::whereIn('membership_balance_id', $balanceIds)
            ->whereIn('status', self::PADEL_LIVE_STATUSES)
            ->where('end_time', '>', now())
            ->orderBy('start_time')
            ->pluck('booking_code');
        $wellness = WellnessBooking::whereIn('membership_balance_id', $balanceIds)
            ->whereNotIn('status', ['CANCELLED', 'CHECKED_IN'])
            ->whereHas('slot', fn ($q) => $q->whereDate('session_date', '>=', now()->toDateString()))
            ->pluck('booking_code');

        return $padel->concat($wellness)->filter()->values()->all();
    }

    /** @return list<string> alasan kartu belum dapat dibatalkan (kosong = boleh dibatalkan). */
    public function blockers(UserMembership $membership): array
    {
        if ($membership->status !== 'ACTIVE') {
            return ['Hanya kartu berstatus ACTIVE yang dapat dibatalkan.'];
        }
        if ($membership->owner_type === 'ORGANIZATIONAL' || $membership->sponsorOrganization()->exists()) {
            return ['Kartu corporate / sponsor dikelola melalui Kelola Sponsor dan tidak dapat dibatalkan dari halaman ini.'];
        }
        if ($membership->balances->contains(fn (UserMembershipBalance $b) => MonthlyBilling::forBalance($b))) {
            return ['Kartu Tagihan Bulanan tidak dapat dibatalkan dari halaman ini.'];
        }

        $reasons = [];
        if ($codes = self::upcomingQuotaBookings($membership)) {
            $more = count($codes) > 5 ? ' dan '.(count($codes) - 5).' lainnya' : '';
            $reasons[] = 'Masih ada booking mendatang yang memakai kuota kartu ini ('.implode(', ', array_slice($codes, 0, 5)).$more
                .'). Batalkan atau pindahkan booking tersebut terlebih dahulu melalui Kelola Pemesanan.';
        }
        if (PadelRecurringSchedule::where('user_membership_id', $membership->id)->where('status', '!=', PadelRecurringSchedule::STATUS_STOPPED)->exists()) {
            $reasons[] = 'Kartu ini masih dipakai Jadwal Tetap Mingguan. Hentikan jadwal tersebut terlebih dahulu melalui Booking Mingguan.';
        }
        $renewals = UserMembership::where('renewal_of_id', $membership->id)->where('status', 'PENDING_PAYMENT')->pluck('membership_code');
        if ($renewals->isNotEmpty()) {
            $reasons[] = 'Masih ada pembelian perpanjangan kartu ini yang menunggu pembayaran ('.$renewals->implode(', ')
                .'). Kartu dapat dibatalkan setelah pembelian tersebut dibatalkan atau kedaluwarsa.';
        }

        return $reasons;
    }

    public function cancel(UserMembership $membership, string $reason, User $by): UserMembership
    {
        if (! $by->can(self::PERMISSION)) {
            throw new HttpException(403, 'Akses ditolak: Anda tidak memiliki izin [cancel_membership] untuk membatalkan membership.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new HttpException(422, 'Alasan pembatalan wajib diisi (minimal 5 karakter).');
        }
        $reason = mb_substr($reason, 0, 200);

        return DB::transaction(function () use ($membership, $reason, $by) {
            $locked = UserMembership::with('user:id,name')->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            // Kuota dikunci sebelum diperiksa: checkout / kasir yang sedang memotong kuota kartu ini menunggu kunci ini,
            // lalu melihat sisa 0 dan status bukan ACTIVE.
            $balances = UserMembershipBalance::where('user_membership_id', $locked->id)->lockForUpdate()->get();
            $locked->setRelation('balances', $balances);
            if ($blockers = $this->blockers($locked)) {
                throw new HttpException(422, implode(' ', $blockers));
            }

            $forfeited = [];
            foreach ($balances as $balance) {
                $remaining = (float) $balance->remaining_quota;
                // Satu baris per fasilitas (juga yang sisanya 0) — catatan permanen siapa, kapan & alasannya; Log Aktivitas
                // punya masa simpan.
                $this->balances->adjustQuota(
                    balanceId: $balance->id,
                    changeType: 'MANUAL_ADJUSTMENT',
                    quantity: $remaining > 0 ? -$remaining : 0.0,
                    notes: self::NOTE_PREFIX.$reason,
                    relatedType: self::LOG_MARKER,
                    relatedId: $locked->id,
                    performedBy: $by->id,
                );
                if ($remaining > 0) {
                    $forfeited[] = $balance->facility.' '.rtrim(rtrim(number_format($remaining, 2, ',', '.'), '0'), ',');
                }
            }

            $locked->update(['status' => 'CANCELLED']);

            ActivityLogger::record('MEMBERSHIP', 'membership.cancelled',
                "Membatalkan membership {$locked->membership_code} (".($locked->user?->name ?? '-')."): {$reason}",
                subject: $locked,
                meta: array_filter([
                    'alasan' => $reason,
                    'sisa kuota hangus' => $forfeited ? implode(', ', $forfeited) : null,
                    'harga beli' => ActivityLogger::rupiah((float) $locked->purchase_price_snapshot),
                ]),
                severity: ActivityLogger::WARNING,
                causer: $by,
                subjectLabel: $locked->membership_code,
            );

            return $locked->fresh(['balances']);
        });
    }

    /** @return array{by: ?string, at: CarbonInterface, reason: string}|null keterangan pembatalan oleh admin (null = bukan). */
    public static function cancellationInfo(UserMembership $membership): ?array
    {
        if ($membership->status !== 'CANCELLED') {
            return null;
        }
        $log = MembershipUsageLog::with('performer:id,name')
            ->where('related_type', self::LOG_MARKER)
            ->where('related_id', $membership->id)
            ->latest('created_at')
            ->first();

        return $log ? [
            'by' => $log->performer?->name,
            'at' => $log->created_at,
            'reason' => Str::after((string) $log->notes, self::NOTE_PREFIX),
        ] : null;
    }
}
