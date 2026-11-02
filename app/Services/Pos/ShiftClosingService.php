<?php

namespace App\Services\Pos;

use App\Models\Pos\Payment;
use App\Models\Pos\PosCashierShift;
use App\Models\Pos\PosEdcSettlement;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Tutup shift kasir — sama untuk POS Padel & POS F&B (tiap loket punya mesin EDC sendiri).
 *
 *   1. BLIND: kasir mengetik angka dari struk settlement / Audit Report EDC & aplikasi QRIS tanpa melihat angka sistem
 *      (baris dikirim ke browser tanpa nominal; tanda tangan angka sistem = HMAC app key).
 *   2. Angka sistem EDC = semua transaksi mesin itu di loket ini SEJAK settlement terakhir (lintas shift): ganti shift
 *      tanpa settle (Audit Report) & settle di akhir hari (struk settlement) dicocokkan dengan angka yang sama.
 *      Baris lain (QRIS stiker, transfer) = transaksi shift ini; QR/VA otomatis = diverifikasi gateway.
 *   3. Toleransi Rp0. Cocok → MATCHED. Selisih → angka yang diketik DIBEKUKAN (tidak bisa diketik ulang setelah angka
 *      sistem terlihat), catatan wajib, shift ditutup NEEDS_REVIEW → diputuskan pemegang izin approve_shift_closing.
 *
 * Kolom ringkasan lama (total_qris_sales, dst.) tetap diisi dari calculateSummary() seperti sebelumnya.
 */
class ShiftClosingService
{
    /** Diketik dari struk settlement / Audit Report mesin EDC. */
    public const TYPE_EDC = 'EDC';

    /** Diketik dari aplikasi merchant / mutasi untuk jam shift ini (QRIS stiker, transfer). */
    public const TYPE_MANUAL = 'MANUAL';

    /** QR / VA otomatis di layar kasir: lunas dipastikan gateway, tidak diketik. */
    public const TYPE_AUTO = 'AUTO';

    /** Benefit Rp0 (kuota member, voucher): bukan uang, tidak diketik. */
    public const TYPE_INFO = 'INFO';

    /** Transfer Pelunasan Tagihan Coaching (Modul 31): langsung ke rekening, dicek admin pada mutasi, tidak diketik kasir. */
    public const TYPE_TRANSFER = 'TRANSFER';

    /** Mesin EDC di-settle saat tutup shift ini (cocokkan dengan struk settlement). */
    public const MODE_SETTLE = 'SETTLE';

    /** Ganti shift tanpa settle (cocokkan dengan Audit Report). */
    public const MODE_AUDIT = 'AUDIT';

    private const AUTO_QRIS_KEYS = ['QRIS_MIDTRANS_QRIS', 'QRIS_DOKU_QRIS'];

    // ── Baris & angka sistem ──────────────────────────────────────────────────────────────────────────────────────

    /**
     * Baris yang ditampilkan ke kasir — TANPA jumlah transaksi & nominal (blind).
     *
     * @return array<int, array{key: string, label: string, source: string, type: string, terminal: ?string}>
     */
    public function rows(PosCashierShift $shift): array
    {
        return self::stripAmounts($this->systemRows($shift));
    }

