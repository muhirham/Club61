<?php

namespace App\Services\Payment\Doku;

use App\Filament\Pages\BookOfflineCourt;
use App\Filament\Pages\JualMembership;
use App\Filament\Pages\KasirProShop;
use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Models\Pos\PosCashierShift;
use App\Models\User;
use App\Services\Padel\BookingTimeService;
use App\Services\Payment\OnlineGatewayRouter;
use App\Services\Payment\OnlinePaymentMethodService;
use App\Services\Pos\PosPendingOrderCanceller;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Modul 27 Tahap 3 — "Bayar Otomatis" di layar kasir lewat DOKU Checkout (versi DOKU dari PosMidtransQrisService;
 * pemilihnya App\Services\Pos\PosAutoPayment).
 *
 * Tampilan: mode pop-up resmi DOKU Checkout = halaman `payment.url` + "?view=iframe" di dalam iframe (persis yang
 * dilakukan jokul-checkout-1.0.0.js). Kasir memakai iframe sendiri di dalam kartu kasir — script DOKU TIDAK dimuat di
 * halaman admin (script itu menimpa window.onclick, menyusun HTML dari URL lewat innerHTML, dan berjalan dengan akses
 * penuh ke sesi kasir). Iframe diberi sandbox tanpa allow-top-navigation: halaman DOKU tidak bisa memindahkan layar kasir.
 *
 * DOKU SNAP aktif (Modul 27b) untuk QRIS / VA BCA → tanpa halaman DOKU sama sekali: QR / nomor VA digambar langsung
 * di kartu kasir (present()['snap']).
 *
 * Tagihan: order UNPAID + tagihan PENDING gateway DOKU yang terikat shift kasir (pos_shift_id). Sesi dicatat dengan
 * format DokuSessionGuard, jadi webhook DOKU, rekonsiliasi, dan pembayaran ganda memakai jalur online yang sudah ada.
 * Pelunasan lewat PaymentOrchestratorService (pos_shift_id tidak ditimpa → uang masuk rekap shift kasir penerima).
 */
class DokuPosQrisService
{
    /** Penanda internal di qris_details.provider (rekap shift & struk): QR dinamis kasir lewat DOKU. */
    public const PROVIDER = 'DOKU_QRIS';

    public const PAID = 'PAID';

    public const PENDING = 'PENDING';

    public const EXPIRED = 'EXPIRED';

    public const CANCELLED = 'CANCELLED';

    public function __construct(
        protected DokuCheckoutService $checkout,
        protected DokuSessionGuard $guard,
        protected BookingTimeService $time,
    ) {}

