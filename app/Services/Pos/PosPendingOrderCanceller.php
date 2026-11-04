<?php

namespace App\Services\Pos;

use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelBookingEquipment;
use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Services\Membership\MembershipOnlinePaymentService;
use App\Services\Padel\PadelBookingService;
use Illuminate\Support\Facades\DB;

/**
 * Bagian bersama "Bayar Otomatis" kasir (Midtrans & DOKU): QR yang tidak dibayar → tagihan ditutup, order batal, slot
 * lapangan dilepas, kuota member / stok bola dikembalikan, kartu member batal. Dipanggil SETELAH gateway-nya dipastikan
 * belum menerima uang (PosMidtransQrisService / DokuPosQrisService).
 */
class PosPendingOrderCanceller
{
    /** @return string 'PAID' kalau tagihan ternyata sudah lunas, selain itu 'CANCELLED'. */
    public static function cancel(Payment $payment, string $closedBy): string
    {
        $bookingService = app(PadelBookingService::class);
        $released = [];

        $result = DB::transaction(function () use ($payment, $closedBy, $bookingService, &$released) {
            $order = Order::whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status === 'SUCCESS') {
                return 'PAID';
            }
            if ($payment->status === 'PENDING') {
                $log = is_array($payment->payload_log) ? $payment->payload_log : [];
                $log['closed_by'] = $closedBy;
                $payment->update(['status' => 'FAILED', 'payload_log' => $log]);
            }

            if (Payment::where('order_id', $order->id)->where('status', 'SUCCESS')->exists()) {
                return 'CANCELLED';
            }

            $order->update(['payment_status' => 'CANCELLED']);
            MembershipOnlinePaymentService::cancelForCancelledOrder($order);

            PadelBooking::where('order_id', $order->id)
                ->whereIn('status', ['PENDING_PAYMENT', 'LOCKED', 'PENDING'])
                ->lockForUpdate()
                ->get()
                ->each(function (PadelBooking $booking) use ($bookingService, &$released) {
                    $bookingService->reverseBookingBenefits($booking);
                    $booking->update(['status' => 'CANCELLED']);
                    $released[] = $booking;
                });

            self::restoreStock($order, $payment);

            return 'CANCELLED';
        });

        // Setelah pembatalan tersimpan (cache tidak ikut di-rollback): slotnya langsung bisa dipakai lagi — kasir yang
        // ganti ke EDC / customer online tidak mendapat "sedang di-hold pemain lain" sampai kunci kedaluwarsa.
        foreach ($released as $booking) {
            $bookingService->releaseSlotCacheLocks($booking);
        }

        return $result;
    }

    /**
     * Stok yang dipotong saat QR kasir dibuat (sebelum dibayar) dikembalikan karena tagihannya batal / kedaluwarsa.
     * Dipakai pembatalan dari layar kasir & jalur DOKU (cancel di atas) dan notifikasi expire Midtrans
     * (PaymentWebhookController) — gateway mana pun, QR yang tidak dibayar tidak memotong stok. Idempoten: baris alat
     * booking ditandai stock_deducted_at = null, penjualan Pro Shop ditandai payload_log.stock_restored_at, merchandise
     * dicek dari Kartu Stok. Wajib dipanggil di dalam transaksi dengan order & tagihan terkunci.
     */
    public static function restoreStock(Order $order, Payment $payment): void
    {
        // Bola (habis pakai) dipotong saat checkout / tambah alat → kembalikan stoknya.
        PadelBookingEquipment::with('equipment')
            ->where('order_id', $order->id)
            ->whereNotNull('stock_deducted_at')
            ->get()
            ->each(function (PadelBookingEquipment $row) {
                $row->equipment?->increment('stock_quantity', (int) $row->quantity);
                $row->update(['stock_deducted_at' => null]);
                // Raket yang sempat tercatat dipinjam (tambah alat ke booking yang sudah check-in) tidak jadi diserahkan.
                \App\Models\Padel\EquipmentLoan::where('booking_equipment_id', $row->id)->whereNull('returned_at')->delete();
            });

        // Penjualan Kasir Pro Shop: stok dipotong saat QR dibuat (tanpa baris booking) → kembalikan sekali saja.
        // Alat dari payload_log, merchandise dari Kartu Stok (MerchStockService juga mencegah dobel).
        $log = is_array($payment->payload_log) ? $payment->payload_log : [];
        if (($log['source'] ?? null) === ProShopSaleService::SOURCE && empty($log['stock_restored_at'])) {
            foreach ((array) ($log['equipment_sale'] ?? []) as $row) {
                \App\Models\Padel\CourtEquipment::whereKey($row['equipment_id'] ?? null)->increment('stock_quantity', max(0, (int) ($row['quantity'] ?? 0)));
            }
            app(\App\Services\Merch\MerchStockService::class)->restoreCancelledSale($order);
            $log['stock_restored_at'] = now()->toIso8601String();
            $payment->update(['payload_log' => $log]);
        }
    }
}
