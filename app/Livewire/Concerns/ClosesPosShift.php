<?php

namespace App\Livewire\Concerns;

use App\Models\Pos\PosCashierShift;
use App\Services\Pos\ShiftClosingService;
use Carbon\Carbon;

/**
 * Tutup shift kasir yang sama untuk POS Padel (BookOfflineCourt) & POS F&B (FnbCashierTerminal) — lihat
 * ShiftClosingService. Kelas pemakai menyediakan properti computed `activeShift`, shiftCounter() & shiftNotice().
 *
 * Blind: browser hanya menerima daftar baris (tanpa jumlah transaksi & nominal) dan tanda tangan HMAC angka sistem.
 * Angka sistem baru terlihat setelah kasir menyimpan angkanya — kalau selisih, angka itu dibekukan di server.
 */
trait ClosesPosShift
{
    public bool $showCloseShiftModal = false;

    public bool $showShiftReportModal = false;

    public string $closingNotes = '';

    /** @var array<int, array{key: string, label: string, source: string, type: string, terminal: ?string}> tanpa nominal */
    public array $closingRows = [];

    /** @var array<string, string> key baris => nominal yang diketik kasir */
    public array $settlementInputs = [];

    /** @var array<string, string> terminal EDC => SETTLE | AUDIT */
    public array $edcModes = [];

    public ?string $closingSignature = null;

    /** Hasil cek setelah percobaan pertama selisih (angka yang diketik sudah dibekukan). */
    public ?array $closingMismatch = null;

    public ?array $reportShiftData = null;

    /** @var array<string, bool> mesin EDC yang dinyatakan sudah di-settle saat buka shift */
    public array $settledAtOpening = [];

    abstract protected function shiftCounter(): string;

    /** @param  string  $level  success | warning | danger */
    abstract protected function shiftNotice(string $level, string $title, string $body): void;

    public function prepareCloseShift(): void
    {
        $shift = $this->activeShift;
        if (! $shift) {
            $this->shiftNotice('warning', 'Tidak Ada Shift Aktif', 'Belum ada shift kasir yang terbuka saat ini.');

            return;
        }
        if ($shift->pendingAutoPaymentCount() > 0) {
            $this->shiftNotice('warning', 'Shift Belum Bisa Ditutup', PosCashierShift::PENDING_AUTO_PAYMENT_MESSAGE);

            return;
        }

        $service = app(ShiftClosingService::class);
        $this->closingRows = $service->rows($shift);
        $this->closingSignature = $service->signature($shift);
        $this->settlementInputs = collect($this->closingRows)
            ->filter(fn (array $row) => in_array($row['type'], [ShiftClosingService::TYPE_EDC, ShiftClosingService::TYPE_MANUAL], true))
            ->mapWithKeys(fn (array $row) => [$row['key'] => ''])
            ->all();
        $this->edcModes = collect(ShiftClosingService::terminalsOf($this->closingRows))->mapWithKeys(fn (string $t) => [$t => ''])->all();
        $this->closingNotes = '';

        // Sudah pernah dicek & selisih → tampilkan hasilnya (angka yang diketik tidak bisa diubah lagi).
        $frozen = $shift->closing_inputs['first_attempt'] ?? null;
        $this->closingMismatch = is_array($frozen)
            ? $service->reconcile($service->systemRows($shift), $frozen['inputs'] ?? [], $frozen['modes'] ?? [], $frozen['rows'] ?? [])
            : null;

        $this->showCloseShiftModal = true;
    }