    /**
     * @param  array<string, ?string>|null  $batchStarts  terminal => awal batch (ISO) — null = transaksi shift ini saja
     * @return array<int, array{key: string, label: string, source: string, type: string, terminal: ?string, count: int, system_amount: float}>
     */
    public function systemRows(PosCashierShift $shift, ?array $batchStarts = null, ?CarbonInterface $until = null): array
    {
        $rows = [];
        $add = function (array $category, string $type, ?string $terminal, float $amount, int $count) use (&$rows) {
            $rows[$category['key']] ??= $category + ['type' => $type, 'terminal' => $terminal, 'count' => 0, 'system_amount' => 0.0];
            $rows[$category['key']]['count'] += $count;
            $rows[$category['key']]['system_amount'] += $amount;
        };

        foreach ($shift->payments()->where('status', 'SUCCESS')->get() as $payment) {
            $category = PosCashierShift::categorize($payment);
            if (self::edcTerminal($category['key']) !== null) {
                continue; // EDC dihitung per batch mesin di bawah
            }
            $add($category, self::typeOf($category['key']), null, (float) $payment->amount, 1);
        }

        $batchStarts ??= $this->batchStarts($shift);
        foreach ($this->edcBatchPayments($shift, $batchStarts, $until) as [$payment, $category, $terminal]) {
            $add($category, self::TYPE_EDC, $terminal, (float) $payment->amount, 1);
        }

        // Baris = metode yang ada transaksinya (shift ini / batch EDC). Salah klik kasir dibetulkan admin saat itu juga
        // (shift masih buka) lewat PaymentMethodCorrection; kalau baru ketahuan saat tutup, kasir menjelaskan di catatan.

        // Tiap mesin yang muncul: baris debit & kredit selalu ada (kasir mengetik 0 kalau kosong) — keberadaan baris tidak
        // membocorkan isi struk.
        $terminals = array_unique(array_filter(array_column($rows, 'terminal')));
        foreach ($terminals as $terminal) {
            foreach (['DEBIT', 'CREDIT'] as $cardType) {
                $category = PosCashierShift::categorize((new Payment)->forceFill([
                    'payment_method' => $cardType === 'CREDIT' ? 'CREDIT_CARD' : 'DEBIT_CARD',
                    'payload_log' => ['edc_details' => ['terminal' => $terminal, 'card_type' => $cardType]],
                ]));
                $add($category, self::TYPE_EDC, $terminal, 0.0, 0);
            }
        }

        // Ada transaksi tapi semuanya Rp0 (kuota member / voucher) = benefit, bukan uang. Baris kartu kosong di mesin EDC
        // (count 0) tetap diketik.
        foreach ($rows as &$row) {
            if ($row['type'] === self::TYPE_MANUAL && $row['count'] > 0 && abs($row['system_amount']) < 0.005) {
                $row['type'] = self::TYPE_INFO;
            }
        }
        unset($row);

        ksort($rows);

        return array_values($rows);
    }

    /**
     * Awal batch tiap mesin EDC loket ini = settlement terakhir yang tercatat. Mesin tanpa catatan settlement (sebelum
     * fitur ini) = transaksi shift ini saja.
     *
     * @return array<string, string> terminal => ISO
     */
    public function batchStarts(PosCashierShift $shift): array
    {
        return PosEdcSettlement::query()
            ->where('counter', $shift->counter)
            ->selectRaw('terminal, MAX(settled_at) as last_settled_at')
            ->groupBy('terminal')
            ->pluck('last_settled_at', 'terminal')
            ->map(fn ($value) => Carbon::parse($value)->toIso8601String())
            ->all();
    }

    /** HMAC angka sistem — dikirim ke browser untuk mendeteksi transaksi baru saat closing tanpa membocorkan angkanya. */
    public function signature(PosCashierShift $shift, ?array $systemRows = null): string
    {
        $systemRows ??= $this->systemRows($shift);
        $data = $shift->getKey().'|'.collect($systemRows)
            ->map(fn (array $row) => $row['key'].':'.$row['count'].':'.number_format($row['system_amount'], 2, '.', ''))
            ->implode('|');

        return hash_hmac('sha256', $data, (string) config('app.key'));
    }

    /** @return array<int, string> terminal EDC yang harus dipilih modenya (settle / belum settle) */
    public static function terminalsOf(array $rows): array
    {
        return array_values(array_unique(array_filter(array_column($rows, 'terminal'))));
    }

