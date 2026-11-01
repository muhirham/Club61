<?php

namespace App\Services\Membership;

use App\Models\Membership\MembershipPlan;
use App\Models\Membership\UserMembership;
use App\Models\Membership\UserMembershipBalance;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelRecurringSchedule;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Terapkan kuota & masa aktif paket terbaru ke kartu yang sudah terjual (izin apply_membership_plan_quota, bawaan hanya
 * super admin). Kartu menyimpan salinan kuota & tanggal berakhir saat dibeli supaya edit paket tidak diam-diam mengubah
 * kartu yang berjalan; tombol ini menyamakannya dengan sengaja, setelah admin melihat pratinjau per kartu.
 *  - Kuota (jam / kunjungan bernilai angka): selisih paket baru − kuota awal kartu ditambahkan ke sisa kuota lewat
 *    MembershipBalanceService::adjustQuota (MANUAL_ADJUSTMENT, tercatat di Audit Log Mutasi Kuota). Pengurangan tidak
 *    pernah membuat sisa minus — paling rendah 0.
 *  - Masa aktif: tanggal berakhir = tanggal mulai kartu + masa aktif paket. Bila hasilnya sebelum hari ini, atau kartu
 *    sudah pernah diperpanjang, tanggal berakhirnya tidak diubah. Batas akhir Booking Mingguan kartu yang lewat dari tanggal
 *    berakhir baru ikut dipotong; booking yang sudah dibuat setelahnya tidak dibatalkan (hanya diinfokan di pratinjau).
 *  - Kartu menunggu pembayaran: hanya kuota awalnya yang disamakan (diisi saat aktivasi; masa aktif dihitung saat aktivasi).
 * Diskon & aturan akses tetap lewat "Terapkan Aturan" (applyPlanAccessRules).
 */
class MembershipPlanQuotaService
{
    use DetectsConcurrencyErrors;

    public const CHANGED_MEANWHILE = 'Kuota salah satu member baru saja berubah saat perubahan diterapkan, jadi tidak ada yang disimpan. Buka ulang pratinjau lalu coba lagi.';

    public function __construct(private MembershipBalanceService $balances) {}

    /**
     * @return list<array{membership_id: string, code: string, owner: string, status: string, quotas: list<array>, end_date: ?array, notes: list<string>, changes: bool}>
     */
    public function preview(MembershipPlan $plan): array
    {
        $plan->load('benefits');
        $memberships = $this->cardsOf($plan)->orderBy('membership_code')->get();

        return $memberships->map(fn (UserMembership $m) => $this->planFor($plan, $m))->values()->all();
    }

    /** Kartu aktif / menunggu bayar milik paket + penanda sudah pernah diperpanjang (renewal MERGED). */
    private function cardsOf(MembershipPlan $plan): \Illuminate\Database\Eloquent\Builder
    {
        return UserMembership::with(['user:id,name', 'balances'])
            ->withExists(['renewalMemberships as was_renewed' => fn ($q) => $q->where('status', 'MERGED')])
            ->where('plan_id', $plan->id)->whereIn('status', MembershipPlan::IN_USE_STATUSES);
    }

    /** @return array{memberships: int, quotas: int, end_dates: int, schedules: int} */
    public function apply(MembershipPlan $plan, User $by): array
    {
        if (! $by->can('apply_membership_plan_quota')) {
            throw new HttpException(403, 'Akses ditolak: Anda tidak memiliki izin [apply_membership_plan_quota].');
        }

        // Member memakai kuota tepat saat diterapkan → sisa minus (DomainException) / deadlock: semua dibatalkan, admin
        // diminta membuka ulang pratinjau. Kesalahan lain tetap dilempar apa adanya.
        try {
            return $this->applyLocked($plan, $by);
        } catch (DomainException $e) {
            throw new HttpException(409, self::CHANGED_MEANWHILE);
        } catch (QueryException $e) {
            if (! $this->causedByConcurrencyError($e)) {
                throw $e;
            }
            throw new HttpException(409, self::CHANGED_MEANWHILE);
        }
    }

