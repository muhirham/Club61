<?php

namespace App\Services\Padel;

use App\Models\Padel\CourtEquipment;
use App\Models\Padel\EquipmentLoan;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelBookingEquipment;
use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Models\Pos\PosCashierShift;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Pos\PosPaymentProof;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Peminjaman Alat (raket / handuk = alat Sewa). Peminjaman dibuka otomatis saat stok alat sewa booking dipotong
 * (check-in, atau tambah alat ke booking yang sudah check-in), ditutup lewat halaman Peminjaman Alat dengan kondisi
 * per unit. Kerusakan / kehilangan karena disengaja → denda menunggu keputusan management → dibayar di POS kasir.
 */
class EquipmentLoanService
{
    public const PERMISSION_RETURN = 'return_equipment_loan';

    public const PERMISSION_DECIDE = 'decide_equipment_fine';

    /** Menerima pembayaran denda di POS = menerima pelunasan tagihan. */
    public const PERMISSION_PAY = 'settle_unpaid_booking';

    public const SOURCE_FINE = 'EQUIPMENT_FINE';

    /** Ketentuan peminjaman raket dari management (Vina, 2 Okt 2026) — tampil di halaman & slip. */
    public const RULES = [
        'Raket hanya dapat digunakan selama durasi booking lapangan.',
        'Setiap peminjaman raket wajib dicatat melalui staff/resepsionis.',
        'Raket tidak diperbolehkan dibawa keluar dari area venue.',
        'Raket wajib digunakan dengan baik dan sesuai fungsinya.',
        'Dilarang melempar, membanting, atau memukul raket ke lantai, kaca, net, maupun benda lainnya.',
        'Setelah selesai bermain, raket wajib dikembalikan langsung kepada staff.',
        'Staff akan melakukan pengecekan kondisi raket sebelum dan setelah pemakaian.',
        'Kerusakan akibat kelalaian atau penggunaan yang tidak semestinya menjadi tanggung jawab peminjam.',
        'Raket yang hilang atau mengalami kerusakan berat wajib diganti sesuai nilai penggantian yang ditetapkan management.',
        'Ketersediaan raket bersifat first come, first served.',
    ];

    public const FINE_NOTE = 'Kerusakan akibat pemakaian normal (normal wear & tear) tidak dikenakan denda. Penentuan kondisi dan tingkat kerusakan dilakukan oleh Management berdasarkan pengecekan raket sebelum dan sesudah pemakaian.';

    /**
     * Buka peminjaman untuk baris alat booking yang stoknya baru dipotong. Sekali per baris (aman dipanggil ulang).
     * Batas kembali = jam selesai booking (booking beberapa jam dalam satu order: jam selesai paling akhir).
     */
    public function openForBookingRow(PadelBookingEquipment $row, ?User $staff): ?EquipmentLoan
    {
        $row->loadMissing(['equipment', 'booking.user']);
        $booking = $row->booking;
        if (! $row->stock_deducted_at || ! $booking || ! $row->equipment?->isRental()) {
            return null;
        }

        $dueAt = $booking->order_id
            ? PadelBooking::where('order_id', $booking->order_id)->whereDate('booking_date', $booking->booking_date)->max('end_time')
            : null;

        return EquipmentLoan::firstOrCreate(['booking_equipment_id' => $row->id], [
            'equipment_id' => $row->equipment_id,
            'equipment_name' => $row->equipment->name,
            'booking_id' => $booking->id,
            'borrower_user_id' => $booking->user_id,
            'borrower_name' => $booking->user?->name ?? 'Customer',
            'borrower_phone' => $booking->user?->phone,
            'quantity' => (int) $row->quantity,
            'handed_out_by_id' => $staff?->id,
            'handed_out_at' => now(),
            'due_at' => $dueAt ?? $booking->end_time,
        ]);
    }