    // ── Tutup shift ──────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, string>  $inputs  key baris => nominal yang diketik kasir
     * @param  array<string, string>  $modes  terminal => SETTLE | AUDIT
     * @return array{status: string, ...}
     *                                    NO_SHIFT · PENDING_AUTO · CHANGED (rows, signature) · INVALID (label) · MODE_MISSING (label)
     *                                    · MISMATCH (result; angka dibekukan) · NEED_NOTES (result) · CLOSED (shift, summary, result, review_status)
     */
    public function submit(string $shiftId, User $by, array $inputs, array $modes, string $notes, ?string $signature): array
    {
        if (! $by->can('close_pos_shift')) {
            throw new HttpException(403, 'Akses ditolak: Anda tidak memiliki izin [close_pos_shift] untuk menutup sesi shift kasir.');
        }

        return DB::transaction(function () use ($shiftId, $by, $inputs, $modes, $notes, $signature) {
            $shift = PosCashierShift::whereKey($shiftId)->lockForUpdate()->first();
            if (! $shift || $shift->status !== 'OPEN') {
                return ['status' => 'NO_SHIFT'];
            }
            if ($shift->pendingAutoPaymentCount() > 0) {
                return ['status' => 'PENDING_AUTO'];
            }

            // Percobaan pertama sudah selisih → angka yang diketik dipakai apa adanya, hanya catatan yang diminta.
            $frozen = $shift->closing_inputs['first_attempt'] ?? null;
            if (is_array($frozen)) {
                $result = $this->reconcile($this->systemRows($shift), $frozen['inputs'] ?? [], $frozen['modes'] ?? [], $frozen['rows'] ?? []);
                if (trim($notes) === '') {
                    return ['status' => 'NEED_NOTES', 'result' => $result];
                }

                return $this->close($shift, $by, $frozen['inputs'] ?? [], $frozen['modes'] ?? [], $notes, forceReview: true, typedRows: $frozen['rows'] ?? []);
            }

            $system = $this->systemRows($shift);
            if ($signature !== null && ! hash_equals($this->signature($shift, $system), $signature)) {
                return ['status' => 'CHANGED', 'rows' => self::stripAmounts($system), 'signature' => $this->signature($shift, $system)];
            }

            $clean = [];
            foreach ($system as $row) {
                if (! in_array($row['type'], [self::TYPE_EDC, self::TYPE_MANUAL], true)) {
                    continue;
                }
                $normalized = str_replace(['.', ',', ' ', 'Rp', 'rp'], '', trim((string) ($inputs[$row['key']] ?? '')));
                if ($normalized === '' || ! ctype_digit($normalized)) {
                    return ['status' => 'INVALID', 'label' => $row['label']];
                }
                $clean[$row['key']] = (int) $normalized;
            }

            $cleanModes = [];
            foreach (self::terminalsOf($system) as $terminal) {
                $mode = strtoupper((string) ($modes[$terminal] ?? ''));
                if (! in_array($mode, [self::MODE_SETTLE, self::MODE_AUDIT], true)) {
                    return ['status' => 'MODE_MISSING', 'label' => self::terminalLabel($terminal)];
                }
                $cleanModes[$terminal] = $mode;
            }

            $result = $this->reconcile($system, $clean, $cleanModes);
            if ($result['matched']) {
                return $this->close($shift, $by, $clean, $cleanModes, $notes, forceReview: false, typedRows: self::stripAmounts($system));
            }

            // Selisih: bekukan angka yang diketik sebelum angka sistem ditampilkan.
            $shift->update(['closing_inputs' => ['first_attempt' => [
                'inputs' => $clean,
                'modes' => $cleanModes,
                'rows' => self::stripAmounts($system),
                'at' => now()->toIso8601String(),
                'by_id' => $by->getKey(),
                'by_name' => $by->name,
                'difference' => $result['difference'],
            ]]]);

            return ['status' => 'MISMATCH', 'result' => $result];
        });
    }