    /**
     * Buat tagihan DOKU untuk order kasir yang baru dibuat (di DALAM transaksi checkout — gagal = checkout di-rollback).
     */
    public function open(Order $order, string $counter, User $cashier, array $payloadLog = [], string $method = 'QRIS'): array
    {
        $shift = PosCashierShift::getActiveShift($counter);
        if (! $shift) {
            throw new HttpException(422, "Tidak ada shift kasir yang aktif untuk loket [{$counter}]. Silakan buka shift terlebih dahulu.");
        }

        $amount = (float) $order->grand_total;
        if ($amount <= 0) {
            throw new HttpException(422, 'Tagihan Rp 0 tidak perlu dibayar otomatis.');
        }

        $methods = app(OnlinePaymentMethodService::class);
        $method = $methods->assertPosSelectable(\App\Services\Pos\PosMidtransQrisService::resolveMethod($method, $amount) ?? $method, $amount);
        $channels = $methods->channels($method, OnlineGatewayRouter::DOKU) ?? [];
        if ($channels === []) {
            throw new HttpException(422, 'Metode '.($methods->label($method) ?? $method).' belum punya kanal DOKU. Atur di menu Metode Pembayaran Online.');
        }

        $gross = (int) round($amount);
        $invoice = DokuCheckoutService::newInvoiceNumber($order->order_number);
        $customer = array_filter([
            'name' => $order->user?->name ?? $order->customer_name,
            'email' => $order->user?->email,
            'phone' => $order->user?->phone,
        ]);

        // Modul 27b: QRIS / VA BCA lewat DOKU SNAP — QR / nomor VA tampil langsung di kartu kasir (tanpa halaman DOKU).
        $snapService = app(Snap\DokuSnapService::class);
        if ($snapService->productFor($channels) !== null) {
            $snap = $snapService->create($invoice, $gross, $channels, $customer, $this->time->paymentWindowMinutes());
            $log = array_merge($payloadLog, [
                'cashier_id' => $cashier->id,
                'cashier_name' => $cashier->name,
                'counter' => $counter,
                'pos_qris' => ['gateway' => OnlineGatewayRouter::DOKU, 'method' => $method, 'expires_at' => $snap['expires_at'], 'is_mock' => false, 'api' => Snap\DokuSnapService::API],
            ], $method === 'QRIS' ? ['qris_details' => ['provider' => self::PROVIDER, 'rrn' => null, 'sender_name' => null]] : []);

            return $this->storeBill($order, $shift, $invoice, $method, $amount, DokuSessionGuard::recordSnap($log, $invoice, $method, $gross, $snap), $cashier);
        }

        $created = $this->checkout->createSession([
            'invoice' => $invoice,
            'amount' => $gross,
            'channels' => $channels,
            'items' => [['id' => $order->order_number, 'name' => 'Club 61 '.$order->order_number, 'price' => $gross, 'quantity' => 1]],
            // Order F&B tanpa akun: hanya nama (kalau ada) — tidak ada data pribadi yang dikarang.
            'customer' => $customer,
            'due_minutes' => $this->time->paymentWindowMinutes(),
            'callback_url' => self::returnUrl($payloadLog['source'] ?? null),
            'auto_redirect' => false,
        ]);

        $log = array_merge($payloadLog, [
            'cashier_id' => $cashier->id,
            'cashier_name' => $cashier->name,
            'counter' => $counter,
            // Data kartu kasir: kartu bisa dibuka lagi setelah halaman kasir di-refresh / tertutup.
            'pos_qris' => [
                'gateway' => OnlineGatewayRouter::DOKU,
                'method' => $method,
                'expires_at' => $created['expires_at']->toIso8601String(),
                'is_mock' => false,
                'payment_url' => $created['payment_url'],
            ],
        ], $method === 'QRIS' ? ['qris_details' => ['provider' => self::PROVIDER, 'rrn' => null, 'sender_name' => null]] : []);
        $log = DokuSessionGuard::record($log, $invoice, $method, $gross, $created['payment_url'], $created['token_id'], $created['request_id'], $created['expires_at']);

        return $this->storeBill($order, $shift, $invoice, $method, $amount, $log, $cashier);
    }

