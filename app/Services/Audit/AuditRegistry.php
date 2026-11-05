<?php

namespace App\Services\Audit;

use App\Models\Fnb\FnbCategory;
use App\Models\Fnb\FnbMenu;
use App\Models\Fnb\FnbModifierGroup;
use App\Models\Fnb\FnbModifierOption;
use App\Models\Membership\MembershipFacility;
use App\Models\Membership\MembershipPlan;
use App\Models\Membership\MembershipPlanBenefit;
use App\Models\Padel\BookingTimeSetting;
use App\Models\Padel\CourtEquipment;
use App\Models\Padel\PadelCourt;
use App\Models\Pos\ClubFinanceSetting;
use App\Models\Pos\OnlinePaymentMethod;
use App\Models\Pos\PaymentGatewaySetting;
use App\Models\Pos\PosCashierShift;
use App\Models\Pos\Voucher;
use App\Models\Role;
use App\Models\Setting\CompanyProfileFacility;
use App\Models\Setting\CompanyProfileSetting;
use App\Models\Setting\CompanyProfileValueProp;
use App\Models\Sponsor\SponsorAccessSchedule;
use App\Models\Sponsor\SponsorOrganization;
use App\Models\Sponsor\SponsorOrganizationMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Daftar model yang perubahan datanya (tambah / ubah / hapus / pulihkan) dicatat OTOMATIS lewat
 * model event — tanpa perlu menyentuh tiap tombol di tiap halaman. Model transaksi (Order, Payment,
 * PadelBooking) sengaja TIDAK di sini: dicatat sebagai event bisnis eksplisit yang lebih bermakna
 * ("Transaksi lunas Rp…", "Refund Rp…") di service-nya masing-masing.
 *
 * Catatan: mass update (Model::where()->update()) tidak memicu model event — titik seperti itu
 * wajib dicatat eksplisit.
 */
class AuditRegistry
{
    /** Kolom yang tidak pernah bermakna untuk audit. */
    private const ALWAYS_IGNORED = ['created_at', 'updated_at', 'deleted_at', 'remember_token'];

    private const COLUMN_LABELS = [
        'name' => 'nama',
        'title' => 'judul',
        'description' => 'deskripsi',
        'base_price' => 'harga',
        'price' => 'harga',
        'extra_price' => 'harga tambahan',
        'rental_price' => 'tarif sewa',
        'hourly_rate_regular' => 'tarif reguler',
        'hourly_rate_prime' => 'tarif prime time',
        'stock_quantity' => 'stok',
        'stock_qty' => 'stok',
        'hpp' => 'HPP',
        'track_stock' => 'lacak stok',
        'min_stock' => 'stok minimum',
        'is_stock_purchase' => 'pembelian stok / bahan',
        'is_active' => 'status aktif',
        'is_available' => 'status tersedia',
        'status' => 'status',
        'password' => 'kata sandi',
        'email' => 'email',
        'phone' => 'nomor HP',
        'tax_rate' => 'tarif pajak',
        'is_tax_enabled' => 'pajak aktif',
        'admin_fee_amount' => 'biaya layanan',
        'is_admin_fee_enabled' => 'biaya layanan aktif',
        'module_overrides' => 'pengaturan per modul',
        'discount_value' => 'nilai diskon',
        'discount_percent' => 'persen diskon',
        'quota' => 'kuota',
        'quota_value' => 'kuota',
        'valid_until' => 'berlaku sampai',
        'duration_days' => 'durasi (hari)',
        'home_route' => 'halaman awal',
        'open_time' => 'jam buka',
        'close_time' => 'jam tutup',
        'image_url' => 'foto',
        'photo_path' => 'foto',
        'sort_order' => 'urutan',
        'price_per_hour' => 'tarif / jam',
        'extra_coach_fee_per_hour' => 'biaya coach tambahan / jam',
        'admin_whatsapp' => 'WhatsApp admin coaching',
        'priority' => 'prioritas',
        'show_on_profile' => 'tampil di profil customer',
        'highlights' => 'poin keunggulan',
        'sport' => 'olahraga',
        'motto' => 'motto',
        'image_path' => 'gambar',
    ];

