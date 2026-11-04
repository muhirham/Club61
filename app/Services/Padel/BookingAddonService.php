<?php

namespace App\Services\Padel;

use App\Models\Padel\CourtEquipment;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelBookingEquipment;
use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Models\Pos\PosCashierShift;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Coaching\CoachingBillingService;
use App\Services\Pos\PosPaymentProof;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Tambah Alat ke Booking (Kasir Pro Shop tab Sewa, izin add_booking_equipment): bola / raket / handuk untuk booking HARI INI / KE DEPAN yang
 * sudah ada, tanpa memilih lapangan.
 *  - Booking lunas / sudah check-in → transaksi baru (order WALK_IN berisi alat saja) dibayar di kasir saat itu (QRIS /
 *    EDC, shift loket yang buka). Alatnya ditautkan ke booking lewat padel_booking_equipments.booking_id; order &
 *    pembayaran booking lama tidak disentuh.
 *  - Sesi coaching Tagihan Berkala yang belum dilunasi → alat masuk ke order tagihannya (belum ada uang masuk), ikut
 *    dilunasi di Pelunasan Tagihan Coaching.
 * Stok: bola dipotong saat itu; raket / handuk dipotong saat itu bila booking sudah check-in, selain itu saat check-in
 * (sama dengan alat yang dipesan saat booking).
 */
class BookingAddonService
{
    public const PERMISSION = 'add_booking_equipment';

    public const MODE_PAID = 'PAID';

    public const MODE_TAB = 'TAB';

    public const MAX_QUANTITY = 20;

    /**
     * PAID = dibayar sekarang sebagai transaksi baru, TAB = masuk tagihan academy, null = tidak bisa ditambah alat.
     * Booking hari ini & tanggal ke depan; yang tanggalnya sudah lewat tidak.
     */
    public function mode(PadelBooking $booking): ?string
    {
        if (! $booking->booking_date || $booking->booking_date->toDateString() < now('Asia/Jakarta')->toDateString()) {
            return null;
        }
        if (in_array($booking->status, ['PENDING_PAYMENT', 'CHECKED_IN'], true) && $booking->order_id
            && CoachingBillingService::isTabBooking($booking)
            && Order::whereKey($booking->order_id)->value('payment_status') === 'UNPAID') {
            return self::MODE_TAB;
        }

        return in_array($booking->status, ['PAID', 'CHECKED_IN'], true) ? self::MODE_PAID : null;
    }

    public function ineligibleReason(?PadelBooking $booking): string
    {
        return match (true) {
            ! $booking => 'Booking tidak ditemukan.',
            ! $booking->booking_date || $booking->booking_date->toDateString() < now('Asia/Jakarta')->toDateString() => "Booking {$booking->booking_code} tanggalnya sudah lewat — tambah alat hanya untuk booking hari ini dan ke depan.",
            default => "Booking {$booking->booking_code} berstatus {$booking->status} — hanya booking yang sudah lunas / check-in (atau sesi Tagihan Berkala coaching) yang bisa ditambah alat.",
        };
    }