    /** Tagihan PENDING yang terikat shift kasir + data kartu kasir. */
    private function storeBill(Order $order, PosCashierShift $shift, string $invoice, string $method, float $amount, array $log, User $cashier): array
    {
        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_gateway' => OnlineGatewayRouter::DOKU,
            'transaction_id' => $invoice,
            'payment_method' => $method,
            'amount' => $amount,
            'status' => 'PENDING',
            'pos_shift_id' => $shift->id,
            'payload_log' => $log,
        ]);

        $order->update(['pos_shift_id' => $shift->id, 'cashier_id' => $order->cashier_id ?? $cashier->id]);

        return self::present($payment->setRelation('order', $order));
    }

    /** Data kartu kasir dari tagihan tersimpan (saat dibuat & saat dilanjutkan setelah halaman di-refresh). */
    public static function present(Payment $payment): array
    {
        $pos = $payment->payload_log['pos_qris'] ?? [];
        $method = strtoupper((string) ($pos['method'] ?? 'QRIS'));

        return [
            'gateway' => OnlineGatewayRouter::DOKU,
            'payment_id' => $payment->id,
            'order_id' => $payment->order_id,
            'order_number' => $payment->order?->order_number,
            'amount' => (float) $payment->amount,
            'method' => $method,
            'method_label' => app(OnlinePaymentMethodService::class)->label($method) ?? $method,
            'snap_token' => null,
            // Hanya URL halaman DOKU https (dicek ulang saat ditampilkan, bukan hanya saat dibuat).
            'frame_url' => DokuCheckoutService::popupUrl($pos['payment_url'] ?? null),
            'redirect_url' => DokuCheckoutService::isDokuUrl((string) ($pos['payment_url'] ?? '')) ? $pos['payment_url'] : null,
            'expires_at' => $pos['expires_at'] ?? now()->toIso8601String(),
            'is_mock' => false,
            'sandbox' => ! app(DokuClient::class)->isProduction(),
            // DOKU SNAP: QR / nomor VA digambar langsung di kartu kasir (frame_url kosong).
            'snap' => Snap\DokuSnapService::display($payment),
        ];
    }

    /** Tagihan Bayar Otomatis DOKU milik kasir ini di loket & layar ini yang masih menunggu. */
    public function resumeFor(string $counter, string $source, ?string $cashierId): ?array
    {
        if (! $cashierId) {
            return null;
        }

        $payment = Payment::with('order')
            ->where('status', 'PENDING')
            ->where('payment_gateway', OnlineGatewayRouter::DOKU)
            ->whereNotNull('pos_shift_id')
            ->latest()
            ->limit(20)
            ->get()
            ->first(fn (Payment $p) => isset($p->payload_log['pos_qris'])
                && ($p->payload_log['counter'] ?? null) === $counter
                && ($p->payload_log['source'] ?? null) === $source
                && ($p->payload_log['cashier_id'] ?? null) === $cashierId);

        return $payment ? self::present($payment) : null;
    }

    /**
     * Status untuk polling layar kasir: PAID / PENDING / EXPIRED / CANCELLED. Lunas biasanya dari webhook DOKU; kalau
     * telat, API status DOKU ditanya langsung (hasil disimpan beberapa detik). Lewat batas waktu → dibatalkan.
     */
    public function status(string $paymentId): string
    {
        $payment = Payment::with('order')->find($paymentId);
        if (! $payment || ! $payment->order) {
            return self::CANCELLED;
        }
        if ($payment->status === 'SUCCESS') {
            return self::PAID;
        }
        if ($payment->status !== 'PENDING') {
            return self::CANCELLED;
        }

        $result = app(DokuReconciliationService::class)->reconcileOrder($payment->order, cacheSeconds: 5);
        $current = $payment->fresh()->status;
        if ($result === DokuReconciliationService::PAID || $current === 'SUCCESS') {
            return self::PAID;
        }
        // DOKU sudah menyatakan sesi ini mati (tagihan ditutup rekonsiliasi) → bersihkan order.
        if ($current !== 'PENDING') {
            return $this->cancel($paymentId, 'EXPIRED') === self::PAID ? self::PAID : self::EXPIRED;
        }

        $expiresAt = $payment->payload_log['pos_qris']['expires_at'] ?? null;
        if ($expiresAt && now()->greaterThan(Carbon::parse($expiresAt)->addSeconds(30))) {
            return $this->cancel($paymentId, 'EXPIRED') === self::PAID ? self::PAID : self::EXPIRED;
        }

        return self::PENDING;
    }

    /**
     * Batalkan QR (kasir menekan Batalkan, atau batas waktu lewat). Urutan anti-uang-hilang:
     *   1. cek status DOKU — sudah dibayar → PAID (transaksi diteruskan);
     *   2. sesi DOKU dibatalkan & DIBUKTIKAN tertutup lewat API status (DokuSessionGuard::closeAll);
     *   3. baru order dibatalkan & slot dilepas.
     * Kasir membatalkan tapi DOKU belum bisa dipastikan tertutup → PENDING (QR tetap tampil, kasir coba lagi) — kalau
     * order dibatalkan duluan, customer masih bisa membayar QR yang tampil di layar. Batas waktu lewat → tetap dibatalkan
     * (sesi DOKU kedaluwarsa bersamaan); uang yang terlambat masuk dicatat + refund PENDING oleh PaymentOrchestratorService.
     */
    public function cancel(string $paymentId, string $reason = 'CASHIER_CANCEL'): string
    {
        $payment = Payment::with('order')->find($paymentId);
        if (! $payment || ! $payment->order) {
            return self::CANCELLED;
        }
        if ($payment->status === 'SUCCESS') {
            return self::PAID;
        }

        $reconciler = app(DokuReconciliationService::class);
        if ($reconciler->reconcileOrder($payment->order) === DokuReconciliationService::PAID) {
            return self::PAID;
        }

        if ($payment->fresh()->status === 'PENDING') {
            try {
                $closed = $this->guard->closeAll($payment->fresh());
            } catch (HttpException $e) {
                if ($reason === 'CASHIER_CANCEL') {
                    return self::PENDING;
                }
                DokuClient::alert("QR kasir DOKU [{$payment->transaction_id}] kedaluwarsa tapi sesi belum bisa dipastikan tertutup — order tetap dibatalkan.");
                $closed = ['action' => DokuSessionGuard::CREATE];
            }

            if ($closed['action'] === DokuSessionGuard::PAID) {
                $bill = $payment->fresh();
                $session = DokuSessionGuard::session($bill, $closed['session_id']) ?? [];
                if ($reconciler->amountMatches($closed['status'] ?? null, $session, $closed['session_id'])) {
                    $reconciler->settle($payment->order, $closed['session_id'], $closed['status'] ?? [], 'DOKU_STATUS_API');

                    return self::PAID;
                }

                // Nominal janggal ([ALERT] sudah dicatat): jangan dibatalkan diam-diam, jangan dilunasi otomatis.
                return self::PENDING;
            }
        }

        return PosPendingOrderCanceller::cancel($payment->fresh(), 'POS_QRIS_'.$reason) === 'PAID' ? self::PAID : self::CANCELLED;
    }

    /**
     * Pembersih berkala (pos:sweep-auto-payments): QR kasir DOKU yang ditinggal & sudah lewat batas waktu dibatalkan
     * beserta ordernya. Uang yang ternyata sudah masuk tetap dilunasi (cancel() mengecek DOKU dulu).
     */
    public function sweepAbandoned(): int
    {
        $count = 0;

        Payment::with('order')
            ->where('payment_gateway', OnlineGatewayRouter::DOKU)
            ->whereNotNull('pos_shift_id')
            ->whereIn('status', ['PENDING', 'FAILED'])
            ->whereHas('order', fn ($q) => $q->where('payment_status', 'UNPAID'))
            ->where('created_at', '>=', now()->subDays(7))
            ->get()
            ->filter(fn (Payment $p) => isset($p->payload_log['pos_qris']))
            ->filter(function (Payment $p) {
                if ($p->status === 'FAILED') {
                    return true;
                }
                $expiresAt = $p->payload_log['pos_qris']['expires_at'] ?? null;

                return $expiresAt && now()->greaterThan(Carbon::parse($expiresAt)->addMinutes(2));
            })
            ->each(function (Payment $p) use (&$count) {
                try {
                    $this->cancel($p->id, 'EXPIRED');
                    $count++;
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        return $count;
    }

    /** Tujuan tombol "kembali ke merchant" DOKU = layar kasir asal (dibuka di tab kasir yang sama, bukan di iframe). */
    private static function returnUrl(?string $source): string
    {
        try {
            return match ($source) {
                'WALK_IN_OFFLINE' => BookOfflineCourt::getUrl(),
                'MEMBERSHIP_POS' => JualMembership::getUrl(),
                'BOOKING_ADDON', \App\Services\Pos\ProShopSaleService::SOURCE => KasirProShop::getUrl(),
                default => route('pos.index'),
            };
        } catch (\Throwable $e) {
            return url('/');
        }
    }
}