    /**
     * module      : modul di panel log
     * label       : nama data dalam kalimat log
     * title       : kolom (atau closure) untuk label manusiawi datanya
     * admin_only  : kolom yang hanya dicatat kalau diubah dari panel admin (mis. stok yang juga
     *               bergerak otomatis tiap checkout — tanpa ini log banjir "stok 10 → 9")
     * severity    : override severity per aksi
     *
     * @return array<class-string<Model>, array<string, mixed>>
     */
    public static function models(): array
    {
        return [
            PadelCourt::class => ['module' => 'MASTER_DATA', 'label' => 'Lapangan', 'severity' => ['deleted' => ActivityLogger::CRITICAL]],
            CourtEquipment::class => ['module' => 'MASTER_DATA', 'label' => 'Alat Sewa', 'admin_only' => ['stock_quantity']],
            FnbCategory::class => ['module' => 'FNB', 'label' => 'Kategori Menu F&B'],
            FnbMenu::class => ['module' => 'FNB', 'label' => 'Menu F&B', 'admin_only' => ['stock_qty'], 'severity_on_columns' => ['hpp' => ActivityLogger::WARNING]],
            \App\Models\Fnb\FnbCashCategory::class => ['module' => 'FNB', 'label' => 'Kategori Pengeluaran F&B', 'title' => ['name']],
            FnbModifierGroup::class => ['module' => 'FNB', 'label' => 'Grup Modifier F&B'],
            FnbModifierOption::class => ['module' => 'FNB', 'label' => 'Opsi Modifier F&B'],
            ClubFinanceSetting::class => [
                'module' => 'FINANCE',
                'label' => 'Pengaturan Biaya & Pajak',
                'title' => fn () => 'Pajak & Biaya Layanan',
                'severity' => ['created' => ActivityLogger::CRITICAL, 'updated' => ActivityLogger::CRITICAL, 'deleted' => ActivityLogger::CRITICAL],
            ],
            OnlinePaymentMethod::class => [
                'module' => 'FINANCE',
                'label' => 'Metode Pembayaran Online',
                'title' => ['label'],
                'severity' => ['created' => ActivityLogger::WARNING, 'updated' => ActivityLogger::WARNING],
            ],
            // Modul 24 Opsi B — tarif & pengaturan coaching = harga (KRITIS), data academy / coach = perubahan biasa.
            \App\Models\Coaching\CoachingAcademy::class => ['module' => 'COACHING', 'label' => 'Academy Coaching', 'title' => ['name']],
            \App\Models\Coaching\Coach::class => ['module' => 'COACHING', 'label' => 'Coach', 'title' => ['name']],
            \App\Models\Coaching\CoachingRate::class => [
                'module' => 'COACHING',
                'label' => 'Tarif Setoran Coaching',
                'title' => ['name'],
                'severity' => ['created' => ActivityLogger::CRITICAL, 'updated' => ActivityLogger::CRITICAL, 'deleted' => ActivityLogger::CRITICAL],
            ],
            \App\Models\Coaching\CoachingSetting::class => [
                'module' => 'COACHING',
                'label' => 'Pengaturan Coaching',
                'title' => fn () => 'Biaya Coach Tambahan & WhatsApp Admin',
                'severity' => ['created' => ActivityLogger::CRITICAL, 'updated' => ActivityLogger::CRITICAL],
            ],
            PaymentGatewaySetting::class => [
                'module' => 'FINANCE',
                'label' => 'Gateway Pembayaran',
                'title' => fn () => 'Gateway Pembayaran (Online & QR Kasir)',
                'severity' => ['created' => ActivityLogger::CRITICAL, 'updated' => ActivityLogger::CRITICAL, 'deleted' => ActivityLogger::CRITICAL],
            ],
            BookingTimeSetting::class => [
                'module' => 'FINANCE',
                'label' => 'Waktu Tahan Slot & Batas Bayar',
                'title' => fn () => 'Waktu Tahan Slot & Batas Bayar',
                'severity' => ['updated' => ActivityLogger::WARNING],
            ],
            Voucher::class => ['module' => 'FINANCE', 'label' => 'Voucher', 'title' => ['code'], 'admin_only' => ['used_count', 'quota'], 'severity' => ['created' => ActivityLogger::WARNING]],
            MembershipFacility::class => ['module' => 'MEMBERSHIP', 'label' => 'Fasilitas Membership', 'title' => ['name'], 'severity' => ['deleted' => ActivityLogger::WARNING]],
            MembershipPlan::class => ['module' => 'MEMBERSHIP', 'label' => 'Paket Membership', 'severity' => ['updated' => ActivityLogger::WARNING]],
            MembershipPlanBenefit::class => [
                'module' => 'MEMBERSHIP',
                'label' => 'Benefit Paket Membership',
                'title' => fn (MembershipPlanBenefit $b) => trim(($b->plan?->name ?? 'Paket').' - '.$b->facility),
                'severity' => ['updated' => ActivityLogger::WARNING],
            ],
            SponsorOrganization::class => ['module' => 'SPONSOR', 'label' => 'Sponsor Corporate', 'severity_on_columns' => ['pic_restricted_actions' => ActivityLogger::WARNING]],
            SponsorOrganizationMember::class => [
                'module' => 'SPONSOR',
                'label' => 'Anggota Sponsor',
                'title' => fn (SponsorOrganizationMember $m) => ($m->user?->name ?? 'Anggota').' @ '.($m->organization?->name ?? '-'),
            ],
            SponsorAccessSchedule::class => [
                'module' => 'SPONSOR',
                'label' => 'Jadwal Akses Sponsor',
                'title' => fn (SponsorAccessSchedule $s) => ($s->organization?->name ?? 'Sponsor').' '.$s->time_start.'-'.$s->time_end,
            ],
            User::class => [
                'module' => 'USER_ROLE',
                'label' => 'Akun Pengguna',
                'severity' => ['deleted' => ActivityLogger::CRITICAL],
                'severity_on_columns' => ['password' => ActivityLogger::WARNING, 'is_active' => ActivityLogger::WARNING, 'email' => ActivityLogger::WARNING],
            ],
            Role::class => ['module' => 'USER_ROLE', 'label' => 'Peran (Role)', 'severity' => ['created' => ActivityLogger::WARNING, 'updated' => ActivityLogger::WARNING, 'deleted' => ActivityLogger::CRITICAL]],
            // Role::findOrCreate() di beberapa tempat memanggil class Spatie langsung — event-nya
            // bernama class induk, jadi didaftarkan juga supaya tidak ada role baru yang lolos.
            \Spatie\Permission\Models\Role::class => ['module' => 'USER_ROLE', 'label' => 'Peran (Role)', 'severity' => ['created' => ActivityLogger::WARNING, 'updated' => ActivityLogger::WARNING, 'deleted' => ActivityLogger::CRITICAL]],
            CompanyProfileSetting::class => ['module' => 'CONTENT', 'label' => 'Konten Website', 'title' => fn () => 'Landing Page'],
            \App\Models\Setting\InvoiceTemplateSetting::class => ['module' => 'FINANCE', 'label' => 'Template Invoice', 'title' => fn () => 'PDF Invoice Email'],
            CompanyProfileFacility::class => ['module' => 'CONTENT', 'label' => 'Fasilitas Website'],
            CompanyProfileValueProp::class => ['module' => 'CONTENT', 'label' => 'Keunggulan Website'],
            \App\Models\Marketing\LinkBioLink::class => ['module' => 'CONTENT', 'label' => 'Tombol Link Bio', 'title' => ['label']],
            \App\Models\Padel\PadelRecurringSchedule::class => ['module' => 'PADEL', 'label' => 'Jadwal Tetap Mingguan', 'title' => ['name'], 'severity' => ['deleted' => ActivityLogger::WARNING]],
            \App\Models\Staff\Guide::class => ['module' => 'STAFF', 'label' => 'Panduan Karyawan', 'title' => ['title'], 'severity' => ['deleted' => ActivityLogger::WARNING]],
        ];
    }