    /** Cari booking hari ini & ke depan yang bisa ditambah alat: kode booking, nama, atau nomor HP. */
    public function search(string $term, int $limit = 8): Collection
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return collect();
        }

        // Perbandingan langsung (bukan whereDate / DATE()) supaya index booking_date + status terpakai — booking lama
        // tidak pernah dihapus, jadi pencarian hanya menyisir booking hari ini & ke depan.
        return PadelBooking::with(['user:id,name,phone', 'court:id,name'])
            ->where('booking_date', '>=', now('Asia/Jakarta')->toDateString())
            ->whereIn('status', ['PAID', 'CHECKED_IN', 'PENDING_PAYMENT'])
            ->where(fn ($q) => $q->where('booking_code', 'like', "%{$term}%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")))
            ->orderBy('booking_date')->orderBy('start_time')
            ->limit($limit * 2)
            ->get()
            ->filter(fn (PadelBooking $b) => $this->mode($b) !== null)
            ->take($limit)
            ->values();
    }

    /** Booking HARI INI yang bisa ditambah alat (daftar awal tab Sewa di Kasir Pro Shop), urut jam main. */
    public function todayEligible(int $limit = 60): Collection
    {
        $today = now('Asia/Jakarta')->toDateString();

        // Rentang (bukan whereDate) supaya index booking_date + status terpakai — pola sama dengan Kelola Pemesanan.
        return PadelBooking::with(['user:id,name,phone', 'court:id,name'])
            ->whereBetween('booking_date', [$today, $today.' 23:59:59'])
            ->whereIn('status', ['PAID', 'CHECKED_IN', 'PENDING_PAYMENT'])
            ->orderBy('start_time')
            ->limit($limit * 2)
            ->get()
            ->filter(fn (PadelBooking $b) => $this->mode($b) !== null)
            ->take($limit)
            ->values();
    }

    /** Ringkasan booking untuk layar kasir. */
    public function summary(PadelBooking $booking): array
    {
        $booking->loadMissing(['user', 'court', 'coachingBooking.academy']);

        return [
            'booking_id' => $booking->id,
            'code' => $booking->booking_code,
            'customer' => $booking->user?->name ?? 'Customer',
            'customer_id' => $booking->user_id,
            'phone' => $booking->user?->phone,
            'court' => $booking->court?->name ?? '-',
            'date' => $booking->booking_date->toDateString(),
            'schedule' => $booking->booking_date->locale('id')->translatedFormat('D, d M Y').' · '
                .$booking->start_time->format('H:i').' - '.$booking->end_time->format('H:i').' WIB',
            'mode' => $this->mode($booking),
            'academy' => $booking->coachingBooking?->academy?->name ?? $booking->coachingBooking?->academy_name,
        ];
    }

    /**
     * Rincian & total. PAID: pajak & biaya layanan transaksi baru. TAB: selisih total order tagihan setelah alat ditambah
     * (biaya layanan per transaksi tidak dihitung dua kali).
     *
     * @param  array<string, int|string>  $quantities  [equipment_id => qty]
     * @param  Collection<string, CourtEquipment>|null  $equipments  baris alat yang sudah dikunci (checkout)
     */
    public function quote(PadelBooking $booking, array $quantities, ?Collection $equipments = null, ?string $mode = null): array
    {
        $mode ??= $this->mode($booking);
        $wanted = collect($quantities)->map(fn ($qty) => (int) $qty)->filter(fn ($qty) => $qty > 0);
        $equipments ??= CourtEquipment::whereIn('id', $wanted->keys())->where('is_active', true)->get()->keyBy('id');

        $items = [];
        foreach ($wanted as $id => $qty) {
            $eq = $equipments->get($id);
            if (! $eq || ! $eq->is_active) {
                continue;
            }
            $qty = min($qty, self::MAX_QUANTITY);
            $items[] = [
                'equipment_id' => $eq->id,
                'name' => $eq->name,
                'type' => strtoupper((string) $eq->type),
                'quantity' => $qty,
                'unit_price' => (float) $eq->rental_price,
                'subtotal' => round((float) $eq->rental_price * $qty, 2),
            ];
        }
        $subtotal = round(array_sum(array_column($items, 'subtotal')), 2);

        if ($mode === self::MODE_TAB) {
            $order = $booking->order ?? Order::find($booking->order_id);
            $after = CoachingBillingService::tabTotalsWith($order, $subtotal);

            return [
                'mode' => $mode,
                'items' => $items,
                'subtotal' => $subtotal,
                'tax_amount' => round($after['tax_amount'] - (float) $order->tax_amount, 2),
                'service_charge' => round($after['admin_fee_amount'] - (float) $order->service_charge, 2),
                'grand_total' => round($after['grand_total'] - (float) $order->grand_total, 2),
                'tab_before' => (float) $order->grand_total,
                'tab_after' => (float) $after['grand_total'],
            ];
        }

        $calc = app(\App\Services\Finance\TaxAndFeeService::class)->calculate(subtotal: $subtotal, discountAmount: 0, channel: 'POS_WALKIN', module: 'PADEL');

        return [
            'mode' => $mode,
            'items' => $items,
            'subtotal' => $subtotal,
            'tax_amount' => (float) $calc['tax_amount'],
            'service_charge' => (float) $calc['admin_fee_amount'],
            'grand_total' => (float) $calc['grand_total'],
        ];
    }

    /**
     * Simpan tambahan alat. Semua dicek ulang di dalam kunci: booking masih bisa ditambah alat dengan cara yang sama
     * ($expectedMode), stok, dan total yang tampil di layar kasir ($expectedTotal).
     *
     * @param  array<string, int|string>  $quantities  [equipment_id => qty]
     * @param  string  $method  QRIS / EDC_x (bukti diketik kasir) atau QRIS_MIDTRANS (Bayar Otomatis: QR / VA di layar kasir)
     * @param  array<string, mixed>  $proof  isian bukti PosPaymentProof (tidak dipakai untuk TAB & Bayar Otomatis)
     * @param  string|null  $onlineMethod  metode Bayar Otomatis pilihan kasir (QRIS / VA yang tampil di kasir)
     * @return array{mode: string, order: Order, payment: ?Payment, pending_qris: ?array, quote: array}
     */
    public function checkout(string $bookingId, array $quantities, string $expectedMode, float $expectedTotal, string $method, array $proof, User $cashier, ?string $onlineMethod = null): array
    {
        if (! $cashier->can(self::PERMISSION)) {
            throw new HttpException(403, 'Akses ditolak: Anda tidak memiliki izin [add_booking_equipment] untuk menambah alat ke booking.');
        }
        $autoPay = strtoupper($method) === 'QRIS_MIDTRANS';

        return DB::transaction(function () use ($bookingId, $quantities, $expectedMode, $expectedTotal, $method, $proof, $cashier, $onlineMethod, $autoPay) {
            // Urutan kunci sama dengan Pelunasan / Batal Tagihan Coaching: order lebih dulu, lalu booking.
            $orderId = PadelBooking::whereKey($bookingId)->value('order_id');
            $lockedOrder = $expectedMode === self::MODE_TAB && $orderId ? Order::whereKey($orderId)->lockForUpdate()->first() : null;
            $booking = PadelBooking::with(['court', 'user'])->whereKey($bookingId)->lockForUpdate()->first();

            $mode = $booking ? $this->mode($booking) : null;
            if (! $mode) {
                throw new HttpException(422, $this->ineligibleReason($booking));
            }
            if ($mode !== $expectedMode) {
                throw new HttpException(409, $mode === self::MODE_TAB
                    ? "Booking {$booking->booking_code} ternyata sesi Tagihan Berkala — muat ulang lalu pilih booking lagi."
                    : "Tagihan coaching {$booking->booking_code} baru saja dilunasi — muat ulang lalu pilih booking lagi supaya alat dibayar sebagai transaksi baru.");
            }
            if ($lockedOrder) {
                $booking->setRelation('order', $lockedOrder);
            }

            $wantedIds = collect($quantities)->filter(fn ($qty) => (int) $qty > 0)->keys()->sort()->values();
            $equipments = CourtEquipment::whereIn('id', $wantedIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $quote = $this->quote($booking, $quantities, $equipments, $mode);
            if ($quote['items'] === []) {
                throw new HttpException(422, 'Pilih minimal satu alat yang ditambahkan.');
            }
            if (abs($quote['grand_total'] - $expectedTotal) > 1) {
                throw new HttpException(409, 'Total tambahan berubah dari Rp '.number_format($expectedTotal, 0, ',', '.').' menjadi Rp '
                    .number_format($quote['grand_total'], 0, ',', '.').'. Periksa lagi rincian di layar sebelum menagih customer.');
            }

            // Stok: alat Jual selalu sekarang; alat Sewa sekarang hanya bila sudah diserahkan (booking sudah check-in).
            $deductNow = [];
            foreach ($quote['items'] as $item) {
                $eq = $equipments->get($item['equipment_id']);
                $now = $eq->isSale() || ($booking->status === 'CHECKED_IN' && $eq->isRental());
                if ($now) {
                    $eq = $equipments->get($item['equipment_id']);
                    if ((int) $eq->stock_quantity < $item['quantity']) {
                        throw new HttpException(422, "Stok {$eq->name} tinggal {$eq->stock_quantity}, tidak cukup untuk {$item['quantity']}.");
                    }
                    $deductNow[$item['equipment_id']] = true;
                }
            }

            $payment = null;
            if ($mode === self::MODE_TAB) {
                $order = $lockedOrder;
                app(CoachingBillingService::class)->addEquipmentToTab($order, $quote['subtotal'], $quote['items']);
            } else {
                if (! PosCashierShift::getActiveShift('PADEL_FRONTDESK')) {
                    throw new HttpException(422, 'Tidak ada shift kasir yang aktif untuk loket [PADEL_FRONTDESK]. Buka shift terlebih dahulu.');
                }
                if ($quote['grand_total'] <= 0) {
                    throw new HttpException(422, 'Total tambahan Rp 0 — tidak ada yang perlu dibayar.');
                }
                // Bayar Otomatis: lunas dikonfirmasi gateway (webhook / cek status), tanpa bukti yang diketik kasir.
                $proofPayload = $autoPay ? [] : PosPaymentProof::validate($method, $proof, $quote['grand_total']);

                $orderNumber = 'ORD-PAD-'.strtoupper(Str::random(10));
                $order = Order::create([
                    'order_number' => $orderNumber,
                    'user_id' => $booking->user_id,
                    'cashier_id' => $cashier->id,
                    'order_type' => 'WALK_IN',
                    'subtotal' => $quote['subtotal'],
                    'discount_amount' => 0,
                    'tax_amount' => $quote['tax_amount'],
                    'service_charge' => $quote['service_charge'],
                    'grand_total' => $quote['grand_total'],
                    'payment_status' => 'UNPAID',
                ]);
                foreach ($quote['items'] as $item) {
                    $order->items()->create([
                        'item_type' => 'EQUIPMENT',
                        'reference_id' => $item['equipment_id'],
                        'item_name' => $item['name'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'subtotal' => $item['subtotal'],
                    ]);
                }
            }

            foreach ($quote['items'] as $item) {
                if (isset($deductNow[$item['equipment_id']])) {
                    $equipments->get($item['equipment_id'])->decrement('stock_quantity', $item['quantity']);
                }
                $row = PadelBookingEquipment::create([
                    'order_id' => $order->id,
                    'booking_id' => $booking->id,
                    'equipment_id' => $item['equipment_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'subtotal' => $item['subtotal'],
                    'stock_deducted_at' => isset($deductNow[$item['equipment_id']]) ? now() : null,
                ]);
                // Alat sewa untuk booking yang sudah check-in langsung diserahkan → tercatat di Peminjaman Alat.
                app(EquipmentLoanService::class)->openForBookingRow($row, $cashier);
            }

            $pending = null;
            if ($mode === self::MODE_PAID) {
                $payloadLog = [
                    'cashier_id' => $cashier->id,
                    'cashier_name' => $cashier->name,
                    'source' => 'BOOKING_ADDON',
                    'channel' => 'FRONTDESK_CASHIER',
                    'booking_addon' => ['booking_id' => $booking->id, 'booking_code' => $booking->booking_code],
                ];
                if ($autoPay) {
                    // QR / VA di layar kasir — order menunggu customer membayar. Tidak dibayar → PosPendingOrderCanceller
                    // membatalkan order & mengembalikan stok yang sudah dipotong; alatnya tidak dihitung selama belum lunas.
                    $pending = app(\App\Services\Pos\PosAutoPayment::class)->open($order, 'PADEL_FRONTDESK', $cashier, $payloadLog + ['payment_method' => 'QRIS'], $onlineMethod ?: 'QRIS');
                } else {
                    app(\App\Services\Payment\PaymentOrchestratorService::class)->markOrderAsPaid($order, [
                        'payment_gateway' => 'CASHIER_POS',
                        'counter' => 'PADEL_FRONTDESK',
                        'transaction_id' => $order->order_number,
                        'payment_method' => strtoupper($method),
                        'amount' => $quote['grand_total'],
                        'cashier_id' => $cashier->id,
                        'payload_log' => $payloadLog + $proofPayload,
                    ]);
                    $payment = Payment::where('order_id', $order->id)->where('status', 'SUCCESS')->latest()->first();
                }
            }

            $list = collect($quote['items'])->map(fn ($i) => "{$i['name']} ×{$i['quantity']}")->implode(', ');
            ActivityLogger::record(
                module: 'PADEL',
                event: 'booking.addon_equipment',
                description: "Menambah alat ke booking {$booking->booking_code}: {$list} (Rp ".number_format($quote['grand_total'], 0, ',', '.').')'
                    .($mode === self::MODE_TAB ? ' — dicatat pada tagihan berkala academy' : " — transaksi {$order->order_number}".($autoPay ? ' (menunggu Bayar Otomatis)' : '')),
                subject: $booking,
                meta: array_filter([
                    'kode_booking' => $booking->booking_code,
                    'alat' => $list,
                    'total' => ActivityLogger::rupiah($quote['grand_total']),
                    'cara_bayar' => $mode === self::MODE_TAB ? 'Tagihan Berkala academy' : ($autoPay ? 'Bayar Otomatis (menunggu pembayaran)' : (PosPaymentProof::METHODS[strtoupper($method)] ?? $method)),
                    'order' => $order->order_number,
                    'tagihan' => $mode === self::MODE_TAB ? ActivityLogger::rupiah($quote['tab_before']).' → '.ActivityLogger::rupiah($quote['tab_after']) : null,
                ]),
                causer: $cashier,
            );

            return ['mode' => $mode, 'order' => $order->fresh(['items']), 'payment' => $payment, 'pending_qris' => $pending, 'quote' => $quote];
        });
    }

    /**
     * Baris alat sebuah booking: milik order booking (termasuk booking lain di order yang sama, seperti sebelumnya) +
     * tambahan alat yang ditautkan ke booking ini lewat transaksi terpisah yang SUDAH LUNAS (QR yang belum dibayar /
     * dibatalkan tidak dihitung). Dipakai check-in (potong stok) & retur alat.
     */
    public static function equipmentRowsOf(PadelBooking $booking): \Illuminate\Database\Eloquent\Builder
    {
        $group = $booking->order_id ? PadelBooking::where('order_id', $booking->order_id)->select('id') : [$booking->id];

        return PadelBookingEquipment::query()->where(function ($q) use ($booking, $group) {
            $booking->order_id ? $q->where('order_id', $booking->order_id) : $q->where('booking_id', $booking->id);
            $q->orWhere(fn ($addon) => $addon->whereIn('booking_id', $group)
                ->whereIn('order_id', Order::where('payment_status', 'PAID')->select('id')));
        });
    }
}