    /** Dipanggil di dalam transaksi submit() (shift sudah dikunci). */
    private function close(PosCashierShift $shift, User $by, array $inputs, array $modes, string $notes, bool $forceReview, array $typedRows): array
    {
        $batchStarts = $this->batchStarts($shift);
        $closedAt = now();
        $result = $this->reconcile($this->systemRows($shift, $batchStarts), $inputs, $modes, $typedRows);
        $reviewStatus = ! $forceReview && $result['matched'] ? PosCashierShift::REVIEW_MATCHED : PosCashierShift::REVIEW_NEEDS_REVIEW;
        if ($reviewStatus === PosCashierShift::REVIEW_NEEDS_REVIEW && trim($notes) === '') {
            return ['status' => 'NEED_NOTES', 'result' => $result];
        }

        $summary = $shift->calculateSummary();
        $closingInputs = $shift->closing_inputs ?? [];
        $closingInputs['final'] = [
            'inputs' => $inputs,
            'modes' => $modes,
            // Baris yang diketik kasir (tanpa angka sistem) — tetap ikut dihitung ulang walau transaksinya dipindah.
            'rows' => self::stripAmounts($result['rows']),
            // Untuk menghitung ulang setelah koreksi admin: awal batch tiap mesin & batas waktu transaksi.
            'batch_starts' => $batchStarts,
            'until' => $closedAt->toIso8601String(),
        ];

        $shift->update([
            'status' => 'CLOSED',
            'closed_by_id' => $by->getKey(),
            'closed_at' => Carbon::now('Asia/Jakarta'),
            'expected_cash' => $summary['expected_cash'],
            'actual_cash' => 0.00,
            'cash_difference' => 0.00,
            'total_cash_sales' => $summary['total_cash_sales'],
            'total_edc_bca_sales' => $summary['total_edc_bca_sales'],
            'total_edc_mandiri_sales' => $summary['total_edc_mandiri_sales'],
            'total_qris_sales' => $summary['total_qris_sales'],
            'total_other_sales' => $summary['total_other_sales'],
            'total_sales' => $summary['total_sales'],
            'total_transactions' => $summary['total_transactions'],
            'settlement_reconciliation' => $result['rows'],
            'settlement_difference' => $result['difference'],
            'closing_inputs' => $closingInputs,
            'review_status' => $reviewStatus,
            'closing_notes' => trim($notes) ?: null,
        ]);

        foreach ($modes as $terminal => $mode) {
            if ($mode === self::MODE_SETTLE) {
                PosEdcSettlement::create([
                    'counter' => $shift->counter,
                    'terminal' => $terminal,
                    'pos_shift_id' => $shift->getKey(),
                    'settled_at' => $closedAt,
                    'settled_by_id' => $by->getKey(),
                    'source' => PosEdcSettlement::SOURCE_CLOSING,
                ]);
            } elseif (! array_key_exists($terminal, $batchStarts)) {
                // Mesin belum pernah tercatat settle & tidak di-settle sekarang: batch dimulai di jam buka shift ini,
                // jadi shift berikutnya mencocokkan transaksi kedua shift dengan Audit Report.
                PosEdcSettlement::create([
                    'counter' => $shift->counter,
                    'terminal' => $terminal,
                    'pos_shift_id' => $shift->getKey(),
                    'settled_at' => $shift->opened_at->copy()->subSecond(),
                    'settled_by_id' => $by->getKey(),
                    'source' => PosEdcSettlement::SOURCE_BASELINE,
                ]);
            }
        }

        return ['status' => 'CLOSED', 'shift' => $shift, 'summary' => $summary, 'result' => $result, 'review_status' => $reviewStatus];
    }

    /**
     * @return array{matched: bool, difference: float, rows: array<int, array<string, mixed>>}
     */
    public function reconcile(array $systemRows, array $inputs, array $modes, array $typedRows = []): array
    {
        // Baris yang sudah diketik kasir tetap dihitung walau transaksinya dipindah admin (sistem jadi Rp0) — angka ketikan
        // tidak boleh hilang dari rekonsiliasi.
        $present = array_flip(array_column($systemRows, 'key'));
        foreach ($typedRows as $meta) {
            if (isset($meta['key']) && ! isset($present[$meta['key']]) && array_key_exists($meta['key'], $inputs)
                && in_array($meta['type'] ?? null, [self::TYPE_EDC, self::TYPE_MANUAL], true)) {
                $systemRows[] = array_intersect_key($meta, array_flip(['key', 'label', 'source', 'type', 'terminal'])) + ['terminal' => null, 'count' => 0, 'system_amount' => 0.0];
                $present[$meta['key']] = true;
            }
        }

        $rows = [];
        $difference = 0.0;
        $matched = true;

        foreach ($systemRows as $row) {
            $typed = in_array($row['type'], [self::TYPE_EDC, self::TYPE_MANUAL], true);
            $settled = $typed ? (float) ($inputs[$row['key']] ?? 0) : (float) $row['system_amount'];
            $rowDifference = round($settled - (float) $row['system_amount'], 2);
            $difference += $rowDifference;
            $matched = $matched && abs($rowDifference) < 0.005;

            $rows[] = $row + [
                'settled_amount' => $settled,
                // false = baris baru muncul setelah kasir mengetik (koreksi admin) — tidak pernah diketik kasir.
                'typed_by_cashier' => $typed && array_key_exists($row['key'], $inputs),
                'difference' => $rowDifference,
                'mode' => $row['terminal'] ? ($modes[$row['terminal']] ?? null) : null,
            ];
        }

        return ['matched' => $matched, 'difference' => round($difference, 2), 'rows' => $rows];
    }