    public static function register(): void
    {
        foreach (array_keys(self::models()) as $class) {
            foreach (['created', 'updated', 'deleted', 'restored'] as $action) {
                if (method_exists($class, $action)) {
                    $class::$action(fn (Model $model) => self::handle($action, $model));
                }
            }
        }

        PosCashierShift::created(fn (PosCashierShift $shift) => self::shiftOpened($shift));
        PosCashierShift::updated(fn (PosCashierShift $shift) => self::shiftClosed($shift));
    }

    public static function handle(string $action, Model $model): void
    {
        if (ActivityLogger::suppressed()) {
            return;
        }

        $config = self::models()[$model::class] ?? null;
        if (! $config) {
            return;
        }

        $changes = match ($action) {
            'created' => self::snapshot($model, 'new', $config),
            'deleted' => self::snapshot($model, 'old', $config),
            'updated' => self::diff($model, $config),
            default => null,
        };

        if ($action === 'updated' && empty($changes)) {
            return;
        }

        $forceDeleting = $action === 'deleted' && method_exists($model, 'isForceDeleting') && $model->isForceDeleting();
        $verb = match ($action) {
            'created' => 'Menambah',
            'updated' => 'Mengubah',
            'deleted' => $forceDeleting ? 'Menghapus permanen' : 'Menghapus',
            'restored' => 'Memulihkan',
        };

        $description = "{$verb} {$config['label']} \"".self::labelFor($model).'"';
        if ($action === 'updated') {
            $description .= ': '.collect(array_keys($changes))->map(fn ($c) => self::columnLabel($c))->implode(', ');
        }

        $severity = $config['severity'][$action] ?? ($action === 'deleted' ? ActivityLogger::WARNING : ActivityLogger::INFO);
        if ($action === 'updated') {
            foreach ($config['severity_on_columns'] ?? [] as $column => $columnSeverity) {
                if (array_key_exists($column, $changes) && self::rank($columnSeverity) > self::rank($severity)) {
                    $severity = $columnSeverity;
                }
            }
        }

        ActivityLogger::record(
            module: $config['module'],
            event: Str::snake(class_basename($model)).'.'.($forceDeleting ? 'force_deleted' : $action),
            description: $description,
            subject: $model,
            severity: $severity,
            changes: $changes ?: null,
        );
    }

