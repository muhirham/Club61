<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Controller;
use App\Models\Padel\PadelBooking;
use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Services\Payment\PaymentManager;
use App\Services\Payment\PaymentOrchestratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    protected PaymentManager $paymentManager;

    public function __construct(PaymentManager $paymentManager)
    {
        $this->paymentManager = $paymentManager;
    }

    /**
     * Handler webhook universal untuk driver pembayaran (midtrans, mock).
     *
     * Error tak terduga (deadlock, DB putus, bug) dibalas 503, bukan 500: Midtrans hanya mengulang notifikasi
     * 1× untuk 500, tapi 4× untuk 503 (2m, 10m, 30m, 1.5j). Aman diulang karena pelunasan idempoten.
     */
    public function handle(string $driver, Request $request): JsonResponse
    {
        try {
            return $this->process($driver, $request);
        } catch (\Throwable $e) {
            report($e);
            Log::error("[ALERT] Webhook {$driver} gagal diproses, dibalas 503 supaya dikirim ulang: ".$e->getMessage(), [
                'order_id' => $request->input('order_id'),
                'transaction_status' => $request->input('transaction_status'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Notifikasi belum bisa diproses, silakan kirim ulang.',
            ], 503);
        }
    }

    private function process(string $driver, Request $request): JsonResponse
    {
        if (strtolower($driver) === 'mock' && app()->environment('production')) {
            return response()->json([
                'success' => false,
                'message' => 'Driver simulasi mock dinonaktifkan pada environment production.',
            ], 403);
        }

        try {
            $gateway = $this->paymentManager->driver($driver);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 404);
        }

        $result = $gateway->verifyWebhook($request);

        if (! $result['is_valid']) {
            // Bukan seluruh payload: signature_key (kalau server key salah set, signature notifikasi ASLI ikut tercatat),
            // nomor VA & data kartu tidak masuk log.
            Log::warning("[ALERT] PAYMENT WEBHOOK SPOOFING DETECTED [{$driver}]: {$result['message']}", [
                'ip' => $request->ip(),
                'order_id' => mb_substr((string) $request->input('order_id'), 0, 100),
                'status_code' => mb_substr((string) $request->input('status_code'), 0, 10),
                'transaction_status' => mb_substr((string) $request->input('transaction_status'), 0, 30),
            ]);

            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 400);
        }

        $incomingOrderId = $result['order_id'];
        $realOrderId = explode('_', $incomingOrderId)[0];
        $status = $result['status'];
        $grossAmount = $result['gross_amount'] ?? 0;
        $paymentType = $request->input('payment_type', strtoupper($driver));
        // signature_key tidak pernah disimpan: kalau bocor, bisa dipakai ulang untuk memalsukan notifikasi.
        $safePayload = \Illuminate\Support\Arr::except($request->all(), ['signature_key']);

        // Signature Midtrans = SHA512(order_id + status_code + gross_amount + server key) — TIDAK mencakup
        // transaction_status. Signature notifikasi lain (expire / cancel) untuk order yang sama bisa dipakai ulang
        // dengan transaction_status=settlement. Karena itu "lunas" WAJIB dikonfirmasi ke Status API Midtrans
        // (rekomendasi Midtrans) sebelum order dilunasi, dan data dari Status API yang dipakai.
        if ($status === 'PAID' && strtolower($driver) === 'midtrans' && config('services.midtrans.verify_webhook_with_status_api', true)) {
            $confirmed = app(\App\Services\Payment\MidtransService::class)->getTransactionStatus($incomingOrderId);
            if ($confirmed === null) {
                // Midtrans tidak bisa dihubungi → 503 (Midtrans kirim ulang; rekonsiliasi berkala juga menangkapnya).
                throw new \RuntimeException("Status API Midtrans tidak bisa dihubungi untuk konfirmasi pelunasan [{$incomingOrderId}].");
            }

            $confirmedStatus = \App\Services\Payment\MidtransService::normalizeStatus((string) ($confirmed['transaction_status'] ?? ''), $confirmed['fraud_status'] ?? null);
            // Status API sesaat masih "pending" (belum sinkron dengan notifikasi) → 503 supaya Midtrans mengirim ulang.
            // Hanya status final yang bertentangan (expire / cancel / tidak dikenal) yang diabaikan sebagai palsu.
            if (($confirmed['order_id'] ?? null) === $incomingOrderId && in_array($confirmedStatus, ['PENDING', 'CHALLENGE'], true)) {
                throw new \RuntimeException("Status API Midtrans masih {$confirmedStatus} untuk [{$incomingOrderId}] — minta notifikasi dikirim ulang.");
            }
            if (($confirmed['order_id'] ?? null) !== $incomingOrderId || $confirmedStatus !== 'PAID') {
                Log::warning("[ALERT] Notifikasi 'lunas' DITOLAK: Status API Midtrans menyatakan {$confirmedStatus} untuk [{$incomingOrderId}] — kemungkinan notifikasi dipalsukan / diputar ulang.", [
                    'ip' => $request->ip(),
                    'claimed_transaction_status' => $request->input('transaction_status'),
                    'status_api_transaction_status' => $confirmed['transaction_status'] ?? null,
                ]);

                return $this->acknowledged($driver, $incomingOrderId, 'IGNORED');
            }

            $grossAmount = (float) ($confirmed['gross_amount'] ?? $grossAmount);
            $paymentType = $confirmed['payment_type'] ?? $paymentType;
            $safePayload['confirmed_via'] = 'MIDTRANS_STATUS_API';
        }

        Log::info("Payment Webhook verified for Driver [{$driver}], Order {$incomingOrderId} (Real: {$realOrderId}): Status = {$status}");

        // 1. Temukan Order dari Database (Universal Order Lookup)
        $order = Order::where('order_number', $realOrderId)
            ->orWhere('id', $realOrderId)
            ->orWhere('order_number', $incomingOrderId)
            ->first();

        if (! $order) {
            $matchedPayment = Payment::where('transaction_id', $incomingOrderId)
                ->orWhere('transaction_id', $realOrderId)
                ->first();
            if ($matchedPayment && $matchedPayment->order) {
                $order = $matchedPayment->order;
            }
        }

        // 2. Fallback untuk transaksi lama pra-migrasi jika record Order belum ada
        if (! $order) {
            $bookings = PadelBooking::where('order_id', $incomingOrderId)
                ->orWhere('order_id', $realOrderId)
                ->get();

            if ($bookings->isEmpty()) {
                $bookingIds = Cache::get("order_bookings:{$incomingOrderId}") ?? Cache::get("order_bookings:{$realOrderId}");
                if (! empty($bookingIds) && is_array($bookingIds)) {
                    $bookings = PadelBooking::whereIn('id', $bookingIds)->get();
                } else {
                    $bookings = PadelBooking::where('booking_code', $incomingOrderId)
                        ->orWhere('booking_code', $realOrderId)
                        ->get();
                }
            }

            if ($bookings->isNotEmpty()) {
                $primaryBooking = $bookings->first();
                $order = Order::create([
                    'order_number' => $realOrderId,
                    'user_id' => $primaryBooking->user_id,
                    'order_type' => 'ONLINE_BOOKING',
                    'subtotal' => $bookings->sum('total_amount'),
                    'grand_total' => (float) ($grossAmount ?: $bookings->sum('total_amount')),
                    'voucher_code' => Cache::get("order_voucher:{$realOrderId}") ?? Cache::get("order_voucher:{$incomingOrderId}"),
                    'payment_status' => 'UNPAID',
                ]);

                foreach ($bookings as $b) {
                    $b->update(['order_id' => $order->id]);
                }
            }
        }

        // Notifikasi sah (tanda tangan valid, "lunas" dikonfirmasi Status API) tapi tidak ada order yang cocok = uang masuk
        // tanpa catatan. Rekonsiliasi berkala hanya memeriksa order yang ada, jadi tidak akan menemukannya.
        if (! $order && $status === 'PAID') {
            Log::critical("[ALERT] Pembayaran {$driver} LUNAS untuk order yang tidak dikenal [{$incomingOrderId}] — uang masuk tanpa order, cek manual.", [
                'gross_amount' => $grossAmount,
                'payment_type' => $paymentType,
            ]);
        }

        if ($order) {
            $orchestrator = app(\App\Services\Payment\PaymentOrchestratorService::class);

            if ($status === 'PAID') {
                // Nominal yang dibayar wajib sama dengan nominal yang ditagihkan untuk sesi ini (dibandingkan dengan
                // catatan sesi / tagihan PEMILIK sesi, bukan grand_total order). Beda → tidak dilunasi + alert KRITIS,
                // sama dengan DOKU. Dulu hanya dicatat lalu tetap dilunasi (booking & membership ikut aktif).
                // Dibalas 200: notifikasi ulang tidak mengubah nominalnya.
                if (! \App\Services\Payment\SnapSessionGuard::amountMatches($order, $incomingOrderId, (float) $grossAmount, 'WEBHOOK_'.strtoupper($driver))) {
                    return $this->acknowledged($driver, $incomingOrderId, 'AMOUNT_MISMATCH');
                }

                $orchestrator->markOrderAsPaid($order, [
                    'payment_gateway' => strtoupper($driver),
                    'transaction_id' => $incomingOrderId,
                    'payment_method' => strtoupper((string) $paymentType),
                    'amount' => (float) ($grossAmount ?: $order->grand_total),
                    'payload_log' => $safePayload,
                ]);
            } elseif ($status === 'CANCELLED') {
                $this->handleGatewayCancellation($order, $incomingOrderId, (string) $request->input('transaction_status', ''), $safePayload);
            } elseif ($status === 'PENDING' && strtolower($driver) === 'midtrans') {
                // Customer memakai sesi yang seharusnya sudah mati (popup lama di tab lain, sesi sebelum ganti metode,
                // atau tagihan yang sudah lunas) → VA / QR barunya langsung ditutup sebelum sempat dibayar.
                app(\App\Services\Payment\SnapSessionGuard::class)->closeIfStale($order, $incomingOrderId);
            } elseif (in_array($rawStatus = (string) $request->input('transaction_status', ''), ['refund', 'partial_refund', 'chargeback', 'partial_chargeback'], true)) {
                // Uang ditarik kembali di sisi Midtrans/bank (refund dari dashboard atau chargeback kartu) — sistem
                // TIDAK otomatis membatalkan booking/membership, tapi wajib terlihat supaya ditindaklanjuti manual.
                Log::critical("[ALERT] Midtrans melaporkan {$rawStatus} untuk order [{$order->order_number}] ({$incomingOrderId}) — cek & tindak lanjuti manual.", [
                    'order_id' => $order->id,
                    'gross_amount' => $grossAmount,
                    'refund_amount' => $request->input('refund_amount'),
                    'payment_type' => $paymentType,
                ]);
            }
        }

        return $this->acknowledged($driver, $incomingOrderId, $status);
    }

    /**
     * Notifikasi expire/cancel/deny untuk SATU sesi pembayaran. Dulu notifikasi ini membatalkan SELURUH order:
     * order PAID jadi CANCELLED, semua tagihan PENDING (termasuk tagihan kasir) jadi FAILED, dan booking LOCKED
     * hasil reschedule ikut CANCELLED — cukup dengan customer membuka link bayar selisih lalu membiarkannya
     * kedaluwarsa, booking yang sudah dibayar lunas hilang dan slotnya lepas.
     *
     * Sekarang hanya tagihan PEMILIK sesi itu yang disentuh, dan hanya kalau sesi itu adalah sesi terakhirnya.
     */
    private function handleGatewayCancellation(Order $order, string $gatewayOrderId, string $rawStatus, array $payload): void
    {
        // deny bukan status akhir: Midtrans mengizinkan customer mencoba kartu lagi dengan order_id yang sama.
        if (strtolower($rawStatus) === 'deny') {
            Log::info("Webhook deny untuk [{$gatewayOrderId}] diabaikan (bukan status akhir, customer bisa mencoba lagi).");

            return;
        }

        DB::transaction(function () use ($order, $gatewayOrderId, $rawStatus, $payload) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $bill = Payment::where('order_id', $order->id)->lockForUpdate()->get()
                ->first(fn (Payment $p) => PaymentOrchestratorService::ownsGatewayId($p, $gatewayOrderId)
                    || ($p->payment_gateway === 'MIDTRANS' && $gatewayOrderId === $order->order_number && $p->transaction_id === $order->order_number));

            if (! $bill || $bill->status !== 'PENDING') {
                Log::info("Webhook {$rawStatus} untuk [{$gatewayOrderId}] diabaikan: tidak ada tagihan PENDING pemilik sesi ini.");

                return;
            }

            $log = is_array($bill->payload_log) ? $bill->payload_log : [];

            // Sesi lama yang kedaluwarsa padahal customer sudah membuka sesi baru untuk tagihan yang sama → abaikan.
            $latestSession = $log['midtrans_order_id'] ?? $bill->transaction_id;
            if ($latestSession !== $gatewayOrderId) {
                Log::info("Webhook {$rawStatus} untuk sesi lama [{$gatewayOrderId}] diabaikan; sesi terbaru tagihan ini [{$latestSession}].");

                return;
            }

            // Tagihan selisih reschedule: customer tetap berutang & booking-nya sudah dibayar sebagian. Kembalikan
            // jadi tagihan terbuka (bisa dibayar ulang via invoice / di kasir) — booking & order tidak disentuh.
            if (($log['type'] ?? null) === 'RESCHEDULE_PRICE_DELTA') {
                $log['snap_session_closed_by'] = 'MIDTRANS_WEBHOOK_'.strtoupper($rawStatus);
                $bill->update(['payment_gateway' => 'CASHIER_POS', 'payload_log' => $log]);

                return;
            }

            // Batas bayar booking belum lewat → jangan tutup dulu. Sesi bayar ulang dibuat berakhir sedikit SEBELUM
            // batas bayar, sementara VA dari sesi sebelumnya bisa masih berlaku sampai batas itu. Pembersih slot
            // menanyakan semua sesi ke Midtrans saat batas bayar + jeda lewat, lalu menutupnya dengan benar.
            $deadlineNotPassed = $order->padelBookings
                ->contains(fn ($b) => in_array($b->status, ['PENDING_PAYMENT', 'PENDING'], true) && $b->expires_at?->isFuture());
            if ($deadlineNotPassed) {
                Log::info("Webhook {$rawStatus} untuk [{$gatewayOrderId}] ditunda: batas bayar booking belum lewat, sesi lain bisa masih dibayar.");

                return;
            }

            $log['closed_by'] = 'MIDTRANS_WEBHOOK_'.strtoupper($rawStatus);
            $log['gateway_notification'] = $payload;
            $bill->update(['status' => 'FAILED', 'payload_log' => $log]);

            // Order yang SUDAH menerima uang tidak pernah dibatalkan oleh notifikasi gateway.
            if (Payment::where('order_id', $order->id)->where('status', 'SUCCESS')->exists()) {
                return;
            }

            $order->update(['payment_status' => 'CANCELLED']);

            // QR kasir (Bayar Otomatis) yang kedaluwarsa: stok bola / merchandise yang dipotong saat QR dibuat dikembalikan,
            // sama dengan pembatalan dari layar kasir & jalur DOKU. Dulu stoknya tertahan selamanya kalau notifikasi ini
            // datang lebih dulu (layar kasir tidak memeriksa lagi & pembersih hanya mengambil order yang belum dibatalkan).
            if (isset($log['pos_qris'])) {
                \App\Services\Pos\PosPendingOrderCanceller::restoreStock($order, $bill);
            }

            // Kartu membership yang menunggu pembayaran order ini ikut batal (dulu "Menunggu Pembayaran" selamanya).
            \App\Services\Membership\MembershipOnlinePaymentService::cancelForCancelledOrder($order);

            // Dikunci & disaring status DI DATABASE: pembersih slot bisa menghanguskan booking yang sama di detik yang
            // sama — dulu data booking lama dipakai, jadi jam kuota member / voucher sponsor dikembalikan dua kali.
            // Booking hasil reschedule tidak pernah ikut dibatalkan di sini (selalu sudah dibayar sebagian).
            $bookings = PadelBooking::whereIn('id', $order->padelBookings()->pluck('id'))
                ->whereIn('status', ['PENDING_PAYMENT', 'LOCKED', 'PENDING'])
                ->where('reschedule_count', 0)
                ->lockForUpdate()
                ->get();
            foreach ($bookings as $booking) {
                // Jam kuota member / voucher sponsor yang dipotong saat checkout dikembalikan (dulu hilang).
                app(\App\Services\Padel\PadelBookingService::class)->reverseBookingBenefits($booking);
                $booking->update(['status' => 'CANCELLED']);
            }
        });
    }

    private function acknowledged(string $driver, string $incomingOrderId, string $status): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => "Webhook {$driver} diproses dengan sukses.",
            'data' => [
                'driver' => $driver,
                'order_id' => $incomingOrderId,
                'status' => $status,
            ],
        ]);
    }
}