    /**
     * Terima alat kembali. $quantities = jumlah per kondisi (good / minor / major / lost), totalnya harus sama dengan
     * yang dipinjam. Baik & lecet ringan kembali ke stok; patah & hilang tidak. Ada denda → menunggu keputusan.
     *
     * @param  array<string, int|string|null>  $quantities
     */
    public function returnLoan(string $loanId, array $quantities, ?string $notes, User $staff): EquipmentLoan
    {
        if (! $staff->can(self::PERMISSION_RETURN)) {
            throw new HttpException(403, 'Akses ditolak: Anda tidak memiliki izin [return_equipment_loan] untuk menerima pengembalian alat.');
        }
        $qty = [];
        foreach (array_keys(EquipmentLoan::CONDITIONS) as $key) {
            $value = $quantities[$key] ?? 0;
            if (! is_numeric($value) || (int) $value != $value || (int) $value < 0) {
                throw new HttpException(422, 'Jumlah per kondisi harus angka bulat 0 atau lebih.');
            }
            $qty[$key] = (int) $value;
        }
        $notes = trim((string) $notes);

        return DB::transaction(function () use ($loanId, $qty, $notes, $staff) {
            $loan = EquipmentLoan::whereKey($loanId)->lockForUpdate()->first();
            if (! $loan) {
                throw new HttpException(404, 'Peminjaman tidak ditemukan.');
            }
            if (! $loan->isOpen()) {
                throw new HttpException(409, 'Alat ini sudah diterima kembali sebelumnya. Muat ulang halaman.');
            }
            $received = array_sum($qty);
            if ($received !== $loan->quantity) {
                throw new HttpException(422, "Jumlah yang diterima ({$received}) harus sama dengan yang dipinjam ({$loan->quantity}).");
            }
            $damaged = $qty['minor'] + $qty['major'] + $qty['lost'];
            if ($damaged > 0 && mb_strlen($notes) < 5) {
                throw new HttpException(422, 'Tulis catatan kondisi (minimal 5 karakter) untuk alat yang rusak / hilang.');
            }

            $equipment = $loan->equipment_id ? CourtEquipment::whereKey($loan->equipment_id)->lockForUpdate()->first() : null;
            $restock = 0;
            $fine = 0.0;
            foreach (EquipmentLoan::CONDITIONS as $key => $condition) {
                if ($condition['restock']) {
                    $restock += $qty[$key];
                }
                if ($condition['fine'] && $equipment) {
                    $fine += $qty[$key] * (float) ($equipment->{$condition['fine']} ?? 0);
                }
            }
            if ($equipment && $restock > 0) {
                $equipment->increment('stock_quantity', $restock);
            }
            // Baris alat booking ikut ditandai kembali (dipakai Kelola Pemesanan & retur lama).
            if ($loan->booking_equipment_id) {
                PadelBookingEquipment::whereKey($loan->booking_equipment_id)->whereNull('returned_at')->update(['returned_at' => now()]);
            }

            $loan->update([
                'returned_at' => now(),
                'returned_by_id' => $staff->id,
                'qty_good' => $qty['good'],
                'qty_minor' => $qty['minor'],
                'qty_major' => $qty['major'],
                'qty_lost' => $qty['lost'],
                'return_notes' => $notes !== '' ? mb_substr($notes, 0, 500) : null,
                'fine_status' => $damaged > 0 ? EquipmentLoan::FINE_PENDING : EquipmentLoan::FINE_NONE,
                'fine_proposed' => round($fine, 2),
                'fine_amount' => round($fine, 2),
            ]);

            $summary = collect(EquipmentLoan::CONDITIONS)->filter(fn ($c, $key) => $qty[$key] > 0)
                ->map(fn ($c, $key) => "{$qty[$key]} {$c['label']}")->implode(', ');
            ActivityLogger::record(
                module: 'PADEL',
                event: 'equipment.loan_returned',
                description: "Menerima kembali {$loan->quantity} {$loan->equipment_name} dari {$loan->borrower_name}: {$summary}"
                    .($damaged > 0 ? ' — denda menunggu keputusan management ('.ActivityLogger::rupiah($fine).')' : ''),
                subject: $loan,
                severity: $damaged > 0 ? ActivityLogger::WARNING : ActivityLogger::INFO,
                meta: array_filter(['alat' => $loan->equipment_name, 'kondisi' => $summary, 'catatan' => $notes ?: null, 'usulan_denda' => $damaged > 0 ? ActivityLogger::rupiah($fine) : null]),
                causer: $staff,
            );

            return $loan->fresh();
        });
    }

