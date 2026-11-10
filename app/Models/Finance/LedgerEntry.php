<?php

namespace App\Models\Finance;

use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Models\Pos\Refund;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Satu baris Buku Transaksi (Modul 17): satu kategori pendapatan dari satu pembayaran atau refund.
 * IMMUTABLE — ditulis sekali oleh App\Services\Finance\LedgerWriter saat uang masuk / refund diproses, tidak bisa
 * diubah atau dihapus lewat Eloquent. Koreksi = baris baru (refund), bukan edit.
 *
 * Definisi angka (PRD §2): net_amount = penjualan bersih (pendapatan), service_amount = biaya layanan (terpisah),
 * tax_amount = pajak terkumpul (BUKAN pendapatan), total_amount = uang diterima (negatif untuk refund),
 * benefit_amount = nilai kuota/voucher yang dipakai (informasi, non-tunai).
 */
class LedgerEntry extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    public const TYPE_PAYMENT = 'PAYMENT';

    public const TYPE_OVERPAYMENT = 'OVERPAYMENT';

    public const TYPE_REFUND = 'REFUND';

    public const CATEGORIES = [
        'SEWA_LAPANGAN' => 'Sewa Lapangan Padel',
        'COACHING' => 'Sewa Lapangan Coaching',
        'ADDON_PADEL' => 'Add-on Padel',
        'DENDA_ALAT' => 'Denda Alat',
        'MEMBERSHIP' => 'Membership',
        'FNB' => 'F&B',
        'WELLNESS' => 'Wellness',
        'GYM' => 'Gym',
        'MERCH' => 'Merchandise',
        'SALON' => 'Salon',
        'LAINNYA' => 'Lainnya',
    ];

    public const SOURCES = [
        'POS_WALKIN_PADEL' => 'POS Walk-In Padel',
        'ONLINE_PADEL' => 'Booking Online Padel',
        'RESCHEDULE_DELTA_POS' => 'Pelunasan Selisih (Kasir)',
        'RESCHEDULE_DELTA_ONLINE' => 'Pelunasan Selisih (Online)',
        'POS_MEMBERSHIP' => 'POS Membership',
        'ONLINE_MEMBERSHIP' => 'Membership Online',
        'POS_FNB' => 'POS F&B',
        'CORPORATE_TRANSFER' => 'Transfer Corporate',
        'LAINNYA' => 'Lainnya',
    ];

    public const ENTRY_TYPES = [
        self::TYPE_PAYMENT => 'Pembayaran',
        self::TYPE_OVERPAYMENT => 'Kelebihan Bayar',
        self::TYPE_REFUND => 'Refund',
    ];

    protected $table = 'ledger_entries';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'gross_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'service_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'benefit_amount' => 'decimal:2',
            'meta' => 'array',
            'method_corrected_at' => 'datetime',
        ];
    }

    /**
     * Satu-satunya pengecualian immutable: koreksi METODE BAYAR oleh admin (salah klik kasir, mis. QRIS padahal debit).
     * Nominal, kategori, waktu & semua kolom lain tetap tidak bisa diubah; metode & bukti asli disimpan di kolom
     * original_* dan siapa / kapan di method_corrected_*. Hanya lewat correctPaymentMethod() (PaymentMethodCorrection).
     */
    public const METHOD_CORRECTION_COLUMNS = [
        'payment_method', 'payment_method_label', 'payment_reference',
        'method_corrected_at', 'method_corrected_by_id', 'method_corrected_by_name',
        'original_payment_method', 'original_payment_method_label', 'original_payment_reference',
    ];

    private static bool $correctingMethod = false;

    /**
     * @param  iterable<LedgerEntry>  $entries  baris buku milik pembayaran yang dikoreksi
     * @param  array{payment_method: ?string, payment_method_label: ?string, payment_reference: ?string}  $method
     */
    public static function correctPaymentMethod(iterable $entries, array $method, User $by): int
    {
        $count = 0;
        self::$correctingMethod = true;
        try {
            foreach ($entries as $entry) {
                $changes = [
                    'payment_method' => $method['payment_method'],
                    'payment_method_label' => $method['payment_method_label'],
                    'payment_reference' => $method['payment_reference'],
                    'method_corrected_at' => now(),
                    'method_corrected_by_id' => $by->getKey(),
                    'method_corrected_by_name' => mb_substr((string) $by->name, 0, 150),
                ];
                // Koreksi kedua dst. tidak menimpa metode asli kasir.
                if ($entry->method_corrected_at === null) {
                    $changes += [
                        'original_payment_method' => $entry->payment_method,
                        'original_payment_method_label' => $entry->payment_method_label,
                        'original_payment_reference' => $entry->payment_reference,
                    ];
                }
                $entry->forceFill($changes)->save();
                $count++;
            }
        } finally {
            self::$correctingMethod = false;
        }

        return $count;
    }

    protected static function booted(): void
    {
        static::updating(function (LedgerEntry $entry) {
            if (self::$correctingMethod && array_diff(array_keys($entry->getDirty()), self::METHOD_CORRECTION_COLUMNS) === []) {
                return;
            }

            throw new LogicException('Baris buku transaksi tidak boleh diubah. Koreksi dicatat sebagai baris baru (refund).');
        });

        static::deleting(function () {
            throw new LogicException('Baris buku transaksi tidak boleh dihapus.');
        });
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id')->withTrashed();
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function refund()
    {
        return $this->belongsTo(Refund::class, 'refund_id');
    }

    public static function categoryLabel(?string $code): string
    {
        return self::CATEGORIES[$code] ?? (string) $code;
    }

    public static function sourceLabel(?string $code): string
    {
        return self::SOURCES[$code] ?? (string) $code;
    }
}