    /**
     * Hitung ulang rekonsiliasi shift yang sudah ditutup (setelah koreksi metode bayar oleh admin) memakai angka yang
     * diketik kasir & batas batch saat ditutup. Status review tidak diubah — tetap diputuskan admin.
     */
    public function recompute(PosCashierShift $shift): void
    {
        $final = $shift->closing_inputs['final'] ?? null;
        if ($shift->status !== 'CLOSED' || ! is_array($final)) {
            return;
        }

        $until = isset($final['until']) ? Carbon::parse($final['until']) : $shift->closed_at;
        $result = $this->reconcile(
            $this->systemRows($shift, $final['batch_starts'] ?? [], $until),
            $final['inputs'] ?? [],
            $final['modes'] ?? [],
            $final['rows'] ?? ($shift->settlement_reconciliation ?? []),
        );

        $shift->update([
            'settlement_reconciliation' => $result['rows'],
            'settlement_difference' => $result['difference'],
        ]);
    }

    /**
     * Data Z-Report dari shift yang sudah ditutup (cetak ulang admin) — format sama dengan laporan POS saat tutup shift.
     * Rekonsiliasi = yang tersimpan sekarang (sudah termasuk hitung ulang setelah koreksi metode).
     */
    public static function reportData(PosCashierShift $shift): array
    {
        $shift->loadMissing(['openedBy:id,name', 'closedBy:id,name', 'reviewedBy:id,name']);
        $format = fn ($at) => $at ? Carbon::parse($at)->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') : '-';

        return [
            'shift_number' => $shift->shift_number,
            'counter' => $shift->counter,
            'opened_by' => $shift->openedBy?->name ?? 'Kasir',
            'closed_by' => $shift->closedBy?->name ?? 'Kasir',
            'opened_at' => $format($shift->opened_at),
            'closed_at' => $format($shift->closed_at),
            'total_sales' => (float) $shift->total_sales,
            'total_transactions' => (int) $shift->total_transactions,
            'reconciliation' => $shift->settlement_reconciliation ?? [],
            'total_difference' => (float) $shift->settlement_difference,
            'review_status' => $shift->review_status,
            'closing_notes' => $shift->closing_notes,
            'reviewed_by' => $shift->reviewedBy?->name,
            'reviewed_at' => $shift->reviewed_at ? $format($shift->reviewed_at) : null,
            'review_notes' => $shift->review_notes,
            'sales_recap_views' => DailySalesRecapService::viewsDuringShift($shift),
        ];
    }

    // ── Review ───────────────────────────────────────────────────────────────────────────────────────────────────

    /** Setujui / tolak shift NEEDS_REVIEW. Penutup shift tidak memutuskan shift-nya sendiri (kecuali super_admin). */
    public function review(string $shiftId, User $by, bool $approve, string $notes): PosCashierShift
    {
        if (! $by->can('approve_shift_closing')) {
            throw new HttpException(403, 'Akses ditolak: Anda tidak memiliki izin [approve_shift_closing].');
        }
        $notes = trim($notes);
        if (mb_strlen($notes) < 5) {
            throw new HttpException(422, 'Catatan keputusan wajib diisi (minimal 5 karakter).');
        }

        return DB::transaction(function () use ($shiftId, $by, $approve, $notes) {
            $shift = PosCashierShift::whereKey($shiftId)->lockForUpdate()->firstOrFail();
            if ($shift->status !== 'CLOSED' || $shift->review_status !== PosCashierShift::REVIEW_NEEDS_REVIEW) {
                throw new HttpException(409, 'Shift ini tidak sedang menunggu review. Muat ulang halaman.');
            }
            if ($shift->closed_by_id === $by->getKey() && ! $by->hasRole('super_admin')) {
                throw new HttpException(403, 'Shift ini Anda tutup sendiri — harus diputuskan admin lain atau super admin.');
            }

            $shift->update([
                'review_status' => $approve ? PosCashierShift::REVIEW_APPROVED : PosCashierShift::REVIEW_REJECTED,
                'reviewed_by_id' => $by->getKey(),
                'reviewed_at' => now(),
                'review_notes' => mb_substr($notes, 0, 2000),
            ]);

            ActivityLogger::record(
                module: 'POS',
                event: $approve ? 'shift.review_approved' : 'shift.review_rejected',
                description: ($approve ? 'Menyetujui' : 'MENOLAK').' selisih shift '.$shift->shift_number.' ('
                    .ActivityLogger::rupiah((float) $shift->settlement_difference).'): '.$notes,
                subject: $shift,
                meta: [
                    'loket' => $shift->counter,
                    'selisih' => (float) $shift->settlement_difference,
                    'catatan_kasir' => $shift->closing_notes,
                    'catatan_review' => $notes,
                ],
                severity: $approve ? ActivityLogger::WARNING : ActivityLogger::CRITICAL,
            );

            return $shift;
        });
    }