    /**
     * Keputusan management atas denda: APPROVE (nominal boleh diubah) atau WAIVE (hapus). Alasan wajib.
     */
    public function decideFine(string $loanId, string $decision, ?float $amount, string $note, User $by): EquipmentLoan
    {
        if (! $by->can(self::PERMISSION_DECIDE)) {
            throw new HttpException(403, 'Akses ditolak: Anda tidak memiliki izin [decide_equipment_fine] untuk memutuskan denda alat.');
        }
        $decision = strtoupper($decision);
        $note = trim($note);
        if (! in_array($decision, ['APPROVE', 'WAIVE'], true)) {
            throw new HttpException(422, 'Pilih setujui atau hapus denda.');
        }
        if (mb_strlen($note) < 5) {
            throw new HttpException(422, 'Tulis alasan keputusan (minimal 5 karakter).');
        }
        if ($decision === 'APPROVE' && ($amount === null || $amount < 1)) {
            throw new HttpException(422, 'Nominal denda yang disetujui minimal Rp 1. Kalau tidak ada denda, pilih Hapus Denda.');
        }

        return DB::transaction(function () use ($loanId, $decision, $amount, $note, $by) {
            $loan = EquipmentLoan::whereKey($loanId)->lockForUpdate()->first();
            if (! $loan || $loan->fine_status !== EquipmentLoan::FINE_PENDING) {
                throw new HttpException(409, 'Denda ini sudah diputuskan atau tidak ada. Muat ulang halaman.');
            }
            $approved = $decision === 'APPROVE';
            $loan->update([
                'fine_status' => $approved ? EquipmentLoan::FINE_APPROVED : EquipmentLoan::FINE_WAIVED,
                'fine_amount' => $approved ? round((float) $amount, 2) : 0,
                'fine_decided_by_id' => $by->id,
                'fine_decided_at' => now(),
                'fine_decision_note' => mb_substr($note, 0, 500),
            ]);

            ActivityLogger::record(
                module: 'PADEL',
                event: $approved ? 'equipment.fine_approved' : 'equipment.fine_waived',
                description: ($approved ? 'Menyetujui denda ' : 'Menghapus denda ')."{$loan->equipment_name} ({$loan->borrower_name})"
                    .($approved ? ': '.ActivityLogger::rupiah((float) $amount) : '').' — '.$note,
                subject: $loan,
                severity: ActivityLogger::WARNING,
                meta: ['usulan' => ActivityLogger::rupiah((float) $loan->fine_proposed), 'diputuskan' => $approved ? ActivityLogger::rupiah((float) $amount) : 'Dihapus', 'alasan' => $note],
                causer: $by,
            );

            return $loan->fresh();
        });
    }

    /** Data tagihan denda untuk layar kasir (Kasir Pro Shop tab Denda). */
    public function fineBillFor(EquipmentLoan $loan): array
    {
        $loan->loadMissing('booking.court');

        return [
            'kind' => 'FINE',
            'loan_id' => $loan->id,
            'booking_id' => null,
            'code' => $loan->booking?->booking_code ?? Str::upper(Str::substr($loan->id, -6)),
            'customer' => $loan->borrower_name,
            'phone' => $loan->borrower_phone,
            'court' => $loan->equipment_name,
            'schedule' => $this->conditionSummary($loan),
            'type' => 'Denda Alat',
            'schedule_before' => null,
            'amount' => $loan->fineDue(),
        ];
    }

    public function conditionSummary(EquipmentLoan $loan): string
    {
        return collect(EquipmentLoan::CONDITIONS)
            ->filter(fn ($c, $key) => $key !== 'good' && (int) $loan->{'qty_'.$key} > 0)
            ->map(fn ($c, $key) => $loan->{'qty_'.$key}.'× '.$c['label'])->implode(', ') ?: 'Kondisi baik';
    }