    public function executeCloseShift(): void
    {
        $user = auth()->user();
        abort_unless(
            $user && $user->can('close_pos_shift'),
            403,
            'Akses ditolak: Anda tidak memiliki izin [close_pos_shift] untuk menutup sesi shift kasir.'
        );

        $shift = $this->activeShift;
        if (! $shift) {
            $this->showCloseShiftModal = false;

            return;
        }

        $result = app(ShiftClosingService::class)->submit(
            $shift->getKey(), $user, $this->settlementInputs, $this->edcModes, $this->closingNotes, $this->closingSignature,
        );

        switch ($result['status']) {
            case 'NO_SHIFT':
                $this->showCloseShiftModal = false;

                return;
            case 'PENDING_AUTO':
                $this->showCloseShiftModal = false;
                $this->shiftNotice('warning', 'Shift Belum Bisa Ditutup', PosCashierShift::PENDING_AUTO_PAYMENT_MESSAGE);

                return;
            case 'CHANGED':
                $previous = $this->settlementInputs;
                $this->closingRows = $result['rows'];
                $this->closingSignature = $result['signature'];
                $this->settlementInputs = collect($this->closingRows)
                    ->filter(fn (array $row) => in_array($row['type'], [ShiftClosingService::TYPE_EDC, ShiftClosingService::TYPE_MANUAL], true))
                    ->mapWithKeys(fn (array $row) => [$row['key'] => $previous[$row['key']] ?? ''])
                    ->all();
                $this->edcModes = collect(ShiftClosingService::terminalsOf($this->closingRows))
                    ->mapWithKeys(fn (string $t) => [$t => $this->edcModes[$t] ?? ''])->all();
                $this->shiftNotice('warning', 'Ada Transaksi Baru', 'Ada transaksi baru masuk saat closing. Cetak ulang struk settlement / Audit Report & cek ulang angka sebelum menutup shift.');

                return;
            case 'INVALID':
                $this->shiftNotice('warning', 'Nominal Belum Diisi', "Isi nominal untuk \"{$result['label']}\" sesuai struk / aplikasi (angka rupiah, 0 kalau tidak ada).");

                return;
            case 'MODE_MISSING':
                $this->shiftNotice('warning', 'Pilih Status Mesin EDC', "Pilih untuk {$result['label']}: Settle sekarang, atau Belum settle (ganti shift).");

                return;
            case 'MISMATCH':
                $this->closingMismatch = $result['result'];
                $this->shiftNotice('warning', 'Ada Selisih', 'Angka yang Anda ketik sudah tersimpan & tidak bisa diubah lagi. Tulis penjelasan selisih, lalu tutup shift — admin akan mereview.');

                return;
            case 'NEED_NOTES':
                $this->closingMismatch = $result['result'];
                $this->shiftNotice('warning', 'Penjelasan Wajib Diisi', 'Ada selisih antara POS dan struk / aplikasi. Wajib isi penjelasan sebelum menutup shift.');

                return;
        }

        $closed = $result['shift'];
        $this->reportShiftData = [
            'shift_number' => $closed->shift_number,
            'counter' => $closed->counter,
            'opened_by' => $closed->openedBy?->name ?? 'Kasir',
            'closed_by' => $user->name,
            'opened_at' => $closed->opened_at->setTimezone('Asia/Jakarta')->format('d/m/Y H:i'),
            'closed_at' => Carbon::now('Asia/Jakarta')->format('d/m/Y H:i'),
            'total_sales' => $result['summary']['total_sales'],
            'total_transactions' => $result['summary']['total_transactions'],
            'reconciliation' => $result['result']['rows'],
            'total_difference' => $result['result']['difference'],
            'review_status' => $result['review_status'],
            'closing_notes' => $closed->closing_notes,
            'sales_recap_views' => \App\Services\Pos\DailySalesRecapService::viewsDuringShift($closed),
            'transaction_types' => \App\Services\Pos\DailySalesRecapService::shiftTransactionTypes($closed),
        ];

        $this->closingRows = [];
        $this->settlementInputs = [];
        $this->edcModes = [];
        $this->closingSignature = null;
        $this->closingMismatch = null;
        $this->closingNotes = '';
        $this->showCloseShiftModal = false;
        $this->showShiftReportModal = true;

        $this->shiftNotice(
            $result['review_status'] === PosCashierShift::REVIEW_MATCHED ? 'success' : 'warning',
            'Shift Kasir Ditutup',
            $result['review_status'] === PosCashierShift::REVIEW_MATCHED
                ? "Sesi {$closed->shift_number} ditutup — semua cocok."
                : "Sesi {$closed->shift_number} ditutup dengan selisih — menunggu review admin.",
        );
    }

    public function closeShiftReportModal(): void
    {
        $this->showShiftReportModal = false;
        $this->reportShiftData = null;
    }

    /** @return array<int, string> peringatan shift lupa ditutup / EDC belum di-settle */
    public function getShiftWarningsProperty(): array
    {
        return app(ShiftClosingService::class)->warnings($this->activeShift);
    }

    /** @return array<string, string> mesin EDC loket ini yang belum di-settle sejak kemarin (ditampilkan saat buka shift) */
    public function getUnsettledEdcProperty(): array
    {
        return app(ShiftClosingService::class)->unsettledFromPreviousDays($this->shiftCounter());
    }

    /** Dipanggil setelah shift baru dibuat: mesin yang dicentang "sudah di-settle" dicatat. */
    protected function recordOpeningSettlements(PosCashierShift $shift): void
    {
        $unsettled = $this->unsettledEdc;
        foreach (array_keys(array_filter($this->settledAtOpening)) as $terminal) {
            if (array_key_exists($terminal, $unsettled)) {
                app(ShiftClosingService::class)->recordOpeningSettlement($shift, $terminal, auth()->user());
            }
        }
        $this->settledAtOpening = [];
    }
}