    // ── Pengingat ────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Mesin EDC loket ini yang punya transaksi SETELAH settlement terakhir dan sebelum hari ini (WIB) — belum di-settle
     * sejak kemarin. Mesin tanpa catatan settlement sama sekali tidak diperingatkan (data sebelum fitur ini).
     *
     * @return array<string, string> terminal => label
     */
    public function unsettledFromPreviousDays(string $counter): array
    {
        $todayStart = Carbon::now('Asia/Jakarta')->startOfDay()->setTimezone(config('app.timezone'));
        $unsettled = [];

        foreach ($this->lastSettlements($counter) as $terminal => $settledAt) {
            $exists = Payment::query()->where('status', 'SUCCESS')
                ->whereIn('pos_shift_id', PosCashierShift::where('counter', $counter)->select('id'))
                ->where('paid_at', '>', $settledAt)
                ->where('paid_at', '<', $todayStart)
                ->get()
                ->contains(fn (Payment $p) => self::edcTerminal(PosCashierShift::categorize($p)['key']) === $terminal);
            if ($exists) {
                $unsettled[$terminal] = self::terminalLabel($terminal);
            }
        }

        return $unsettled;
    }

    /** Kasir menyatakan sudah men-settle mesin ini (saat buka shift). */
    public function recordOpeningSettlement(PosCashierShift $shift, string $terminal, User $by): void
    {
        PosEdcSettlement::create([
            'counter' => $shift->counter,
            'terminal' => $terminal,
            'pos_shift_id' => $shift->getKey(),
            'settled_at' => now(),
            'settled_by_id' => $by->getKey(),
            'source' => PosEdcSettlement::SOURCE_OPENING,
        ]);
    }

    /**
     * Peringatan untuk layar POS: shift dibuka sejak hari sebelumnya / > 12 jam, EDC belum di-settle menjelang 22.00.
     *
     * @return array<int, string>
     */
    public function warnings(?PosCashierShift $shift): array
    {
        if (! $shift || $shift->status !== 'OPEN') {
            return [];
        }

        $warnings = [];
        $now = Carbon::now('Asia/Jakarta');
        $openedAt = $shift->opened_at->copy()->setTimezone('Asia/Jakarta');
        if ($openedAt->lt($now->copy()->startOfDay()) || $openedAt->diffInHours($now) >= 12) {
            $warnings[] = 'Shift '.$shift->shift_number.' dibuka sejak '.$openedAt->format('d/m H:i').' WIB — tutup shift ini dulu supaya transaksi hari baru tidak tercampur.';
        }

        if ((int) $now->format('H') >= 21) {
            // Layar POS di-render ulang tiap klik — dihitung paling sering sekali per menit.
            $pending = Cache::remember('pos_shift_edc_pending:'.$shift->getKey(), 60, fn () => collect($this->systemRows($shift))
                ->filter(fn (array $row) => $row['type'] === self::TYPE_EDC && $row['count'] > 0)
                ->pluck('terminal')->unique()->map(fn ($t) => self::terminalLabel($t))->implode(', '));
            if ($pending !== '') {
                $warnings[] = "Mesin {$pending} belum di-settle. Settle sebelum 22.00 WIB supaya dana cair H+1.";
            }
        }

        // Modul 31: tagihan berkala Per Shift yang dicatat di shift ini jatuh tempo saat shift ditutup.
        if ($shift->counter === 'PADEL_FRONTDESK') {
            $academies = app(\App\Services\Coaching\CoachingBillingService::class)->unpaidSessions()
                ->whereHas('coachingBooking', fn ($q) => $q->where('cashier_shift_id', $shift->getKey())
                    ->where('billing_cycle', \App\Models\Coaching\CoachingAcademy::CYCLE_SHIFT))
                ->with('coachingBooking.academy:id,name')->get()
                ->map(fn ($b) => $b->coachingBooking->academy?->name ?? $b->coachingBooking->academy_name)->unique()->filter();
            if ($academies->isNotEmpty()) {
                $warnings[] = 'Tagihan coaching Per Shift dari shift ini belum dilunasi ('.$academies->implode(', ').'). Lakukan Pelunasan Tagihan Coaching sebelum menutup shift.';
            }
        }

        return $warnings;
    }