    /**
     * Terima pembayaran denda yang sudah disetujui di kasir (shift loket yang buka, bukti EDC / QRIS diketik kasir).
     * Nominal yang tampil di layar dicek ulang di dalam kunci; denda yang sudah lunas tidak bisa dibayar dua kali.
     */
    public function payFine(string $loanId, float $expectedAmount, string $method, array $proof, User $cashier): Payment
    {
        if (! $cashier->can(self::PERMISSION_PAY)) {
            throw new HttpException(403, 'Akses ditolak: Anda tidak memiliki izin [settle_unpaid_booking] untuk menerima pembayaran denda.');
        }

        return DB::transaction(function () use ($loanId, $expectedAmount, $method, $proof, $cashier) {
            $loan = EquipmentLoan::whereKey($loanId)->lockForUpdate()->first();
            if (! $loan) {
                throw new HttpException(404, 'Peminjaman tidak ditemukan.');
            }
            $due = $loan->fineDue();
            if ($due <= 0) {
                throw new HttpException(422, 'Tidak ada denda yang perlu dibayar untuk peminjaman ini (belum disetujui, dihapus, atau sudah lunas).');
            }
            if (abs($due - $expectedAmount) > 1) {
                throw new HttpException(409, 'Nominal denda berubah menjadi Rp '.number_format($due, 0, ',', '.').'. Buka ulang tagihannya dari halaman Peminjaman Alat.');
            }
            if (! PosCashierShift::getActiveShift('PADEL_FRONTDESK')) {
                throw new HttpException(422, 'Tidak ada shift kasir yang aktif untuk loket [PADEL_FRONTDESK]. Buka shift terlebih dahulu.');
            }
            $proofPayload = PosPaymentProof::validate($method, $proof, $due);

            // Denda = penggantian nilai alat: tanpa pajak & biaya layanan.
            $order = Order::create([
                'order_number' => 'ORD-PAD-'.strtoupper(Str::random(10)),
                'user_id' => $loan->borrower_user_id,
                'cashier_id' => $cashier->id,
                'order_type' => 'WALK_IN',
                'subtotal' => $due,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'service_charge' => 0,
                'grand_total' => $due,
                'payment_status' => 'UNPAID',
            ]);
            $order->items()->create([
                'item_type' => 'EQUIPMENT_FINE',
                'reference_id' => $loan->id,
                'item_name' => mb_substr("Denda {$loan->equipment_name} — {$this->conditionSummary($loan)}", 0, 150),
                'quantity' => 1,
                'unit_price' => $due,
                'subtotal' => $due,
            ]);
            $loan->update(['fine_order_id' => $order->id]);

            app(\App\Services\Payment\PaymentOrchestratorService::class)->markOrderAsPaid($order, [
                'payment_gateway' => 'CASHIER_POS',
                'counter' => 'PADEL_FRONTDESK',
                'transaction_id' => $order->order_number,
                'payment_method' => strtoupper($method),
                'amount' => $due,
                'cashier_id' => $cashier->id,
                'payload_log' => [
                    'cashier_id' => $cashier->id,
                    'cashier_name' => $cashier->name,
                    'source' => self::SOURCE_FINE,
                    'channel' => 'FRONTDESK_CASHIER',
                    'equipment_loan_id' => $loan->id,
                ] + $proofPayload,
            ]);

            ActivityLogger::record(
                module: 'PADEL',
                event: 'equipment.fine_paid',
                description: "Menerima pembayaran denda {$loan->equipment_name} dari {$loan->borrower_name}: ".ActivityLogger::rupiah($due)." — transaksi {$order->order_number}",
                subject: $loan,
                meta: ['total' => ActivityLogger::rupiah($due), 'cara_bayar' => PosPaymentProof::METHODS[strtoupper($method)] ?? $method, 'order' => $order->order_number],
                causer: $cashier,
            );

            return Payment::where('order_id', $order->id)->where('status', 'SUCCESS')->latest()->firstOrFail();
        });
    }
}
