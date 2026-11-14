<?php

namespace App\Services\Membership;

use App\Models\Membership\UserMembershipBalance;
use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Sponsor\CorporateTransfer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Modul 34 — pencatatan pembayaran Tagihan Bulanan yang ditransfer perusahaan langsung ke rekening Club 61. Nominal
 * diketik sesuai invoice Finance (sistem tidak menghitung harga). Satu pembayaran per kartu per bulan, dibukukan sebagai
 * Sewa Lapangan (item MONTHLY_BILL) dengan sumber "Transfer Corporate" — tanpa shift kasir.
 *
 * Tanpa tabel baru: kartu, periode & ringkasan jam disimpan di payments.payload_log. Salah input dibatalkan lewat
 * CorporateTransfer::void() (baris buku pembalik, pembayarannya ditandai dibatalkan) lalu dicatat ulang.
 */
class MonthlyBillingPayment
{
    public const ITEM_TYPE = 'MONTHLY_BILL';

    public const ORDER_TYPE = 'CORPORATE';

    /** Pembayaran tagihan bulanan (termasuk yang dibatalkan — cek payload_log.voided). */
    public static function payments(): Builder
    {
        return CorporateTransfer::payments()->where('status', 'SUCCESS')->whereNotNull('payload_log->monthly_period');
    }

    /** Pilihan periode pembayaran: bulan masa aktif kartu sampai bulan ini, terbaru dulu. */
    public static function periodOptions(UserMembershipBalance $balance): array
    {
        $membership = $balance->membership;
        $from = Carbon::parse(($membership?->start_date ?? now())->format('Y-m-01'));
        $until = Carbon::parse(now()->format('Y-m-01'));
        if ($membership?->end_date && $membership->end_date->lt($until)) {
            $until = Carbon::parse($membership->end_date->format('Y-m-01'));
        }

        $options = [];
        for ($m = $until->copy(); $m->gte($from) && count($options) < 36; $m->subMonthNoOverflow()) {
            $options[$m->format('Y-m')] = MonthlyBilling::monthLabel($m);
        }

        return $options;
    }

    public function record(UserMembershipBalance $balance, string $month, float $amount, array $data, User $by): Payment
    {
        try {
            if (! $by->can(CorporateTransfer::PERMISSION)) {
                throw new HttpException(403, 'Akses ditolak: Anda tidak memiliki izin untuk mencatat pembayaran transfer perusahaan.');
            }
            if (! MonthlyBilling::forBalance($balance)) {
                throw new HttpException(422, 'Membership ini tidak memakai Tagihan Bulanan.');
            }
            if (! isset(self::periodOptions($balance)[$month])) {
                throw new HttpException(422, 'Periode tagihan tidak valid untuk masa aktif membership ini.');
            }
            $amount = round($amount, 2);
            if ($amount < 1 || $amount > 10_000_000_000) {
                throw new HttpException(422, 'Nominal pembayaran harus lebih dari Rp 0.');
            }
            $details = CorporateTransfer::details($data, $by);

            return CorporateTransfer::withUnusedReference($details, fn () => DB::transaction(function () use ($balance, $month, $amount, $details, $by) {
                UserMembershipBalance::whereKey($balance->id)->lockForUpdate()->first();
                if (MonthlyBilling::paymentFor($balance->id, $month)) {
                    throw new HttpException(422, 'Pembayaran Tagihan Bulanan periode '.MonthlyBilling::monthLabel(Carbon::parse($month.'-01')).' sudah dicatat.');
                }

                $membership = $balance->membership()->with(['sponsorOrganization', 'user:id,name'])->first();
                $billedName = $membership ? MonthlyBilling::billedName($membership) : '-';
                $monthLabel = MonthlyBilling::monthLabel(Carbon::parse($month.'-01'));
                $totals = MonthlyBilling::recap($balance, $month)['totals'];

                $order = Order::create([
                    'order_number' => 'ORD-TB-'.strtoupper(Str::random(10)),
                    'user_id' => ($membership ? MonthlyBilling::bookerId($membership) : null) ?? $membership?->user_id,
                    'cashier_id' => null,
                    'order_type' => self::ORDER_TYPE,
                    'subtotal' => $amount,
                    'discount_amount' => 0,
                    'tax_amount' => 0,
                    'service_charge' => 0,
                    'grand_total' => $amount,
                    'payment_status' => 'UNPAID',
                ]);
                $order->items()->create([
                    'item_type' => self::ITEM_TYPE,
                    'reference_id' => $balance->id,
                    'item_name' => "Tagihan Bulanan {$billedName} — {$monthLabel} (".MonthlyBilling::hoursLabel($totals['hours']).' jam)',
                    'quantity' => 1,
                    'unit_price' => $amount,
                    'subtotal' => $amount,
                ]);

                $payment = CorporateTransfer::markPaid($order, $amount, $details, [
                    'monthly_balance_id' => $balance->id,
                    'monthly_period' => $month,
                    'monthly_billed_name' => $billedName,
                    'monthly_membership_code' => $membership?->membership_code,
                    'monthly_hours' => $totals['hours'],
                    'monthly_bookings' => $totals['bookings'],
                ]);

                ActivityLogger::record('MEMBERSHIP', 'monthly_bill.payment_recorded',
                    "Mencatat pembayaran Tagihan Bulanan {$billedName} periode {$monthLabel}: ".ActivityLogger::rupiah($amount).' (transfer '.$details['sender_bank'].' '.$details['reference'].')',
                    subject: $balance,
                    meta: ['periode' => $monthLabel, 'jam' => $totals['hours'], 'tanggal_transfer' => $details['transfer_date'], 'kartu' => $membership?->membership_code],
                    causer: $by,
                    subjectLabel: $billedName,
                );

                return $payment;
            }));
        } catch (\Throwable $e) {
            CorporateTransfer::discardProof($data['proof_path'] ?? null);

            throw $e;
        }
    }
}