    /** @return array{memberships: int, quotas: int, end_dates: int, schedules: int} */
    private function applyLocked(MembershipPlan $plan, User $by): array
    {
        return DB::transaction(function () use ($plan, $by) {
            // Kunci paket lebih dulu (sebelum membaca apa pun) supaya dua penerapan bersamaan berjalan bergantian dan yang
            // kedua membaca kartu yang sudah diubah, bukan salinan lama.
            MembershipPlan::whereKey($plan->id)->lockForUpdate()->first();
            $plan->load('benefits');
            $memberships = $this->cardsOf($plan)->orderBy('id')->lockForUpdate()->get();

            $result = ['memberships' => 0, 'quotas' => 0, 'end_dates' => 0, 'schedules' => 0];
            $changed = [];
            foreach ($memberships as $membership) {
                $row = $this->planFor($plan, $membership);
                if (! $row['changes']) {
                    continue;
                }
                $result['memberships']++;

                foreach ($row['quotas'] as $q) {
                    if (! $q['apply']) {
                        continue;
                    }
                    $balance = UserMembershipBalance::whereKey($q['balance_id'])->lockForUpdate()->firstOrFail();
                    // Isi kartu dibaca ulang di dalam kunci: berbeda dengan dasar perhitungan → semua dibatalkan (409).
                    if (abs((float) $balance->initial_quota - $q['old_initial']) >= 0.005 || abs((float) $balance->remaining_quota - $q['old_remaining']) >= 0.005) {
                        throw new DomainException('Kuota kartu berubah saat diterapkan.');
                    }
                    if ($q['adjust'] != 0.0) {
                        $this->balances->adjustQuota(
                            balanceId: $balance->id,
                            changeType: 'MANUAL_ADJUSTMENT',
                            quantity: $q['adjust'],
                            notes: "Penyesuaian paket {$plan->name} oleh {$by->name}: kuota {$this->num($q['old_initial'])} → {$this->num($q['new_initial'])}",
                            relatedType: MembershipPlan::class,
                            relatedId: $plan->id,
                            performedBy: $by->id,
                        );
                    }
                    UserMembershipBalance::whereKey($balance->id)->update(['initial_quota' => $q['new_initial'], 'updated_at' => now()]);
                    $result['quotas']++;
                }

                if ($row['end_date'] && $row['end_date']['apply']) {
                    $membership->update(['end_date' => $row['end_date']['new']]);
                    // Jadwal tetap mingguan kartu ini tidak boleh membuat booking lewat masa aktif barunya.
                    $result['schedules'] += $this->schedulesEndingAfter($membership->id, $row['end_date']['new'])
                        ->update(['ends_on' => $row['end_date']['new'], 'updated_at' => now()]);
                    $result['end_dates']++;
                }
                $changed[$row['code']] = $row['summary'];
            }

            if ($result['memberships'] > 0) {
                ActivityLogger::record(
                    module: 'MEMBERSHIP',
                    event: 'membership_plan.quota_applied',
                    description: "Kuota & masa aktif paket {$plan->name} diterapkan ke {$result['memberships']} kartu",
                    subject: $plan,
                    meta: array_filter(['paket' => $plan->name, 'kartu' => $changed, 'jadwal_mingguan_dipotong' => $result['schedules']]),
                    severity: ActivityLogger::WARNING,
                    causer: $by,
                );
            }

            return $result;
        });
    }