    // ── Bantu ────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Pembayaran EDC loket ini yang masuk batch mesinnya: sejak settlement terakhir (lintas shift), atau — mesin tanpa
     * catatan settlement — transaksi shift ini saja.
     *
     * @return array<int, array{0: Payment, 1: array, 2: string}>
     */
    private function edcBatchPayments(PosCashierShift $shift, array $batchStarts, ?CarbonInterface $until): array
    {
        $starts = collect($batchStarts)->filter()->map(fn ($value) => Carbon::parse($value));
        $earliest = $starts->min();

        $payments = Payment::query()
            ->where('status', 'SUCCESS')
            ->where(function ($q) use ($shift, $earliest) {
                $q->where('pos_shift_id', $shift->getKey());
                if ($earliest) {
                    $q->orWhere(fn ($w) => $w->whereIn('pos_shift_id', PosCashierShift::where('counter', $shift->counter)->select('id'))
                        ->where('paid_at', '>', $earliest));
                }
            })
            ->get();

        $batch = [];
        foreach ($payments as $payment) {
            $category = PosCashierShift::categorize($payment);
            $terminal = self::edcTerminal($category['key']);
            if ($terminal === null) {
                continue;
            }
            $paidAt = $payment->paid_at ?? $payment->created_at;
            if ($until && $paidAt && $paidAt->gt($until)) {
                continue;
            }
            $start = $starts->get($terminal);
            $inBatch = $start
                ? ($paidAt && $paidAt->gt($start))
                : $payment->pos_shift_id === $shift->getKey();
            if ($inBatch) {
                $batch[] = [$payment, $category, $terminal];
            }
        }

        return $batch;
    }

    /** @return array<string, string> terminal => settled_at terakhir */
    private function lastSettlements(string $counter): array
    {
        return PosEdcSettlement::query()
            ->where('counter', $counter)
            ->selectRaw('terminal, MAX(settled_at) as last_settled_at')
            ->groupBy('terminal')
            ->pluck('last_settled_at', 'terminal')
            ->all();
    }

    /** Terminal EDC dari kunci kategori ("EDC_BCA_DEBIT" → "EDC_BCA"); null = bukan EDC. */
    public static function edcTerminal(string $key): ?string
    {
        return preg_match('/^(EDC_[A-Z0-9_]+?)_(DEBIT|CREDIT)$/', $key, $m) ? $m[1] : null;
    }

    private static function typeOf(string $key): string
    {
        if ($key === PosCashierShift::TRANSFER_COACHING_KEY) {
            return self::TYPE_TRANSFER;
        }

        return str_starts_with($key, 'AUTO_') || in_array($key, self::AUTO_QRIS_KEYS, true) ? self::TYPE_AUTO : self::TYPE_MANUAL;
    }

    public static function terminalLabel(string $terminal): string
    {
        return match ($terminal) {
            'EDC_BCA' => 'EDC BCA',
            'EDC_MANDIRI' => 'EDC Mandiri',
            'EDC_LAINNYA' => 'EDC Lainnya',
            default => $terminal,
        };
    }

    private static function stripAmounts(array $rows): array
    {
        return array_map(
            fn (array $row) => array_intersect_key($row, array_flip(['key', 'label', 'source', 'type', 'terminal'])),
            $rows,
        );
    }
}
