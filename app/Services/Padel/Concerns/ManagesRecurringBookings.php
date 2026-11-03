<?php

namespace App\Services\Padel\Concerns;

use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelRecurringOccurrence;
use App\Models\Padel\PadelRecurringSchedule;
use App\Models\Pos\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Jadwal Tetap Mingguan — membuat SATU booking (satu lapangan, satu tanggal) atas nama pemilik membership, lunas
 * 100% oleh kuota jam PADEL membership jadwal itu. Jalurnya sama dengan checkout yang ditanggung penuh kuota:
 * holdBatchSlots (cek bentrok + jam operasional + kunci slot) → applyMembershipBenefitToCourtBookings (kartu dipilih
 * eksplisit: masa berlaku & jam akses paket dicek, kuota dipotong lewat ledger) → order Rp0 → markOrderAsPaid.
 * Tidak ada uang masuk, jadi tidak butuh shift kasir. Kuota kurang = gagal (tidak pernah jadi tagihan).
 */
trait ManagesRecurringBookings
{
    public function createRecurringBooking(PadelRecurringSchedule $schedule, string $courtId, string $date, User $actor): PadelBooking
    {
        $owner = $schedule->membership?->user;
        $balance = $schedule->padelBalance();
        if (! $owner || ! $balance) {
            throw new HttpException(422, 'Membership jadwal ini tidak punya kuota jam PADEL.');
        }

        // Dibayar kuota jam (bukan harga lapangan) → hanya tanggal tutup yang berlaku, bukan batas tanggal dibuka.
        \App\Support\BookingWindow::assertBookable($date, closedOnly: true);

        $slots = [['court_id' => $courtId, 'start_time' => $schedule->start_time, 'end_time' => $schedule->end_time]];
        $hold = $this->holdBatchSlots($slots, $date, $owner);
        $bookingIds = collect($hold['bookings'])->pluck('id')->all();

        try {
            return DB::transaction(function () use ($schedule, $courtId, $date, $actor, $owner, $balance, $bookingIds) {
                // Kunci catatan minggu ini: proses lain (tombol admin & perintah terjadwal bersamaan) yang sudah
                // membuat booking-nya → batalkan hold ini.
                $occurrence = PadelRecurringOccurrence::where('recurring_schedule_id', $schedule->id)
                    ->where('court_id', $courtId)
                    ->whereDate('occurrence_date', $date)
                    ->lockForUpdate()
                    ->first();
                if (! $occurrence || ! in_array($occurrence->status, [PadelRecurringOccurrence::STATUS_PENDING, PadelRecurringOccurrence::STATUS_FAILED], true)) {
                    throw new HttpException(409, 'Jadwal minggu ini sudah diproses.');
                }

                $bookings = PadelBooking::with('court')->whereIn('id', $bookingIds)->where('user_id', $owner->id)
                    ->where('status', 'LOCKED')->lockForUpdate()->get();
                if ($bookings->count() !== count($bookingIds)) {
                    throw new HttpException(422, 'Slot yang ditahan sudah tidak berlaku.');
                }

                $benefit = $this->applyMembershipBenefitToCourtBookings($bookings, $owner, $balance->id);
                if ($benefit['benefit_type'] !== 'HOURS' || (float) $bookings->sum('court_fee') > 0) {
                    throw new HttpException(422, 'Sisa kuota jam tidak cukup (sisa '.rtrim(rtrim(number_format((float) $balance->fresh()->remaining_quota, 2, '.', ''), '0'), '.').' jam).');
                }

                $orderNumber = 'ORD-PAD-'.strtoupper(Str::random(10));
                $order = Order::create([
                    'order_number' => $orderNumber,
                    'user_id' => $owner->id,
                    'cashier_id' => $actor->id,
                    'order_type' => 'WALK_IN',
                    'subtotal' => 0,
                    'discount_amount' => 0,
                    'tax_amount' => 0,
                    'service_charge' => 0,
                    'grand_total' => 0,
                    'payment_status' => 'UNPAID',
                ]);

                foreach ($bookings as $b) {
                    $order->items()->create([
                        'item_type' => 'PADEL',
                        'reference_id' => $b->id,
                        'item_name' => 'Sewa '.($b->court ? $b->court->name : 'Court Padel').' · Jadwal Tetap',
                        'quantity' => 1,
                        'unit_price' => $b->court_fee,
                        'subtotal' => $b->court_fee,
                    ]);

                    $b->forceFill([
                        'order_id' => $order->id,
                        'status' => 'PAID',
                        'expires_at' => null,
                        'qr_code_hash' => 'VNT-TICKET-'.strtoupper(bin2hex(random_bytes(16))),
                        'recurring_schedule_id' => $schedule->id,
                    ])->save();
                }

                app(\App\Services\Payment\PaymentOrchestratorService::class)->markOrderAsPaid($order, [
                    'payment_gateway' => 'MEMBERSHIP_QUOTA',
                    'transaction_id' => $orderNumber,
                    'payment_method' => 'MEMBERSHIP_QUOTA',
                    'amount' => 0.0,
                    'payload_log' => [
                        'covered_by' => 'MEMBERSHIP_QUOTA',
                        'source' => 'RECURRING_SCHEDULE',
                        'recurring_schedule_id' => $schedule->id,
                        'created_by' => $actor->name,
                    ],
                ]);

                $booking = $bookings->first();
                $occurrence->update([
                    'status' => PadelRecurringOccurrence::STATUS_BOOKED,
                    'booking_id' => $booking->id,
                    'note' => null,
                ]);

                return $booking->fresh(['court']);
            });
        } catch (\Throwable $e) {
            try {
                $this->releaseSlots($bookingIds, $owner, onlyLocked: true);
            } catch (\Throwable $releaseError) {
                report($releaseError); // jangan menutupi error aslinya
            }

            throw $e;
        }
    }

