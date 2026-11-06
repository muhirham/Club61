<?php

namespace App\Services\Padel\Concerns;

use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelBookingEquipment;
use App\Models\Pos\Order;
use App\Models\User;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait ManagesTicketsAndRefunds
{
    // Modul 21: customer tidak bisa mengajukan refund sendiri. Pembatalan + refund hanya diajukan staf dari Kelola
    // Pemesanan (PadelBookingService::requestCancelAndRefund) dan disetujui / ditolak di Antrian Refund.

    /**
     * Mengambil riwayat booking user terfilter.
     */
    public function myBookings(User $user, ?string $status = null): Collection
    {
        $query = PadelBooking::with(['court', 'coach', 'equipments.equipment'])
            ->where('user_id', $user->id)
            ->latest('start_time');

        if ($status) {
            $statusUpper = strtoupper($status);
            if ($statusUpper === 'UPCOMING') {
                $query->whereIn('status', ['LOCKED', 'PAID'])->where('start_time', '>=', now());
            } elseif ($statusUpper === 'COMPLETED') {
                $query->whereIn('status', ['CHECKED_IN', 'COMPLETED']);
            } elseif ($statusUpper === 'CANCELLED') {
                $query->whereIn('status', ['CANCELLED', 'EXPIRED', 'REFUND_PENDING', 'REFUNDED']);
            }
        }

        $bookings = $query->get();

        // Sudah lunas = order punya pembayaran SUCCESS, berapa pun nominalnya (booking Rp0 voucher corporate / kuota member
        // juga lunas). Halaman invoice memakainya membedakan "Tidak hadir" dari "Batas waktu pembayaran habis".
        $paidOrderIds = \App\Models\Pos\Payment::whereIn('order_id', $bookings->pluck('order_id')->filter()->unique()->values())
            ->where('status', 'SUCCESS')
            ->distinct()
            ->pluck('order_id')
            ->flip();

        return $bookings->each(fn (PadelBooking $b) => $b->setAttribute('was_paid', $b->order_id !== null && isset($paidOrderIds[$b->order_id])));
    }

    /**
     * Mengambil detail satu tiket booking beserta konteks order terpadu.
     */
    public function getTicket(string $bookingId, User $user): PadelBooking
    {
        $order = Order::where('order_number', $bookingId)
            ->orWhere('id', $bookingId)
            ->first();
        $orderId = $order?->id;

        $booking = PadelBooking::with(['court', 'coach', 'equipments.equipment'])
            ->where(function ($q) use ($bookingId, $orderId) {
                $q->where('id', $bookingId)
                  ->orWhere('booking_code', $bookingId)
                  ->orWhere('order_id', $bookingId);
                if ($orderId) {
                    $q->orWhere('order_id', $orderId);
                }
            })
            ->where('user_id', $user->id)
            ->firstOrFail();

        // Jika booking ini terikat dengan order_id, sertakan seluruh sesi se-order dan alat sewa
        if ($booking->order_id) {
            $orderBookings = PadelBooking::with('court')
                ->where('order_id', $booking->order_id)
                ->where('user_id', $user->id)
                ->orderBy('start_time')
                ->get();

            $orderEquipments = PadelBookingEquipment::with('equipment')
                ->where('order_id', $booking->order_id)
                ->get();

            $totalEquipmentFee = $orderEquipments->sum('subtotal');
            $totalCourtFee = $orderBookings->sum('court_fee');
            $totalMemberDiscount = $orderBookings->sum('member_discount_court');
            $totalSponsorDiscount = $orderBookings->sum('sponsor_discount_court');
            $totalSponsorHours = $orderBookings->sum('sponsor_hours_consumed');

            $booking->setAttribute('order_bookings', $orderBookings);
            $booking->setAttribute('order_equipments', $orderEquipments);
            $booking->setAttribute('order_court_fee', (float) $totalCourtFee);
            $booking->setAttribute('order_equipment_fee', (float) $totalEquipmentFee);
            $booking->setAttribute('order_grand_total', (float) ($totalCourtFee + $totalEquipmentFee));
            $booking->setAttribute('order_member_discount_court', (float) $totalMemberDiscount);
            $booking->setAttribute('order_sponsor_discount_court', (float) $totalSponsorDiscount);
            $booking->setAttribute('order_sponsor_hours_consumed', (float) $totalSponsorHours);

            // Load order dan data transaksi pembayaran riil
            $order = Order::with(['payments' => function ($q) {
                $q->latest();
            }])->where('order_number', $booking->order_id)
               ->orWhere('id', $booking->order_id)
               ->first();

            if ($order) {
                $booking->setAttribute('order_grand_total', (float) $order->grand_total);
                // Label metode bayar dari pembayaran yang SUDAH lunas — tagihan selisih yang masih menunggu
                // tidak boleh membuat invoice menampilkan "MENUNGGU_PEMBAYARAN" sebagai metode bayar.
                $latestPayment = $order->payments->firstWhere('status', 'SUCCESS') ?? $order->payments->first();

                // Rincian selisih reschedule untuk invoice (lunas / menunggu) + selisih yang hangus.
                $booking->setAttribute('reschedule_charges', $order->payments
                    ->filter(fn ($p) => (($p->payload_log['type'] ?? null) === 'RESCHEDULE_PRICE_DELTA') && in_array($p->status, ['SUCCESS', 'PENDING'], true))
                    ->sortBy('created_at')
                    ->map(fn ($p) => [
                        'amount' => (float) $p->amount,
                        'status' => $p->status,
                        'method_label' => $p->status === 'SUCCESS' ? $this->formatPaymentMethodLabel($p->payment_method, $p->payload_log) : null,
                        'schedule_before' => $p->payload_log['schedule_before'] ?? null,
                        'date' => $p->created_at?->toIso8601String(),
                    ])->values());
                $booking->setAttribute('order_reschedule_forfeited', (float) $orderBookings->sum('reschedule_forfeited_amount'));
                $booking->setAttribute('order_voucher', $this->orderVoucherInfo($order));
                // DOKU SNAP (Modul 27b): teks QR / nomor VA tagihan yang masih menunggu — halaman bayar kita sendiri.
                $booking->setAttribute('pay_display', \App\Services\Payment\Doku\Snap\DokuSnapService::display(
                    $order->payments->where('status', 'PENDING')->where('payment_gateway', 'DOKU')->sortByDesc('created_at')->first()
                ));
                $rawMethod = $latestPayment?->payment_method;
                $methodLabel = $this->formatPaymentMethodLabel($rawMethod, $latestPayment?->payload_log);

                $totalPaid = (float) $order->payments->where('status', 'SUCCESS')->sum('amount');
                // Tagihan milik booking INI (order bisa berisi beberapa booking dengan tagihan selisih masing-masing).
                $booking->setRelation('order', $order);
                $pendingSupplementalPayment = $this->pendingBillForBooking($booking);
                $unpaidDelta = $pendingSupplementalPayment
                    ? (float) $pendingSupplementalPayment->amount
                    : (in_array($booking->status, ['LOCKED', 'PENDING_PAYMENT', 'PENDING'], true) ? max(0, (float) $order->grand_total - $totalPaid) : 0.0);

                $booking->setAttribute('order', $order);
                $booking->setAttribute('payment_method', $rawMethod);
                $booking->setAttribute('payment_method_label', $methodLabel);
                $booking->setAttribute('total_paid', $totalPaid);
                // Lunas walau Rp0 (voucher corporate / kuota member / voucher 100%) — lihat myBookings().
                $booking->setAttribute('was_paid', $order->payments->contains('status', 'SUCCESS'));
                $booking->setAttribute('unpaid_delta', $unpaidDelta);
                // Selisih reschedule juga untuk booking yang dulu 100% ditanggung kuota member / voucher (dibayar Rp0).
                $isRescheduleBill = $pendingSupplementalPayment
                    && (($pendingSupplementalPayment->payload_log['type'] ?? null) === 'RESCHEDULE_PRICE_DELTA' || (int) $booking->reschedule_count > 0);
                $booking->setAttribute('has_pending_delta', $unpaidDelta > 0 && ($totalPaid > 0 || $isRescheduleBill));
                $booking->setAttribute('pending_supplemental_id', $pendingSupplementalPayment?->id);
                // Pilihan bayar selisih yang dicatat resepsionis: CASHIER (di kasir saat datang) / ONLINE (Midtrans).
                $booking->setAttribute('pending_delta_channel', $pendingSupplementalPayment?->payload_log['preferred_channel'] ?? null);
            }
        }

        // Modul 21: hasil pengajuan refund booking ini (menunggu / dikembalikan / dijadikan voucher saldo).
        $refund = \App\Models\Pos\Refund::with('voucher:id,refund_id,code,balance,valid_until')
            ->where('padel_booking_id', $booking->id)->latest()->first();
        $booking->setAttribute('refund_info', $refund ? [
            'status' => $refund->status,
            'amount' => (float) $refund->refund_amount,
            'voucher_code' => $refund->voucher?->code,
            'voucher_balance' => $refund->voucher ? (float) $refund->voucher->balance : null,
            'voucher_valid_until' => $refund->voucher?->valid_until?->timezone('Asia/Jakarta')->format('d M Y'),
        ] : null);

        return $booking;
    }

    /**
     * Voucher yang memotong order ini, untuk rincian invoice. Potongan diambil dari order (nilai saat bayar); jenis &
     * besaran potongan dari voucher — keduanya dikunci setelah promo dipakai (PromoVoucherService), jadi tetap cocok.
     */
    protected function orderVoucherInfo(Order $order): ?array
    {
        if (! $order->voucher_code || (float) $order->discount_amount <= 0) {
            return null;
        }

        $voucher = \App\Models\Pos\Voucher::where('code', $order->voucher_code)->first();
        $isCredit = (bool) $voucher?->isCredit();

        return [
            'code' => $order->voucher_code,
            'discount' => (float) $order->discount_amount,
            'is_credit' => $isCredit,
            'name' => $voucher && ! $isCredit ? $voucher->name : null,
            'type' => $isCredit ? null : $voucher?->discount_type,
            'value' => $voucher && ! $isCredit ? (float) $voucher->discount_value : null,
            'max_discount' => $voucher && ! $isCredit && $voucher->max_discount_amount !== null ? (float) $voucher->max_discount_amount : null,
        ];
    }
}
