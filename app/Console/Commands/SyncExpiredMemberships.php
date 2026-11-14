<?php

namespace App\Console\Commands;

use App\Models\Membership\UserMembership;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncExpiredMemberships extends Command
{
    protected $signature = 'membership:sync-expired';

    protected $description = 'Sinkronisasi status membership yang sudah habis masa aktif atau kuotanya habis.';

    public function handle(): int
    {
        $today = Carbon::today()->toDateString();
        $expiredCount = 0;

        // Daftar kandidat saja (tanpa lock). Keputusan akhir diambil ulang per kartu di bawah lock: selama job berjalan,
        // kartu bisa dibatalkan admin (CANCELLED) atau diperpanjang (end_date maju) — dulu job menimpa keduanya jadi
        // EXPIRED dari data yang sudah basi.
        $candidateIds = UserMembership::where('status', 'ACTIVE')->pluck('id');

        foreach ($candidateIds as $membershipId) {
            $expired = DB::transaction(function () use ($membershipId, $today) {
                $membership = UserMembership::whereKey($membershipId)->lockForUpdate()->first();

                if (! $membership || $membership->status !== 'ACTIVE' || ! self::shouldExpire($membership, $today)) {
                    return false;
                }

                $membership->update(['status' => 'EXPIRED']);

                return true;
            });

            if ($expired) {
                $expiredCount++;
            }
        }

        $this->info("Sinkronisasi selesai: {$expiredCount} membership telah ditandai EXPIRED.");

        // Pesanan membership online yang ditinggal tanpa dibayar (> 24 jam) dibatalkan, setelah dicek ke Midtrans.
        $abandoned = app(\App\Services\Membership\MembershipOnlinePaymentService::class)->cancelStale();
        $this->info("{$abandoned} pesanan membership online yang tidak dibayar dibatalkan.");

        return Command::SUCCESS;
    }

    /** Dipanggil di bawah lock baris kartu; balance dibaca segar (kuota bisa berubah selama job berjalan). */
    private static function shouldExpire(UserMembership $membership, string $today): bool
    {
        // Kondisi 1: end_date sudah terlewat
        if ($membership->end_date && $membership->end_date < $today) {
            return true;
        }

        // Kondisi 2: kartu sudah TIDAK memberi apa pun lagi sebelum end_date. Hanya kalau SEMUA balance
        // berkuota terbatas, semuanya habis, dan tidak ada diskon tersisa. Dulu cukup "semua HOURS/VISITS
        // sisa 0": balance unlimited (initial_quota null, sisa selalu 0) dianggap habis → kartu gym unlimited
        // hangus sehari setelah dibeli, dan diskon "di luar kuota" ikut hilang sebelum masa berlaku habis.
        $balances = $membership->balances()->get();
        $stillGivesSomething = $balances->contains(fn ($b) => ! in_array($b->quota_type, ['HOURS', 'VISITS'], true)
            || $b->initial_quota === null
            || (float) $b->remaining_quota > 0
            || (float) $b->discount_percent > 0);

        // Kuota yang terpotong oleh booking yang belum dimainkan (mis. Jadwal Tetap) belum berarti kartunya selesai:
        // dulu kartu corporate langsung EXPIRED sehari setelah Jadwal Tetap memotong seluruh jamnya, padahal bookingnya
        // belum dimainkan (reschedule jadi harga normal, jam dari booking yang batal tidak bisa dipakai lagi).
        return $balances->isNotEmpty() && ! $stillGivesSomething
            && ! \App\Services\Membership\MembershipCancellationService::upcomingQuotaBookings($membership);
    }
}
