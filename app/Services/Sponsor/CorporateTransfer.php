<?php

namespace App\Services\Sponsor;

use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Models\User;
use App\Services\Media\SecureImageUploader;
use App\Services\Payment\PaymentOrchestratorService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Pembayaran perusahaan yang ditransfer LANGSUNG ke rekening Club 61 (bukan uang kasir), dicatat dari Kelola Sponsor:
 * pemberian membership corporate & pembayaran Tagihan Bulanan (Modul 34). Tanpa shift kasir, tetapi tetap tercatat di
 * pembayaran, Buku Transaksi & Analitik (sumber "Transfer Corporate").
 *
 * Rincian transfer & foto bukti (disk privat) disimpan di payments.payload_log.corporate_transfer.
 */
class CorporateTransfer
{
    public const GATEWAY = 'CORPORATE_TRANSFER';

    public const METHOD = 'TRANSFER_BANK';

    public const PERMISSION = 'record_corporate_transfer';

    public const PROOF_DISK = 'local';

    public const PROOF_DIR = 'corporate-transfer';

    public const LABEL = 'Transfer Bank (Rekening Perusahaan)';

    /** Jenis pencatatan di payload_log.corporate_kind (Tagihan Bulanan memakai monthly_period). */
    public const KIND_GRANT = 'GRANT';

    public const KIND_PACKAGE = 'PACKAGE';