    /**
     * Pindahkan booking Jadwal Tetap ke slot baru saat jadwalnya diedit (hari / jam / durasi / lapangan) — tanpa
     * membatalkan: kode booking sama, tetap lunas dari kuota. Aturan sama dengan reschedule biasa: lewat batas reschedule
     * global (rescheduleDeadline) atau jam main sudah lewat → ditolak. Slot tujuan wajib kosong (termasuk jam tambahan
     * kalau durasinya bertambah). Durasi bertambah tapi sisa kuota kurang → durasi lama dipertahankan (jam mulai tetap
     * pindah). Selisih jam kuota dipotong / dikembalikan lewat ledger yang sama.
     *
     * @return array{booking: PadelBooking, kept_duration: bool}
     *
     * @throws HttpException 422 dengan alasan untuk admin
     */
    public function moveRecurringBooking(PadelBooking $booking, string $courtId, string $date, string $startTime, string $endTime, User $actor): array
    {
        return DB::transaction(function () use ($booking, $courtId, $date, $startTime, $endTime, $actor) {
            // Urutan kunci sama dengan holdBatchSlots: lapangan dulu, baru baris booking.
            $court = \App\Models\Padel\PadelCourt::where('id', $courtId)->where('is_active', true)->lockForUpdate()->first();
            if (! $court) {
                throw new HttpException(422, 'Lapangan tujuan tidak aktif.');
            }
            $b = PadelBooking::with(['court', 'recurringSchedule'])->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($b->status !== 'PAID' || ! $b->recurring_schedule_id) {
                throw new HttpException(422, "Booking {$b->booking_code} berstatus {$b->status}, tidak dipindah.");
            }
            if (now()->gte(\App\Services\Padel\PadelBookingService::rescheduleDeadline($b))) {
                throw new HttpException(422, 'Lewat batas reschedule ('.\App\Services\Padel\PadelBookingService::rescheduleCutoffLabel($b).' sebelum main) — tetap main sesuai jadwal lama.');
            }
            if (\App\Services\Padel\PadelBookingService::rescheduleLimitReached($b)) {
                throw new HttpException(422, 'Sudah dipindah '.\App\Services\Padel\PadelBookingService::MAX_RESCHEDULES.'x (batas maksimal reschedule) — tetap main sesuai jadwal lama.');
            }

            $start = \Carbon\Carbon::parse("{$date} {$startTime}");
            $end = \Carbon\Carbon::parse("{$date} {$endTime}");
            if ($start->lte(now())) {
                throw new HttpException(422, 'Jam main tujuan sudah lewat.');
            }
            \App\Support\BookingWindow::assertBookable($date, closedOnly: true);
            \App\Support\BookingWindow::assertHoursOpen($date, $start, $end);

            $balance =\App\Models\Membership\UserMembershipBalance::whereKey($b->membership_balance_id)->lockForUpdate()->first();
            $membership = $balance?->membership;
            if (! $balance || ! $membership || ! $membership->isActive()) {
                throw new HttpException(422, 'Membership pemilik kuota sudah tidak aktif.');
            }
            if ($membership->end_date && $start->toDateString() > $membership->end_date->toDateString()) {
                throw new HttpException(422, 'Tanggal tujuan lewat masa aktif membership ('.$membership->end_date->translatedFormat('d M Y').').');
            }

            $oldHours = (float) $b->member_hours_consumed;
            $newHours = round($start->diffInMinutes($end) / 60, 2);
            $keptDuration = false;
            if ($newHours - $oldHours > 0 && (float) $balance->remaining_quota < $newHours - $oldHours) {
                // Kuota tidak cukup untuk jam tambahan → durasi lama, jam mulai tetap pindah.
                $end = $start->copy()->addMinutes((int) round($oldHours * 60));
                $newHours = $oldHours;
                $keptDuration = true;
            }

            if (\App\Services\Membership\PadelAccessRule::violation($balance, $start, $end)) {
                throw new HttpException(422, 'Jam tujuan di luar ketentuan paket member (berlaku: '.\App\Services\Membership\PadelAccessRule::label($balance).').');
            }

            $open = (int) substr($court->open_time ?: '06:00', 0, 2);
            $closeVal = $court->close_time ?: '23:00';
            $close = in_array($closeVal, ['00:00', '24:00'], true) ? 24 : (int) substr($closeVal, 0, 2);
            $endHour = $end->isSameDay($start) ? (int) $end->format('H') : 24;
            if ((int) $start->format('H') < $open || $endHour > $close) {
                throw new HttpException(422, "Di luar jam operasional {$court->name}.");
            }

            $taken = PadelBooking::where('court_id', $court->id)
                ->whereDate('booking_date', $start->toDateString())
                ->whereIn('status', ['LOCKED', 'PENDING_PAYMENT', 'PENDING', 'PAID', 'CHECKED_IN'])
                ->where('id', '!=', $b->id)
                ->where('start_time', '<', $end->format('Y-m-d H:i:s'))
                ->where('end_time', '>', $start->format('Y-m-d H:i:s'))
                ->lockForUpdate()
                ->value('booking_code');
            if ($taken) {
                throw new HttpException(422, "Slot {$court->name} {$start->translatedFormat('d M')} {$start->format('H:i')}-{$end->format('H:i')} sudah terisi ({$taken}).");
            }

            $before = ($b->court?->name ?? '-').', '.$b->booking_date->translatedFormat('D d M Y').' '.$b->start_time->format('H:i').'-'.$b->end_time->format('H:i');
            $delta = round($newHours - $oldHours, 2);
            if ($delta != 0.0) {
                app(\App\Services\Membership\MembershipBalanceService::class)->adjustQuota(
                    balanceId: $balance->id,
                    changeType: $delta > 0 ? 'DECREMENT' : 'REVERSAL',
                    quantity: abs($delta),
                    notes: "Jadwal Tetap diubah: durasi booking {$b->booking_code} ".($delta > 0 ? 'bertambah' : 'berkurang').' '.abs($delta).' jam',
                    relatedType: PadelBooking::class,
                    relatedId: $b->id,
                    performedBy: $actor->id,
                );
            }

            $this->releaseSlotCacheLocks($b);
            $fee = (float) app(\App\Services\Padel\PeakHourService::class)->courtFee($court, $start, $end)['fee'];
            $b->forceFill([
                'court_id' => $court->id,
                'booking_date' => $start->toDateString(),
                'start_time' => $start,
                'end_time' => $end,
                'member_hours_consumed' => $newHours,
                'member_discount_court' => $fee,
                'court_fee' => 0,
                'reschedule_count' => (int) $b->reschedule_count + 1,
            ])->save();

            if ($b->order_id) {
                \App\Models\Pos\OrderItem::where('order_id', $b->order_id)->where('item_type', 'PADEL')->where('reference_id', $b->id)
                    ->update(['item_name' => 'Sewa '.$court->name.' · Jadwal Tetap']);
            }

            \App\Services\Audit\ActivityLogger::record(
                module: 'PADEL',
                event: 'recurring.moved',
                description: "Booking {$b->booking_code} ikut perubahan Jadwal Tetap \"{$b->recurringSchedule?->name}\"",
                subject: $b,
                meta: array_filter([
                    'sebelum' => $before,
                    'sesudah' => $court->name.', '.$start->translatedFormat('D d M Y').' '.$start->format('H:i').'-'.$end->format('H:i'),
                    'selisih_jam_kuota' => $delta != 0.0 ? $delta : null,
                    'durasi_lama_dipertahankan' => $keptDuration ? 'ya (sisa kuota kurang)' : null,
                ], fn ($v) => $v !== null),
                causer: $actor,
            );

            return ['booking' => $b->fresh(['court']), 'kept_duration' => $keptDuration];
        });
    }
}
