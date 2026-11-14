<?php

namespace App\Services\Membership;

use App\Models\Membership\UserMembership;
use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Payment\OnlineGatewayRouter;
use App\Services\Payment\OnlinePaymentMethodService;
use App\Services\Payment\PaymentManager;
use App\Services\Payment\PaymentOrchestratorService;
use App\Services\Payment\SnapSessionGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Pembelian membership ONLINE yang belum dibayar: lanjutkan bayar (sesi Midtrans baru untuk order yang sama),
 * batalkan, dan bersihkan pesanan yang ditinggal. Dulu customer yang menutup jendela bayar hanya bisa membeli
 * ulang → order + kartu PENDING baru setiap kali, dan kartu lama "Menunggu Pembayaran" selamanya.
 */
class MembershipOnlinePaymentService
{
    /** Pesanan online yang ditinggal lebih lama dari ini dibatalkan otomatis (sesi Midtrans sudah lama berakhir). */
    public const STALE_HOURS = 24;

    public function __construct(
        protected PaymentManager $paymentManager,
        protected OnlineGatewayRouter $reconciler,
    ) {}

    /** Pembelian online (bukan penjualan kasir) milik user yang masih menunggu pembayaran. */
    public function pendingPurchase(User $user): ?UserMembership
    {
        return $this->pendingQuery()
            ->with('plan')
            ->where('user_id', $user->id)
            ->latest()
            ->first();
    }