    public static function labelFor(Model $model): string
    {
        $title = self::models()[$model::class]['title'] ?? null;

        try {
            if ($title instanceof \Closure) {
                return (string) $title($model);
            }

            foreach ((array) ($title ?? ['name', 'title', 'booking_code', 'order_number', 'shift_number', 'code', 'email']) as $attribute) {
                $value = $model->getAttribute($attribute);
                if (is_scalar($value) && (string) $value !== '') {
                    return (string) $value;
                }
            }
        } catch (\Throwable) {
            // relasi hilang dsb. — jatuh ke id pendek
        }

        return '#'.Str::upper(Str::substr((string) $model->getKey(), -8));
    }

    public static function columnLabel(string $column): string
    {
        return self::COLUMN_LABELS[$column] ?? str_replace('_', ' ', $column);
    }

    private static function snapshot(Model $model, string $side, array $config): array
    {
        $changes = [];
        foreach ($model->attributesToArray() as $column => $value) {
            if (in_array($column, self::ALWAYS_IGNORED, true) || $column === $model->getKeyName()) {
                continue;
            }
            $changes[$column] = [$side => $value];
        }

        return $changes;
    }

    private static function diff(Model $model, array $config): array
    {
        // Kolom admin_only (mis. stok) hanya dicatat kalau diubah MANUAL di halaman kelola datanya —
        // bukan efek samping otomatis dari check-in / checkout / retur alat (itu sudah punya log sendiri).
        $manualPages = $config['manual_pages'] ?? ['admin/master-data'];
        $isManualEdit = collect($manualPages)->contains(fn ($page) => str_starts_with(ActivityLogger::currentPath(), $page));
        $adminOnly = $isManualEdit ? [] : ($config['admin_only'] ?? []);
        $changes = [];

        foreach (array_keys($model->getChanges()) as $column) {
            if (in_array($column, self::ALWAYS_IGNORED, true) || in_array($column, $adminOnly, true)) {
                continue;
            }

            $old = $model->getOriginal($column);
            $new = $model->getAttributeValue($column);

            // Cast decimal "38000.00" vs "38000" dsb. — jangan catat perubahan yang sebenarnya sama.
            if (is_numeric($old) && is_numeric($new) && (float) $old === (float) $new) {
                continue;
            }
            if ($old == $new && gettype($old) === gettype($new)) {
                continue;
            }

            $changes[$column] = ['old' => $old, 'new' => $new];
        }

        return $changes;
    }