    /** Rencana perubahan satu kartu (dipakai pratinjau & penerapan supaya hasilnya sama persis). */
    private function planFor(MembershipPlan $plan, UserMembership $membership): array
    {
        $quotas = [];
        $notes = [];
        $benefits = $plan->benefits->keyBy('facility');

        foreach ($membership->balances as $balance) {
            $benefit = $benefits->get($balance->facility);
            if (! $benefit) {
                continue; // fasilitas sudah tidak ada di paket — kartu tidak diubah
            }
            // Hanya kuota angka dengan jenis yang sama (jam ↔ jam, kunjungan ↔ kunjungan). Unlimited / diskon saja dilewati.
            $numeric = $balance->initial_quota !== null && $benefit->quota_value !== null && $benefit->quota_type === $balance->quota_type;
            if (! $numeric) {
                $differs = $benefit->quota_type !== $balance->quota_type || ($balance->initial_quota === null) !== ($benefit->quota_value === null);
                if ($differs) {
                    $notes[] = "{$balance->facility}: jenis kuota kartu berbeda dengan paket — tidak diubah.";
                }

                continue;
            }

            $oldInitial = (float) $balance->initial_quota;
            $newInitial = (float) $benefit->quota_value;
            if (abs($newInitial - $oldInitial) < 0.005) {
                continue;
            }

            $remaining = (float) $balance->remaining_quota;
            $isActive = $membership->status === 'ACTIVE';
            $wanted = $newInitial - $oldInitial;
            // Kartu menunggu bayar belum diisi (sisa 0) — cukup kuota awalnya yang disamakan.
            $adjust = $isActive ? max($wanted, -$remaining) : 0.0;
            $clamped = $isActive && $adjust > $wanted;
            if ($clamped) {
                $notes[] = "{$balance->facility}: sisa kuota hanya {$this->num($remaining)}, dikurangi sampai 0.";
            }

            $quotas[] = [
                'balance_id' => $balance->id,
                'facility' => $balance->facility,
                'unit' => $balance->quota_type === 'HOURS' ? 'jam' : 'kunjungan',
                'old_initial' => $oldInitial,
                'new_initial' => $newInitial,
                'old_remaining' => $remaining,
                'new_remaining' => $isActive ? round($remaining + $adjust, 2) : $remaining,
                'adjust' => round($adjust, 2),
                'clamped' => $clamped,
                'apply' => true,
            ];
        }

        $endDate = null;
        if ($membership->status === 'ACTIVE' && $membership->start_date && $plan->duration_days) {
            $newEnd = Carbon::parse($membership->start_date)->addDays((int) $plan->duration_days);
            $oldEnd = $membership->end_date ? Carbon::parse($membership->end_date) : null;
            if (! $oldEnd || ! $newEnd->isSameDay($oldEnd)) {
                // Perpanjangan mendorong tanggal berakhir dari tanggal berakhir lama (fulfillRenewal), jadi rumus
                // tanggal mulai + masa aktif akan memotong masa perpanjangan yang sudah dibayar.
                $renewed = (bool) $membership->was_renewed;
                $tooEarly = $newEnd->lt(today());
                if ($renewed) {
                    $notes[] = 'Kartu sudah diperpanjang — tanggal berakhir tidak diubah.';
                } elseif ($tooEarly) {
                    $notes[] = 'Masa aktif baru berakhir '.$newEnd->locale('id')->translatedFormat('d M Y').' (sudah lewat) — tanggal berakhir tidak diubah.';
                }
                $endDate = ['old' => $oldEnd?->toDateString(), 'new' => $newEnd->toDateString(), 'apply' => ! $renewed && ! $tooEarly];
                if ($endDate['apply']) {
                    $newEndLabel = $newEnd->locale('id')->translatedFormat('d M Y');
                    $schedules = $this->schedulesEndingAfter($membership->id, $endDate['new'])->count();
                    if ($schedules > 0) {
                        $notes[] = "Booking Mingguan: batas akhir {$schedules} jadwal ikut dipotong ke {$newEndLabel}.";
                    }
                    $bookedAfter = PadelBooking::whereIn('recurring_schedule_id', PadelRecurringSchedule::where('user_membership_id', $membership->id)->select('id'))
                        ->where('booking_date', '>', $endDate['new'])->whereNotIn('status', ['CANCELLED', 'REFUNDED'])->count();
                    if ($bookedAfter > 0) {
                        $notes[] = "{$bookedAfter} booking jadwal tetap setelah {$newEndLabel} sudah dibuat — tidak dibatalkan otomatis, batalkan di Kelola Pemesanan bila perlu.";
                    }
                }
            }
        }

        $summary = array_filter([
            ...array_map(fn ($q) => "{$q['facility']} {$this->num($q['old_initial'])}→{$this->num($q['new_initial'])} {$q['unit']}", $quotas),
            $endDate && $endDate['apply'] ? "berakhir {$endDate['old']}→{$endDate['new']}" : null,
        ]);

        return [
            'membership_id' => $membership->id,
            'code' => $membership->membership_code,
            'owner' => $membership->user?->name ?? '-',
            'status' => $membership->status,
            'quotas' => $quotas,
            'end_date' => $endDate,
            'notes' => $notes,
            'summary' => implode(', ', $summary),
            'changes' => $quotas !== [] || ($endDate['apply'] ?? false),
        ];
    }

    /** Jadwal tetap mingguan kartu (aktif / dijeda) yang batas akhirnya lewat dari tanggal berakhir kartu yang baru. */
    private function schedulesEndingAfter(string $membershipId, string $date): \Illuminate\Database\Eloquent\Builder
    {
        return PadelRecurringSchedule::where('user_membership_id', $membershipId)
            ->whereIn('status', [PadelRecurringSchedule::STATUS_ACTIVE, PadelRecurringSchedule::STATUS_PAUSED])
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>', $date));
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    }
}