    /**
     * Sesi pembayaran Midtrans baru untuk order yang sama — nominal tetap sesuai order (harga terkunci saat dipesan).
     *
     * @return array{order: Order, membership: UserMembership, payment: array, already_paid: bool}
     */
    public function resume(UserMembership $membership, string $paymentMethod, User $user): array
    {
        $order = $this->assertOwnedPending($membership, $user);

        // Uang dari sesi sebelumnya (mis. VA yang ditransfer) bisa sudah masuk tapi webhook-nya belum sampai —
        // cek dulu supaya customer tidak membayar dua kali.
        if ($this->reconciler->reconcileOrder($order) === OnlineGatewayRouter::PAID) {
            return $this->alreadyPaid($membership);
        }

        return DB::transaction(function () use ($membership, $paymentMethod, $user) {
            $order = Order::whereKey($membership->order_id)->lockForUpdate()->firstOrFail();
            $membership = UserMembership::with('plan')->whereKey($membership->id)->lockForUpdate()->firstOrFail();

            if ($order->payment_status === 'PAID') {
                return $this->alreadyPaid($membership);
            }
            if ($order->payment_status !== 'UNPAID' || $membership->status !== 'PENDING_PAYMENT') {
                throw new HttpException(422, \App\Support\SiteLocale::text('membership.order_closed'));
            }

            $grandTotal = (float) $order->grand_total;

            // Satu tagihan = satu sesi yang bisa dibayar: metode sama → sesi yang sama; ganti metode → sesi lama
            // ditutup dulu di gateway-nya (SnapSessionGuard / DokuSessionGuard). Dulu tiap klik membuka sesi baru.
            $bill = Payment::where('order_id', $order->id)->where('status', 'PENDING')->latest()->lockForUpdate()->first();
            $onlineGateway = $this->reconciler->targetGatewayFor($bill);
            $paymentMethod = app(OnlinePaymentMethodService::class)->assertSelectable($paymentMethod, $grandTotal, $onlineGateway);
            $prepared = $this->reconciler->prepareSession($bill, $paymentMethod, $grandTotal, $onlineGateway);
            if ($prepared['action'] === OnlineGatewayRouter::SESSION_PAID) {
                $this->reconciler->settlePreparedSession($order, $prepared);

                return $this->alreadyPaid($membership);
            }
            if ($prepared['action'] === OnlineGatewayRouter::SESSION_REUSE) {
                return [
                    'order' => $order->fresh(),
                    'membership' => $membership->fresh(['balances', 'plan']),
                    'payment' => OnlineGatewayRouter::reusedPayment($prepared),
                    'already_paid' => false,
                ];
            }

            $paymentResult = $this->reconciler->createSession($onlineGateway, [
                'order_number' => $order->order_number,
                'session_id' => $order->order_number.'_'.time().strtoupper(Str::random(4)),
                'amount' => (int) $grandTotal,
                'items' => self::itemDetails($order, $membership->plan?->name ?? 'Membership'),
                'customer' => ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone ?? '081261617233'],
                'method' => $paymentMethod,
                'callback_url' => route('customer.invoice', ['membership_id' => $membership->id]),
            ]);
            $sessionId = $paymentResult['session_id'];

            // Sesi baru dicatat PERMANEN di tagihan order — webhook / rekonsiliasi mencocokkan pembayaran ke tagihan
            // lewat midtrans_order_ids / doku_invoice_ids (PaymentOrchestratorService::ownsGatewayId).
            $log = OnlineGatewayRouter::recordSession(
                is_array($bill?->payload_log) ? $bill->payload_log : [],
                $paymentResult,
                $paymentMethod,
                $grandTotal,
                now()->addMinutes(app(\App\Services\Padel\BookingTimeService::class)->paymentWindowMinutes()),
            );

            $attributes = [
                'payment_gateway' => $paymentResult['gateway'],
                'payment_method' => strtoupper($paymentMethod),
                'amount' => $grandTotal,
                'snap_token' => $paymentResult['snap_token'] ?? null,
                'payment_url' => $paymentResult['payment_url'] ?? $paymentResult['redirect_url'] ?? null,
                'payload_log' => $log,
            ];

            if ($bill) {
                $bill->update($attributes);
            } else {
                // Tagihan lama sudah ditutup rekonsiliasi (sesi kedaluwarsa) — buat tagihan baru untuk sesi ini.
                Payment::create($attributes + ['order_id' => $order->id, 'transaction_id' => $sessionId, 'status' => 'PENDING']);
            }
            $membership->touch();

            // Simulator (mesin developer / test) — sama dengan checkout: langsung lunas.
            if ($paymentResult['is_mock'] ?? false) {
                app(PaymentOrchestratorService::class)->markOrderAsPaid($order, [
                    'payment_gateway' => 'MOCK',
                    'counter' => 'ONLINE_PORTAL',
                    'transaction_id' => $sessionId,
                    'payment_method' => strtoupper($paymentMethod),
                    'amount' => $grandTotal,
                    'user' => $user,
                    'payload_log' => $paymentResult['raw'] ?? null,
                ]);
            }

            return [
                'order' => $order->fresh(),
                'membership' => $membership->fresh(['balances', 'plan']),
                // Data internal sesi DOKU (Request-Id untuk pembatalan, token) tidak dikirim ke browser.
                'payment' => \Illuminate\Support\Arr::except($paymentResult, ['request_id', 'token_id', 'expires_at', 'snap']),
                'already_paid' => false,
            ];
        });
    }

    /** Dibatalkan customer (mis. ingin ganti paket). Uang yang ternyata sudah masuk tidak pernah dibatalkan. */
    public function cancel(UserMembership $membership, User $user): void
    {
        $order = $this->assertOwnedPending($membership, $user);

        $result = $this->reconciler->reconcileOrder($order);
        if ($result === OnlineGatewayRouter::PAID) {
            throw new HttpException(409, \App\Support\SiteLocale::text('membership.paid_meanwhile'));
        }
        if ($result === OnlineGatewayRouter::ERROR) {
            throw new HttpException(503, \App\Support\SiteLocale::text('membership.status_check_failed', ['gateway' => $this->reconciler->gatewayLabel($order)]));
        }

        $sessions = $this->close($membership, 'CUSTOMER_CANCELLED');

        ActivityLogger::record(
            module: 'MEMBERSHIP',
            event: 'membership.online_order_cancelled',
            description: "Customer membatalkan pesanan membership {$order->order_number} yang belum dibayar",
            subject: $order,
            meta: ['order_number' => $order->order_number, 'paket' => $membership->plan?->name],
        );

        // Sesi online yang masih terbuka (VA belum dibayar) ditutup juga, supaya tidak bisa dibayar setelah ini.
        // Kalaupun tetap terbayar, uangnya tercatat + refund PENDING (PaymentOrchestratorService, order CANCELLED).
        foreach ($sessions as $gateway => $ids) {
            $this->reconciler->cancelSessions($ids, $gateway);
        }
    }

    /** Pesanan online yang ditinggal (dijalankan harian dari membership:sync-expired). */
    public function cancelStale(): int
    {
        $count = 0;

        $this->pendingQuery()
            ->with('order')
            ->where('updated_at', '<', now()->subHours(self::STALE_HOURS))
            ->get()
            ->each(function (UserMembership $membership) use (&$count) {
                if ($this->reconciler->reconcileOrder($membership->order) !== OnlineGatewayRouter::NOT_PAID) {
                    return;
                }

                $this->close($membership, 'ABANDONED_ONLINE_ORDER');
                // Lunas di sela-sela (close tidak membatalkan) → tidak dicatat sebagai batal.
                if ($membership->fresh()?->status !== 'CANCELLED') {
                    return;
                }
                $order = $membership->order;
                ActivityLogger::record(
                    module: 'MEMBERSHIP',
                    event: 'membership.online_order_expired',
                    description: "Pesanan membership {$order->order_number} dibatalkan otomatis karena tidak dibayar dalam ".self::STALE_HOURS.' jam',
                    subject: $order,
                    meta: ['order_number' => $order->order_number, 'paket' => $membership->plan?->name, 'pelanggan' => $order->user?->name],
                    asSystem: true,
                );
                $count++;
            });

        return $count;
    }

    /** Kartu PENDING milik order yang dibatalkan gateway (notifikasi expire / cancel) ikut dibatalkan. */
    public static function cancelForCancelledOrder(Order $order): void
    {
        UserMembership::where('order_id', $order->id)
            ->where('status', 'PENDING_PAYMENT')
            ->update(['status' => 'CANCELLED', 'updated_at' => now()]);
    }

    /**
     * Item Midtrans WAJIB berjumlah sama dengan gross_amount; selisih pembulatan dititipkan ke baris terakhir.
     *
     * @return list<array{id: string, price: int, quantity: int, name: string}>
     */
    public static function itemDetails(Order $order, string $planName, ?string $taxName = null, ?string $adminFeeName = null): array
    {
        $items = [[
            'id' => substr((string) ($order->items()->value('reference_id') ?? 'MEMBERSHIP'), 0, 50),
            'price' => (int) round((float) $order->subtotal),
            'quantity' => 1,
            'name' => substr($planName, 0, 50),
        ]];
        if ((float) $order->tax_amount > 0) {
            $items[] = ['id' => 'TAX-FEE', 'price' => (int) round((float) $order->tax_amount), 'quantity' => 1, 'name' => substr($taxName ?: 'Pajak', 0, 50)];
        }
        if ((float) $order->service_charge > 0) {
            $items[] = ['id' => 'ADMIN-FEE', 'price' => (int) round((float) $order->service_charge), 'quantity' => 1, 'name' => substr($adminFeeName ?: 'Biaya Layanan', 0, 50)];
        }

        $items[array_key_last($items)]['price'] += (int) $order->grand_total - array_sum(array_map(fn ($i) => $i['price'] * $i['quantity'], $items));

        return $items;
    }

    private function pendingQuery()
    {
        return UserMembership::query()
            ->where('status', 'PENDING_PAYMENT')
            ->whereNull('sold_by_admin_id')
            ->whereHas('order', fn ($q) => $q->where('order_type', 'MEMBERSHIP')->where('payment_status', 'UNPAID'));
    }

    private function assertOwnedPending(UserMembership $membership, User $user): Order
    {
        if ($membership->user_id !== $user->id) {
            throw new HttpException(403, 'Pesanan membership ini bukan milik Anda.');
        }

        $order = $membership->order;

        if ($membership->sold_by_admin_id || ! $order || $order->order_type !== 'MEMBERSHIP') {
            throw new HttpException(422, \App\Support\SiteLocale::text('membership.not_online'));
        }
        if ($order->payment_status === 'PAID') {
            throw new HttpException(409, \App\Support\SiteLocale::text('membership.already_paid_reload'));
        }
        if ($order->payment_status !== 'UNPAID' || $membership->status !== 'PENDING_PAYMENT') {
            throw new HttpException(422, \App\Support\SiteLocale::text('membership.order_closed'));
        }

        return $order;
    }

    /** @return array<string, list<string>> sesi tagihan yang ditutup, per gateway (MIDTRANS / DOKU) */
    private function close(UserMembership $membership, string $reason): array
    {
        return DB::transaction(function () use ($membership, $reason) {
            $order = Order::whereKey($membership->order_id)->lockForUpdate()->firstOrFail();

            // Lunas di sela-sela (webhook baru masuk) → jangan batalkan.
            if ($order->payment_status !== 'UNPAID') {
                return [];
            }

            $sessions = [OnlineGatewayRouter::MIDTRANS => [], OnlineGatewayRouter::DOKU => []];
            foreach (Payment::where('order_id', $order->id)->where('status', 'PENDING')->lockForUpdate()->get() as $bill) {
                $log = is_array($bill->payload_log) ? $bill->payload_log : [];
                if ($bill->payment_gateway === 'MIDTRANS') {
                    $sessions[OnlineGatewayRouter::MIDTRANS] = array_merge($sessions[OnlineGatewayRouter::MIDTRANS], [$bill->transaction_id, $log['midtrans_order_id'] ?? null], (array) ($log['midtrans_order_ids'] ?? []));
                }
                $sessions[OnlineGatewayRouter::DOKU] = array_merge($sessions[OnlineGatewayRouter::DOKU], \App\Services\Payment\Doku\DokuSessionGuard::sessionsOf($bill));
                $log['closed_by'] = $reason;
                $bill->update(['status' => 'FAILED', 'payload_log' => $log]);
            }

            $order->update(['payment_status' => 'CANCELLED']);
            self::cancelForCancelledOrder($order);

            return array_map(fn (array $ids) => array_values(array_unique(array_filter($ids, fn ($s) => is_string($s) && $s !== ''))), $sessions);
        });
    }

    private function alreadyPaid(UserMembership $membership): array
    {
        return [
            'order' => $membership->order()->first(),
            'membership' => $membership->fresh(['balances', 'plan']),
            'payment' => ['is_mock' => false, 'snap_token' => null],
            'already_paid' => true,
        ];
    }
}