    private static function shiftOpened(PosCashierShift $shift): void
    {
        if (ActivityLogger::suppressed()) {
            return;
        }

        ActivityLogger::record(
            module: 'POS',
            event: 'shift.opened',
            description: "Membuka shift {$shift->shift_number} di loket ".self::counterLabel($shift->counter),
            subject: $shift,
            meta: array_filter([
                'loket' => $shift->counter,
                'modal_awal' => (float) $shift->starting_cash,
                'catatan' => $shift->opening_notes,
            ], fn ($v) => $v !== null && $v !== ''),
        );
    }

    private static function shiftClosed(PosCashierShift $shift): void
    {
        if (ActivityLogger::suppressed() || ! $shift->wasChanged('status') || $shift->status !== 'CLOSED') {
            return;
        }

        $difference = (float) ($shift->settlement_difference ?? 0) + (float) ($shift->cash_difference ?? 0);
        $hasDifference = abs($difference) > 0.009;

        $description = "Menutup shift {$shift->shift_number} di loket ".self::counterLabel($shift->counter)
            .': '.(int) $shift->total_transactions.' transaksi, total '.ActivityLogger::rupiah((float) $shift->total_sales);
        if ($hasDifference) {
            $description .= ' | ADA SELISIH SETORAN '.ActivityLogger::rupiah($difference);
        }

        ActivityLogger::record(
            module: 'POS',
            event: 'shift.closed',
            description: $description,
            subject: $shift,
            meta: array_filter([
                'loket' => $shift->counter,
                'jumlah_transaksi' => (int) $shift->total_transactions,
                'total_penjualan' => (float) $shift->total_sales,
                'total_qris' => (float) $shift->total_qris_sales,
                'total_edc_bca' => (float) $shift->total_edc_bca_sales,
                'total_edc_mandiri' => (float) $shift->total_edc_mandiri_sales,
                'total_lainnya' => (float) $shift->total_other_sales,
                'selisih_setoran' => $difference,
                'rekonsiliasi' => $shift->settlement_reconciliation,
                'catatan_tutup' => $shift->closing_notes,
            ], fn ($v) => $v !== null && $v !== ''),
            severity: $hasDifference ? ActivityLogger::WARNING : ActivityLogger::INFO,
        );
    }

    private static function counterLabel(?string $counter): string
    {
        return match ($counter) {
            'PADEL_FRONTDESK' => 'Frontdesk Padel',
            'FNB_COUNTER' => 'Kasir F&B',
            'MEMBERSHIP_DESK' => 'Meja Membership',
            default => (string) $counter,
        };
    }

    private static function rank(string $severity): int
    {
        return match ($severity) {
            ActivityLogger::CRITICAL => 3,
            ActivityLogger::WARNING => 2,
            default => 1,
        };
    }
}
