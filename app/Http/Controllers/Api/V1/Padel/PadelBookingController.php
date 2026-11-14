<?php

namespace App\Http\Controllers\Api\V1\Padel;

use App\Http\Controllers\Controller;
use App\Models\Pos\ClubFinanceSetting;
use App\Services\Padel\PadelBookingService;
use App\Support\SiteLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PadelBookingController extends Controller
{
    protected PadelBookingService $bookingService;

    public function __construct(PadelBookingService $bookingService)
    {
        $this->bookingService = $bookingService;
    }

    /**
     * Matriks Jadwal Lapangan (Publik, Timezone-Aware).
     */
    public function schedule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            // Diterima persis yang bisa dipakai jadwal (Carbon: nama zona, alias lama, offset +07:00 / 7); selain itu 422,
            // bukan error 500. Sengaja memakai Carbon yang sama dengan getScheduleMatrix supaya klien lama tidak tertolak.
            'timezone' => ['nullable', 'string', 'max:64', function (string $attribute, mixed $value, \Closure $fail) {
                try {
                    \Carbon\Carbon::now($value);
                } catch (\Throwable) {
                    $fail('Zona waktu tidak dikenali.');
                }
            }],
        ]);

        $date = $validated['date'] ?? now()->format('Y-m-d');
        $timezone = $validated['timezone'] ?? 'Asia/Jakarta';

        $matrix = $this->bookingService->getScheduleMatrix($date, $timezone);

        return response()->json([
            'success' => true,
            'message' => SiteLocale::text('booking.schedule_loaded'),
            'data' => $matrix,
        ]);
    }

    /**
     * Katalog Sewa Peralatan & Add-ons (Publik).
     */
    public function equipments(): JsonResponse
    {
        $equipments = $this->bookingService->getEquipments();

        return response()->json([
            'success' => true,
            'message' => SiteLocale::text('booking.equipments_loaded'),
            'data' => $equipments,
        ]);
    }

    /**
     * Pengaturan Biaya Layanan dan Pajak Terpusat (Publik).
     */
    public function financeSettings(): JsonResponse
    {
        $settings = ClubFinanceSetting::getSettings();

        return response()->json([
            'success' => true,
            'message' => SiteLocale::text('booking.finance_settings_loaded'),
            'data' => [
                'is_tax_enabled' => (bool) $settings->is_tax_enabled,
                'tax_name' => (string) ($settings->tax_name ?: 'PB1 Pajak Daerah / PPh'),
                'tax_type' => (string) ($settings->tax_type ?: 'PERCENTAGE'),
                'tax_rate' => (float) $settings->tax_rate,
                'tax_channels' => (string) ($settings->tax_channels ?: 'ALL'),
                'is_admin_fee_enabled' => (bool) $settings->is_admin_fee_enabled,
                'admin_fee_name' => (string) ($settings->admin_fee_name ?: 'Biaya Layanan / Admin'),
                'admin_fee_type' => (string) ($settings->admin_fee_type ?: 'FIXED'),
                'admin_fee_amount' => (float) $settings->admin_fee_amount,
                'admin_fee_channels' => (string) ($settings->admin_fee_channels ?: 'ONLINE_ONLY'),
            ],
        ]);
    }

    /**
     * Hold Slot Lapangan (Multi-Slot Atomic All-or-Nothing).
     */
    public function hold(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'booking_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today',
                'before_or_equal:'.now()->addDays(PadelBookingService::MAX_DAYS_AHEAD)->toDateString()],
            'slots' => ['required', 'array', 'min:1'],
            'slots.*.court_id' => ['required', 'string'],
            'slots.*.start_time' => ['required', 'date_format:H:i'],
            'slots.*.end_time' => ['required', 'date_format:H:i'],
        ], [
            'booking_date.before_or_equal' => SiteLocale::text('booking.max_days_ahead', ['days' => PadelBookingService::MAX_DAYS_AHEAD]),
        ]);

        // Batas jam per pemesanan & per akun (audit G17) — kasir walk-in memakai holdBatchSlots langsung, tidak dibatasi.
        // coach_id dari customer tidak diterima lagi (Modul 24 Opsi B): coach & tarif coaching hanya dipasang staf di POS.
        $holdData = $this->bookingService->holdForCustomer(
            $validated['slots'],
            $validated['booking_date'],
            $request->user(),
        );

        $slotCount = count($holdData['bookings']);

        return response()->json([
            'success' => true,
            'message' => SiteLocale::text('booking.hold_success', ['count' => $slotCount]),
            'data' => $holdData,
        ], 201);
    }

    /**
     * Melepaskan Kunci Slot (Batal dari Keranjang).
     */
    public function release(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'booking_ids' => ['required', 'array', 'min:1'],
            'booking_ids.*' => ['required', 'string'],
            // true = hanya lepas slot yang masih DITAHAN (LOCKED). Dipakai keranjang & checkout (countdown habis,
            // hapus item) supaya tidak pernah membatalkan booking yang sudah klik bayar & sedang dibayar di Midtrans.
            'only_locked' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $canCancel = $user->canCancelBooking();

        // Jika user tidak memiliki izin batal, cegah pembatalan tiket yang sudah berstatus PENDING/PENDING_PAYMENT
        if (! $canCancel && empty($validated['only_locked'])) {
            $hasPendingBooking = \App\Models\Padel\PadelBooking::whereIn('id', $validated['booking_ids'])
                ->where('user_id', $user->id)
                ->whereIn('status', ['PENDING', 'PENDING_PAYMENT'])
                ->exists();

            if ($hasPendingBooking) {
                // 403 sengaja tidak diterjemahkan: pesannya juga dicatat di Log Aktivitas admin (bootstrap/app.php).
                throw new HttpException(403, 'Akses Ditolak: Peran Anda tidak memiliki izin untuk membatalkan pesanan ini.');
            }
        }

        $releasedCount = $this->bookingService->releaseSlots($validated['booking_ids'], $user, onlyLocked: (bool) ($validated['only_locked'] ?? false));

        return response()->json([
            'success' => true,
            'message' => SiteLocale::text('booking.release_success', ['count' => $releasedCount]),
        ]);
    }

    /**
     * Preview Benefit Membership (Read-Only) — dipanggil halaman "Payment Details & Checkout"
     * SEBELUM customer menekan tombol bayar, supaya potongan diskon/kuota membership terlihat
     * di muka, bukan baru ketahuan setelah pembayaran diproses.
     */
    public function previewMembershipBenefit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'booking_ids' => ['required', 'array', 'min:1'],
            'booking_ids.*' => ['required', 'string'],
            'membership_balance_id' => ['nullable', 'string'],
            'sponsor_voucher_id' => ['nullable', 'string'],
        ]);

        $preview = $this->bookingService->previewMembershipBenefit(
            $validated['booking_ids'],
            $request->user(),
            $validated['membership_balance_id'] ?? null,
            $validated['sponsor_voucher_id'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => SiteLocale::text('booking.preview_loaded'),
            'data' => $preview,
        ]);
    }

    /**
     * Checkout Pembayaran (Idempotent 24 Jam).
     */
    public function checkout(Request $request): JsonResponse
    {
        $idempotencyKey = $request->header('X-Idempotency-Key');
        if (! $idempotencyKey) {
            throw new HttpException(400, SiteLocale::text('checkout.idempotency_required'));
        }

        $validated = $request->validate([
            'booking_ids' => ['required', 'array', 'min:1'],
            'booking_ids.*' => ['required', 'string'],
            'equipments' => ['nullable', 'array'],
            // Satu baris per alat, maks. 10 per checkout — dulu tanpa batas: stok bola bisa dihabiskan oleh keranjang yang
            // tidak pernah dibayar (stok bola dipotong saat checkout).
            'equipments.*.equipment_id' => ['required', 'string', 'distinct'],
            'equipments.*.quantity' => ['required', 'integer', 'min:1', 'max:10'],
            'voucher_code' => ['nullable', 'string'],
            // Kode harus ada di katalog resmi; aktif/nonaktif & batas nominal divalidasi di service (butuh total tagihan).
            'payment_method' => ['required', 'string', \Illuminate\Validation\Rule::in(array_keys(\App\Services\Payment\OnlinePaymentCatalog::all()))],
            // 'NONE' = customer sengaja memilih TIDAK memakai benefit membership untuk booking ini
            // (toggle di halaman checkout), null = auto-detect membership aktif seperti biasa.
            'membership_balance_id' => ['nullable', 'string'],
            // Sama seperti membership_balance_id tapi untuk voucher jam sponsor corporate — 'NONE'
            // = customer matiin toggle voucher, null = auto-detect voucher aktif miliknya.
            'sponsor_voucher_id' => ['nullable', 'string'],
        ]);

        $checkoutResult = $this->bookingService->checkout(
            $validated['booking_ids'],
            $validated['equipments'] ?? [],
            $validated['voucher_code'] ?? null,
            $validated['payment_method'],
            $idempotencyKey,
            $request->user(),
            $validated['membership_balance_id'] ?? null,
            $validated['sponsor_voucher_id'] ?? null
        );

        return response()->json($checkoutResult, 200);
    }

    /**
     * Ganti Metode Pembayaran / Regenerasi Snap Token (Pending Payment Retry).
     */
    public function retryPayment(string $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Kode harus ada di katalog resmi; aktif/nonaktif & batas nominal divalidasi di service (butuh total tagihan).
            'payment_method' => ['required', 'string', \Illuminate\Validation\Rule::in(array_keys(\App\Services\Payment\OnlinePaymentCatalog::all()))],
        ]);

        $result = $this->bookingService->retryPayment(
            $id,
            $validated['payment_method'],
            $request->user()
        );

        return response()->json($result, 200);
    }

    /**
     * Riwayat Booking Saya.
     */
    public function myBookings(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $bookings = $this->bookingService->myBookings($request->user(), $status);

        return response()->json([
            'success' => true,
            'message' => SiteLocale::text('booking.my_bookings_loaded'),
            'data' => $bookings,
        ]);
    }

    /**
     * Detail E-Tiket & Payload QR Code.
     */
    public function ticket(string $id, Request $request): JsonResponse
    {
        // getTicket() sekaligus memastikan tiket ini milik user yang login.
        $booking = $this->bookingService->getTicket($id, $request->user());

        // Polling halaman invoice: kalau masih menunggu bayar, tanya langsung ke Midtrans juga —
        // jadi status tetap berubah jadi lunas walau webhook-nya tidak pernah sampai.
        // Termasuk booking LOCKED hasil reschedule yang menunggu pelunasan selisih — dulu dilewati, jadi
        // customer yang sudah bayar selisih via Midtrans tetap melihat "belum lunas" kalau webhook tidak sampai.
        $awaitingPayment = in_array($booking->status, ['PENDING_PAYMENT', 'PENDING'], true)
            || ($booking->status === 'LOCKED' && (int) $booking->reschedule_count > 0);

        if ($request->boolean('verify_payment') && $awaitingPayment && $booking->order_id) {
            $order = \App\Models\Pos\Order::find($booking->order_id);

            if ($order && app(\App\Services\Payment\OnlineGatewayRouter::class)->reconcileOrder($order, cacheSeconds: 10) === \App\Services\Payment\OnlineGatewayRouter::PAID) {
                $booking = $this->bookingService->getTicket($id, $request->user());
            } elseif ($booking->expires_at && $booking->expires_at->isPast() && (int) $booking->reschedule_count === 0) {
                // Batas bayar lewat: lepas slot sekarang kalau Midtrans sudah memastikan sesinya berakhir — tidak
                // menunggu scheduler tiap menit, jadi halaman invoice langsung berubah jadi Expired.
                if ($this->bookingService->releaseExpiredLocks() > 0) {
                    $booking = $this->bookingService->getTicket($id, $request->user());
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => SiteLocale::text('booking.ticket_loaded'),
            'data' => $booking,
        ]);
    }

    /**
     * Check-in Turnstile / Gate di Venue (Kasir & Staff).
     */
    public function checkIn(Request $request): JsonResponse
    {
        $user = $request->user();
        // Izin spesifik, bukan isStaff(): isStaff() meloloskan role non-frontline mana pun (trainer, stylist, role
        // baru bikinan admin) walau tidak diberi izin check-in. Sama dengan rute web /pos/check-in.
        if (! $user->can('checkin_padel_ticket')) {
            throw new HttpException(403, 'Akses Ditolak: Hanya staf yang punya izin check-in yang dapat memverifikasi check-in pemain.');
        }

        $validated = $request->validate([
            'qr_code_hash' => ['required', 'string'],
        ]);

        $checkInData = $this->bookingService->checkIn($validated['qr_code_hash'], $user);

        return response()->json([
            'success' => true,
            'message' => 'Check-in berhasil! Akses gate dibuka. Selamat bertanding.',
            'data' => $checkInData,
        ]);
    }

    /**
     * Voucher saldo milik customer (dari refund yang ditolak) yang masih bisa dipakai (`data`, bentuk lama tidak berubah)
     * + promo hasil klaim yang bisa dipakai sekarang (`claimed`, Modul 22 tahap 3).
     */
    public function myVouchers(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => app(\App\Services\Finance\VoucherService::class)->walletFor($request->user())->values(),
            'claimed' => app(\App\Services\Finance\VoucherClaimService::class)->usableForApi($request->user()),
        ]);
    }

    /** Klaim promo cara sebar C dengan kodenya (kode dari link / banner promo). */
    public function claimVoucher(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:30']]);
        $claims = app(\App\Services\Finance\VoucherClaimService::class);
        $voucher = $claims->findClaimable($validated['code']);
        if (! $voucher) {
            return response()->json(['success' => false, 'message' => SiteLocale::text('voucher.promo_not_found')], 404);
        }

        $claims->claim($voucher, $request->user());

        return response()->json([
            'success' => true,
            'message' => SiteLocale::text('voucher.claimed'),
            'data' => collect($claims->usableForApi($request->user()))->firstWhere('code', $voucher->code),
        ]);
    }

    /**
     * Cek kode voucher sebelum bayar — potongannya dihitung server dengan aturan yang sama dengan checkout,
     * jadi angka di halaman checkout sama dengan yang ditagihkan.
     */
    public function checkVoucher(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'amount' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            // Rincian untuk promo (hanya memotong sewa lapangan) — hanya tampilan; checkout menghitung ulang di server.
            'court_amount' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'addon_amount' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'membership_applied' => ['nullable', 'boolean'],
        ]);

        $amount = (float) $validated['amount'];
        $court = isset($validated['court_amount']) ? (float) $validated['court_amount'] : $amount;
        $result = app(\App\Services\Finance\VoucherService::class)->resolve($validated['code'], $request->user(), $amount, context: [
            'channel' => \App\Models\Pos\Voucher::CHANNEL_ONLINE,
            'amounts' => ['PADEL' => $court, 'PADEL_ADDON' => (float) ($validated['addon_amount'] ?? max(0, $amount - $court))],
            'membership_applied' => (bool) ($validated['membership_applied'] ?? false),
        ]);
        if ($result['error']) {
            return response()->json(['success' => false, 'message' => $result['error']], 422);
        }

        $voucher = $result['voucher'];

        return response()->json([
            'success' => true,
            'data' => [
                'code' => $voucher->code,
                'type' => $voucher->discount_type,
                'discount' => $result['discount'],
                'value' => (float) $voucher->discount_value,
                'max_discount' => $voucher->max_discount_amount !== null ? (float) $voucher->max_discount_amount : null,
                'min_order' => (float) $voucher->min_order_amount,
                'stack_with_membership' => (bool) $voucher->stack_with_membership,
                // Rincian aturan yang ditampilkan di checkout (teks dirangkai di halaman).
                'name' => $voucher->isCredit() ? null : $voucher->name,
                'channel' => $voucher->channel,
                'valid_until' => $voucher->valid_until?->timezone(\App\Services\Finance\LedgerReport::TIMEZONE)->format('d M Y'),
                'available_balance' => $voucher->isCredit() ? app(\App\Services\Finance\VoucherService::class)->availableBalance($voucher) : null,
            ],
        ]);
    }
}