    /**
     * Isian rincian transfer untuk form aksi Filament.
     *
     * @return list<\Filament\Schemas\Components\Component|\Filament\Forms\Components\Field>
     */
    public static function formFields(): array
    {
        return [
            DatePicker::make('transfer_date')
                ->label('Tanggal Transfer')
                ->native(false)
                ->displayFormat('d M Y')
                ->maxDate(now())
                ->default(now())
                ->required(),
            TextInput::make('sender_bank')
                ->label('Bank Pengirim')
                ->placeholder('Contoh: BCA, Mandiri')
                ->required()
                ->maxLength(60),
            TextInput::make('reference')
                ->label('Nomor Referensi Transfer')
                ->helperText('Nomor referensi / berita transfer yang tertera pada bukti transfer.')
                ->required()
                ->maxLength(60),
            FileUpload::make('proof_path')
                ->label('Bukti Transfer')
                ->image()
                ->disk(self::PROOF_DISK)
                ->directory(self::PROOF_DIR)
                ->visibility('private')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(6144)
                ->helperText('Foto / tangkapan layar bukti transfer. JPG / PNG / WEBP, maks. 6 MB.')
                ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file) => SecureImageUploader::store($file, self::PROOF_DIR, self::PROOF_DISK))
                ->required(),
        ];
    }

    /**
     * Rincian transfer yang sudah divalidasi dari isian form.
     *
     * @return array{transfer_date: string, sender_bank: string, reference: string, proof_path: string, recorded_by: string, recorded_by_name: string, recorded_at: string}
     */
    public static function details(array $data, User $by): array
    {
        $proof = self::validProofPath($data['proof_path'] ?? null);
        $bank = trim((string) ($data['sender_bank'] ?? ''));
        $reference = strtoupper(trim((string) ($data['reference'] ?? '')));
        $date = rescue(fn () => \Carbon\Carbon::parse((string) ($data['transfer_date'] ?? '')), null, false);

        // Bukti harus berkas baru hasil unggahan form ini: isian path yang direkayasa ke bukti milik pencatatan lain ditolak.
        if (! $proof || ! Storage::disk(self::PROOF_DISK)->exists($proof)) {
            throw new HttpException(422, 'Bukti transfer wajib diunggah.');
        }
        if (self::proofInUse($proof)) {
            throw new HttpException(422, 'Bukti transfer ini sudah dipakai pencatatan lain. Unggah ulang bukti transfer.');
        }
        if ($bank === '' || $reference === '' || ! $date || $date->isAfter(now()->endOfDay())) {
            throw new HttpException(422, 'Lengkapi tanggal transfer, bank pengirim, dan nomor referensi transfer.');
        }

        return [
            'transfer_date' => $date->format('Y-m-d'),
            'sender_bank' => mb_substr($bank, 0, 60),
            'reference' => mb_substr($reference, 0, 60),
            'proof_path' => $proof,
            'recorded_by' => (string) $by->id,
            'recorded_by_name' => $by->name,
            'recorded_at' => now()->toIso8601String(),
        ];
    }

    /** Satu nomor referensi dari bank yang sama hanya boleh dicatat sekali (cegah pencatatan ganda). */
    public static function assertReferenceUnused(array $details): void
    {
        // Nomor referensi disimpan huruf besar → dicari langsung di database, bank dicocokkan tanpa beda huruf.
        $used = self::payments()->where('payload_log->corporate_transfer->reference', $details['reference'])
            ->get(['id', 'payload_log'])
            ->contains(fn (Payment $p) => empty($p->payload_log['voided'])
                && strcasecmp(self::of($p)['sender_bank'] ?? '', $details['sender_bank']) === 0);

        if ($used) {
            throw new HttpException(422, 'Nomor referensi transfer '.$details['reference'].' dari '.$details['sender_bank'].' sudah pernah dicatat.');
        }
    }

    /** Lunasi order dengan transfer perusahaan (tanpa shift kasir). */
    public static function markPaid(Order $order, float $amount, array $details, array $extraPayload = []): Payment
    {
        $transactionId = 'CT-'.now()->format('ymd').'-'.strtoupper(Str::random(6));

        app(PaymentOrchestratorService::class)->markOrderAsPaid($order, [
            'payment_gateway' => self::GATEWAY,
            'payment_method' => self::METHOD,
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'payload_log' => ['corporate_transfer' => $details] + $extraPayload,
        ]);

        return Payment::where('transaction_id', $transactionId)->firstOrFail();
    }

    /**
     * Transfer pembayaran paket membership yang SUDAH aktif (mis. paket corporate yang diberikan / dijual sebelumnya):
     * hanya mencatat uang masuk (kategori Membership) — kartu, kuota, karyawan & jadwal tidak diubah. Nominal diketik
     * sesuai kontrak / invoice; boleh lebih dari sekali (mis. cicilan).
     */
    public static function recordPackagePayment(\App\Models\Sponsor\SponsorOrganization $organization, float $amount, array $data, User $by): Payment
    {
        try {
            if (! $by->can(self::PERMISSION)) {
                throw new HttpException(403, 'Akses ditolak: Anda tidak memiliki izin untuk mencatat pembayaran transfer perusahaan.');
            }
            $membership = $organization->userMembership()->with('plan')->first();
            if (! $membership) {
                throw new HttpException(422, 'Perusahaan ini belum memiliki kartu membership.');
            }
            $amount = round($amount, 2);
            if ($amount < 1 || $amount > 10_000_000_000) {
                throw new HttpException(422, 'Nominal pembayaran harus lebih dari Rp 0.');
            }
            $details = self::details($data, $by);
            self::assertReferenceUnused($details);

            return \Illuminate\Support\Facades\DB::transaction(function () use ($organization, $membership, $amount, $details, $by) {
                $planName = $membership->plan?->name ?? 'Membership';
                $order = Order::create([
                    'order_number' => 'ORD-CT-PKG-'.strtoupper(Str::random(8)),
                    'user_id' => $organization->sponsor_admin_user_id ?? $membership->user_id,
                    'cashier_id' => null,
                    'order_type' => \App\Services\Membership\MonthlyBillingPayment::ORDER_TYPE,
                    'subtotal' => $amount,
                    'discount_amount' => 0,
                    'tax_amount' => 0,
                    'service_charge' => 0,
                    'grand_total' => $amount,
                    'payment_status' => 'UNPAID',
                ]);
                $order->items()->create([
                    'item_type' => 'MEMBERSHIP',
                    'reference_id' => $membership->plan_id,
                    'item_name' => "Pembayaran Paket {$planName} — {$organization->name} ({$membership->membership_code})",
                    'quantity' => 1,
                    'unit_price' => $amount,
                    'subtotal' => $amount,
                ]);

                $payment = self::markPaid($order, $amount, $details, [
                    'corporate_kind' => self::KIND_PACKAGE,
                    'corporate_company' => $organization->name,
                    'corporate_plan' => $planName,
                    'corporate_membership_id' => $membership->id,
                    'corporate_membership_code' => $membership->membership_code,
                ]);

                \App\Services\Audit\ActivityLogger::record('MEMBERSHIP', 'corporate_transfer.package_recorded',
                    "Mencatat transfer pembayaran paket {$planName} {$organization->name}: ".\App\Services\Audit\ActivityLogger::rupiah($amount)
                        .' (transfer '.$details['sender_bank'].' '.$details['reference'].')',
                    subject: $organization,
                    meta: ['kartu' => $membership->membership_code, 'tanggal_transfer' => $details['transfer_date']],
                    causer: $by,
                    subjectLabel: $organization->name,
                );

                return $payment;
            });
        } catch (\Throwable $e) {
            self::discardProof($data['proof_path'] ?? null);

            throw $e;
        }
    }

    /**
     * Batalkan pencatatan transfer yang salah input (Tagihan Bulanan / pembayaran paket): uangnya dikeluarkan dari Buku
     * Transaksi lewat baris pembalik (refund PROCESSED bermetode "Pembatalan Pencatatan"), order REFUNDED, pembayaran
     * ditandai voided. Transfer pemberian membership tidak dapat dibatalkan di sini (kartunya sudah aktif karenanya).
     */
    public static function void(Payment $payment, string $reason, User $by): void
    {
        if (! $by->can(self::PERMISSION)) {
            throw new HttpException(403, 'Akses ditolak: Anda tidak memiliki izin untuk membatalkan pencatatan transfer perusahaan.');
        }
        $reason = trim(mb_substr($reason, 0, 255));
        if (mb_strlen($reason) < 5) {
            throw new HttpException(422, 'Alasan pembatalan wajib diisi (minimal 5 karakter).');
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($payment, $reason, $by) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $log = is_array($payment->payload_log) ? $payment->payload_log : [];
            if ($payment->payment_gateway !== self::GATEWAY || $payment->status !== 'SUCCESS' || ! self::isVoidable($log)) {
                throw new HttpException(422, 'Pencatatan ini tidak dapat dibatalkan.');
            }
            if (! empty($log['voided'])) {
                throw new HttpException(422, 'Pencatatan ini sudah dibatalkan.');
            }

            $refund = \App\Models\Pos\Refund::create([
                'order_id' => $payment->order_id,
                'payment_id' => $payment->id,
                'requested_by_id' => $by->id,
                'refund_amount' => (float) $payment->amount,
                'reason' => 'Pembatalan pencatatan transfer perusahaan (salah input): '.$reason,
                'status' => 'PROCESSED',
                'refund_method' => 'PEMBATALAN_PENCATATAN',
                'refund_reference' => $payment->transaction_id,
                'processed_at' => now(),
                'processed_by_id' => $by->id,
                'admin_notes' => $reason,
            ]);
            app(\App\Services\Finance\LedgerWriter::class)->recordRefund($refund);

            Order::whereKey($payment->order_id)->update(['payment_status' => 'REFUNDED']);
            $payment->update(['payload_log' => $log + ['voided' => [
                'by' => (string) $by->id,
                'by_name' => $by->name,
                'at' => now()->toIso8601String(),
                'reason' => $reason,
            ]]]);

            \App\Services\Audit\ActivityLogger::record('MEMBERSHIP', 'corporate_transfer.voided',
                'Membatalkan pencatatan transfer '.($log['monthly_billed_name'] ?? $log['corporate_company'] ?? '-').': '.\App\Services\Audit\ActivityLogger::rupiah((float) $payment->amount),
                subject: $payment,
                meta: ['alasan' => $reason, 'transaksi' => $payment->transaction_id],
                severity: \App\Services\Audit\ActivityLogger::CRITICAL,
                causer: $by,
                subjectLabel: $payment->transaction_id,
            );
        });
    }

    /** Pencatatan yang boleh dibatalkan: Tagihan Bulanan & pembayaran paket yang sudah aktif. */
    public static function isVoidable(array $log): bool
    {
        return ! empty($log['monthly_period']) || ($log['corporate_kind'] ?? null) === self::KIND_PACKAGE;
    }

    /** Pembayaran transfer perusahaan (semua status). */
    public static function payments(): Builder
    {
        return Payment::query()->where('payment_gateway', self::GATEWAY);
    }

    /**
     * Riwayat transfer perusahaan (membership corporate & Tagihan Bulanan), terbaru dulu.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public static function history(int $limit = 50): \Illuminate\Support\Collection
    {
        return self::payments()->where('status', 'SUCCESS')->latest()->limit($limit)->get()
            ->map(function (Payment $p) {
                $t = self::of($p) ?? [];
                $log = is_array($p->payload_log) ? $p->payload_log : [];
                $period = $log['monthly_period'] ?? null;

                return [
                    'id' => (string) $p->id,
                    'transaction_id' => $p->transaction_id,
                    'transfer_date' => $t['transfer_date'] ?? null,
                    'kind' => $period
                        ? 'Tagihan Bulanan '.\App\Services\Membership\MonthlyBilling::monthLabel(\Carbon\Carbon::parse($period.'-01'))
                        : (($log['corporate_kind'] ?? null) === self::KIND_PACKAGE ? 'Pembayaran Paket' : 'Membership Corporate')
                            .(! empty($log['corporate_plan']) ? ' · '.$log['corporate_plan'] : ''),
                    'company' => $log['monthly_billed_name'] ?? $log['corporate_company'] ?? '-',
                    'amount' => (float) $p->amount,
                    'bank' => $t['sender_bank'] ?? '-',
                    'reference' => $t['reference'] ?? '-',
                    'recorded_by' => $t['recorded_by_name'] ?? '-',
                    'voided' => is_array($log['voided'] ?? null) ? $log['voided'] : null,
                    'monthly' => $period !== null,
                    'voidable' => self::isVoidable($log),
                    'has_proof' => self::validProofPath($t['proof_path'] ?? null) !== null,
                ];
            });
    }

    /** Rincian transfer satu pembayaran (null = bukan transfer perusahaan). */
    public static function of(Payment $payment): ?array
    {
        $details = is_array($payment->payload_log) ? ($payment->payload_log['corporate_transfer'] ?? null) : null;

        return is_array($details) ? $details : null;
    }

    /** Hanya berkas di folder bukti transfer — isian lain (path rekayasa) ditolak. */
    public static function validProofPath(mixed $path): ?string
    {
        $path = is_array($path) ? (array_values($path)[0] ?? null) : $path;

        return is_string($path) && str_starts_with($path, self::PROOF_DIR.'/') && ! str_contains($path, '..') ? $path : null;
    }

    /** Hapus bukti dari pencatatan yang gagal — tidak pernah menghapus bukti yang sudah dipakai pembayaran lain. */
    public static function discardProof(mixed $path): void
    {
        if (($path = self::validProofPath($path)) && ! self::proofInUse($path)) {
            Storage::disk(self::PROOF_DISK)->delete($path);
        }
    }

    private static function proofInUse(string $path): bool
    {
        return self::payments()->where('payload_log->corporate_transfer->proof_path', $path)->exists();
    }
}
