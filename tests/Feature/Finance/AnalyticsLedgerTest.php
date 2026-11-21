<?php

namespace Tests\Feature\Finance;

use App\Filament\Pages\Analytics;
use App\Filament\Pages\BukuTransaksi;
use App\Models\Finance\LedgerEntry;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Models\User;
use App\Services\Finance\LedgerAnalytics;
use App\Services\Finance\LedgerReport;
use App\Services\Permission\Club61PermissionMatrix;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Modul 17 Fase 3 — Analytics & Keuangan membaca dari Buku Transaksi: F&B ikut terhitung, rincian per
 * kategori / sumber / metode, tren harian per hari WIB, dan tautan ke Buku Transaksi yang tersaring.
 */
class AnalyticsLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Club61PermissionMatrix::syncAllPermissions('web');
        LedgerReport::flushMemo();
        $this->owner = User::factory()->superAdmin()->create();
        $this->travelTo(Carbon::parse('2026-10-20 10:00', LedgerReport::TIMEZONE));
    }

    /** Satu baris buku. $at dalam WIB. */
    private function row(string $at, string $category, string $source, float $net, array $extra = []): LedgerEntry
    {
        $type = $extra['entry_type'] ?? LedgerEntry::TYPE_PAYMENT;
        $tax = $extra['tax'] ?? 0;
        $paymentId = $extra['payment_id'] ?? (string) Str::ulid();

        return LedgerEntry::create([
            'occurred_at' => Carbon::parse($at, LedgerReport::TIMEZONE)->setTimezone(config('app.timezone')),
            'entry_type' => $type,
            'source' => $source,
            'category' => $category,
            'order_id' => (string) Str::ulid(),
            'payment_id' => $paymentId,
            'refund_id' => $extra['refund_id'] ?? null,
            'dedupe_key' => Str::random(20),
            'order_number' => $extra['order_number'] ?? 'ORD-'.Str::upper(Str::random(6)),
            'customer_name' => 'Budi',
            'payment_method' => $extra['method'] ?? 'QRIS',
            'payment_method_label' => $extra['label'] ?? 'QRIS BCA',
            'payment_gateway' => $extra['gateway'] ?? null,
            'pos_shift_id' => $extra['pos_shift_id'] ?? null,
            'gross_amount' => $net,
            'net_amount' => $net,
            'tax_amount' => $tax,
            'total_amount' => $net + $tax,
        ]);
    }

    private function seedMonth(): void
    {
        $this->row('2026-10-05 10:00', 'SEWA_LAPANGAN', 'POS_WALKIN_PADEL', 300000, ['tax' => 30000, 'method' => 'EDC', 'label' => 'EDC BCA Debit']);
        $this->row('2026-10-05 12:00', 'FNB', 'POS_FNB', 100000, ['tax' => 10000, 'order_number' => 'ORD-FNB-001']);
        $this->row('2026-10-06 09:00', 'MEMBERSHIP', 'ONLINE_MEMBERSHIP', 1500000, ['method' => 'BCA_VA', 'label' => 'BCA Virtual Account']);
        // Refund sebagian pembayaran lapangan.
        $this->row('2026-10-07 15:00', 'SEWA_LAPANGAN', 'POS_WALKIN_PADEL', -100000, ['entry_type' => LedgerEntry::TYPE_REFUND, 'refund_id' => (string) Str::ulid(), 'tax' => -10000, 'method' => 'EDC', 'label' => 'EDC BCA Debit']);
        // Bulan lalu — tidak ikut "bulan ini".
        $this->row('2026-09-30 20:00', 'FNB', 'POS_FNB', 999000);
    }

    public function test_breakdown_per_category_source_and_method_includes_fnb_and_refunds(): void
    {
        $this->seedMonth();
        $base = LedgerReport::applyDateRange(LedgerEntry::query(), '2026-10-01', '2026-10-20');

        $category = collect(LedgerAnalytics::breakdown($base, 'category'))->keyBy('key');
        $this->assertSame(110000.0, $category['FNB']['money_net']);
        $this->assertSame(330000.0, $category['SEWA_LAPANGAN']['money_in']);
        $this->assertSame(-110000.0, $category['SEWA_LAPANGAN']['refunds']);
        $this->assertSame(220000.0, $category['SEWA_LAPANGAN']['money_net']);
        $this->assertSame(1, $category['SEWA_LAPANGAN']['transactions']);
        $this->assertSame('F&B', $category['FNB']['label']);

        $source = collect(LedgerAnalytics::breakdown($base, 'source'))->keyBy('key');
        $this->assertSame('POS F&B', $source['POS_FNB']['label']);
        $this->assertSame(1500000.0, $source['ONLINE_MEMBERSHIP']['money_net']);

        $method = collect(LedgerAnalytics::breakdown($base, 'method'))->keyBy('key');
        $this->assertSame('EDC', $method['EDC BCA Debit']['method_code']);
        $this->assertSame(220000.0, $method['EDC BCA Debit']['money_net']);
        $this->assertSame(1500000.0, $method['BCA Virtual Account']['money_net']);

        // Jumlah semua rincian = Total Uang Masuk (Bersih) di kartu ringkasan & Buku Transaksi.
        $summary = LedgerReport::summary(clone $base, '2026-10-01', '2026-10-20');
        foreach (LedgerAnalytics::DIMENSIONS as $dimension) {
            $this->assertSame($summary['money_net'], collect(LedgerAnalytics::breakdown($base, $dimension))->sum('money_net'), $dimension);
        }
        $this->assertSame(1830000.0, $summary['money_net']);
    }

    public function test_daily_trend_uses_wib_days_and_fills_empty_days(): void
    {
        // 00:30 WIB tanggal 6 = 17:30 UTC tanggal 5 — harus masuk tanggal 6.
        $this->row('2026-10-06 00:30', 'FNB', 'POS_FNB', 50000);
        $this->row('2026-10-05 23:30', 'FNB', 'POS_FNB', 20000);
        $base = LedgerReport::applyDateRange(LedgerEntry::query(), '2026-10-04', '2026-10-07');

        $trend = LedgerAnalytics::trend($base, '2026-10-04', '2026-10-07');
        $points = collect($trend['points'])->keyBy('key');

        $this->assertSame('day', $trend['unit']);
        $this->assertSame(['2026-10-04', '2026-10-05', '2026-10-06', '2026-10-07'], $points->keys()->all());
        $this->assertSame(0.0, $points['2026-10-04']['money_in']);
        $this->assertSame(20000.0, $points['2026-10-05']['money_in']);
        $this->assertSame(50000.0, $points['2026-10-06']['money_in']);
    }

    public function test_long_ranges_are_grouped_per_month(): void
    {
        $this->row('2026-08-10 10:00', 'FNB', 'POS_FNB', 10000);
        $this->row('2026-10-10 10:00', 'FNB', 'POS_FNB', 30000);

        $trend = LedgerAnalytics::trend(LedgerEntry::query(), null, null);

        $this->assertSame('month', $trend['unit']);
        $this->assertSame(['2026-08', '2026-09', '2026-10'], array_column($trend['points'], 'key'));
        $this->assertSame([10000.0, 0.0, 30000.0], array_column($trend['points'], 'money_in'));
    }

    public function test_analytics_page_shows_ledger_totals_including_fnb(): void
    {
        $this->seedMonth();
        $this->actingAs($this->owner);

        Livewire::test(Analytics::class)
            ->assertOk()
            ->assertSet('preset', 'bulan_ini')
            ->assertSee('Pendapatan Bersih (Net Revenue)')
            ->assertSee('Rp 1.830.000')
            ->assertSee('POS F&amp;B', false)
            ->assertSee('ORD-FNB-001')
            ->assertDontSee('Rp 999.000')
            ->call('setPreset', 'semua')
            ->assertSet('preset', 'semua')
            ->assertSee('Rp 2.829.000')
            ->call('setPreset', 'tidak-ada')
            ->assertSet('preset', LedgerReport::DEFAULT_PRESET)
            // Tanggal kustom ngawur dari browser diabaikan, bukan error 500.
            ->call('setPreset', 'kustom')
            ->set('dari', 'bukan-tanggal')
            ->set('sampai', '2026-10-31')
            ->assertOk();
    }

    public function test_page_splits_channels_service_lines_and_membership_without_trend_chart(): void
    {
        $this->seedMonth();
        $this->row('2026-10-08 10:00', 'MERCH', 'LAINNYA', 75000);
        $this->actingAs($this->owner);

        Livewire::test(Analytics::class)
            // Per loket: frontdesk padel 220rb, F&B 110rb, kasir lain (merch) 75rb; online 1,5jt.
            ->assertViewHas('channels', fn ($c) => $c['padel']['money_net'] === 220000.0
                && $c['fnb']['money_net'] === 110000.0
                && $c['other']['money_net'] === 75000.0
                && $c['online']['money_net'] === 1500000.0)
            // Baris online tanpa pembayaran yang dikenali → gateway "Lainnya", totalnya tetap utuh.
            ->assertViewHas('gateways', fn ($g) => $g === [['label' => 'Lainnya', 'money_net' => 1500000.0, 'transactions' => 1]])
            ->assertSee('POS Frontdesk Padel')
            ->assertSee('POS Kasir F&amp;B', false)
            ->assertSee('Payment Gateway Online')
            ->assertDontSee('Midtrans Gateway')
            ->assertViewHas('serviceLines', function (array $lines) {
                $lines = collect($lines)->keyBy('code');

                return $lines['SEWA_LAPANGAN']['money_net'] === 220000.0
                    && $lines['FNB']['money_net'] === 110000.0
                    // Coaching (Modul 24 Opsi B) sudah punya kategori buku sendiri → tidak "Menyusul" & bisa difilter.
                    && $lines['WELLNESS']['soon'] && ! $lines['COACHING']['soon'] && ! $lines['FNB']['soon']
                    && $lines['COACHING']['filter'] === ['COACHING'] && $lines['COACHING']['money_net'] === 0.0
                    // Merchandise (Kasir Pro Shop) punya lini sendiri — tidak lagi masuk "Lainnya".
                    && $lines['MERCH']['money_net'] === 75000.0
                    && $lines['MERCH']['filter'] === ['MERCH']
                    && ! $lines->has('LAINNYA')
                    && ! $lines->has('MEMBERSHIP');
            })
            // Lini layanan + membership = Pendapatan Bersih.
            ->assertViewHas('membership', fn ($m) => $m['sales'] === 1500000.0 && $m['others'] === 405000.0)
            ->assertSee('Distribusi Kanal Pembayaran')
            ->assertSee('Food &amp; Beverage (F&amp;B)', false)
            ->assertSee('Akses wellness di luar paket membership')
            ->assertSee('Pelatih &amp; Coaching Session', false)
            ->assertSee('Menyusul')
            ->assertSee('Penjualan merchandise di Kasir Pro Shop')
            ->assertDontSee('Lainnya (Merchandise)')
            ->assertSee('Omzet Penjualan Membership')
            ->assertSee('Rp 1.905.000')
            ->assertDontSee('Tren Uang Masuk');
    }

    public function test_online_money_is_split_per_payment_gateway(): void
    {
        foreach ([['DOKU', 400000], ['DOKU', 350000], ['MIDTRANS', 600000]] as $i => [$gateway, $amount]) {
            $order = Order::create(['order_number' => 'ORD-GW-'.$i, 'order_type' => 'ONLINE_BOOKING', 'subtotal' => $amount, 'grand_total' => $amount, 'payment_status' => 'PAID']);
            $payment = Payment::create([
                'order_id' => $order->id, 'payment_gateway' => $gateway, 'transaction_id' => 'GW-'.$i,
                'amount' => $amount, 'payment_method' => 'BCA_VA', 'status' => 'SUCCESS',
            ]);
            $this->row('2026-10-1'.$i.' 10:00', 'SEWA_LAPANGAN', 'ONLINE_PADEL', $amount, ['payment_id' => $payment->id, 'method' => 'BCA_VA']);
        }
        $this->actingAs($this->owner);

        Livewire::test(Analytics::class)
            ->assertViewHas('gateways', fn ($g) => $g === [
                ['label' => 'DOKU', 'money_net' => 750000.0, 'transactions' => 2],
                ['label' => 'Midtrans', 'money_net' => 600000.0, 'transactions' => 1],
            ])
            ->assertViewHas('channels', fn ($c) => $c['online']['money_net'] === 1350000.0)
            ->assertSee('DOKU Rp 750.000 (2 trx)');
    }

    public function test_preset_cannot_be_changed_directly_from_the_browser(): void
    {
        $this->actingAs($this->owner);
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(Analytics::class)->set('preset', 'bulan_ini');
    }

    public function test_breakdown_rows_link_to_a_filtered_buku_transaksi(): void
    {
        $this->seedMonth();
        $this->actingAs($this->owner);

        $url = Livewire::test(Analytics::class)->instance()->bukuUrl(['kategori' => ['FNB']]);
        $this->assertStringContainsString('periode=bulan_ini', $url);
        $this->assertStringContainsString('kategori', $url);

        Livewire::withQueryParams(['periode' => 'bulan_ini', 'kategori' => ['FNB', 'NGAWUR'], 'metode' => ['QRIS']])
            ->test(BukuTransaksi::class)
            ->assertSet('tableFilters.periode.preset', 'bulan_ini')
            ->assertSet('tableFilters.category.values', ['FNB'])
            ->assertSet('tableFilters.payment_method.values', ['QRIS'])
            ->assertSee('ORD-FNB-001');
    }

    /**
     * QRIS manual (QR statis + RRN), QRIS otomatis kasir, dan QRIS online semuanya berkode QRIS. Dulu filter Metode
     * Bayar cuma punya satu pilihan "QRIS Otomatis (Kasir) (QRIS)" yang ternyata memuat ketiganya — QRIS manual seolah
     * tidak ada. Sekarang dipilah dari gateway & shift kasir yang sudah tersimpan di baris buku (data lama ikut).
     */
    public function test_qris_is_split_into_manual_cashier_auto_and_online_in_filter_and_analytics(): void
    {
        $shift = (string) Str::ulid();
        $this->row('2026-10-05 10:00', 'SEWA_LAPANGAN', 'POS_WALKIN_PADEL', 100000, ['order_number' => 'ORD-QR-MANUAL', 'gateway' => 'CASHIER_POS', 'pos_shift_id' => $shift, 'label' => 'QRIS Instan (GoPay/OVO/BCA)']);
        $this->row('2026-10-05 11:00', 'FNB', 'POS_FNB', 20000, ['order_number' => 'ORD-QR-LAMA', 'label' => 'QRIS Kasir Frontdesk']); // baris lama tanpa gateway = kasir
        $this->row('2026-10-05 12:00', 'SEWA_LAPANGAN', 'POS_WALKIN_PADEL', 200000, ['order_number' => 'ORD-QR-KASIR', 'gateway' => 'DOKU', 'pos_shift_id' => $shift, 'label' => 'QRIS Otomatis (Kasir)']);
        $this->row('2026-10-05 13:00', 'SEWA_LAPANGAN', 'ONLINE_PADEL', 300000, ['order_number' => 'ORD-QR-ONLINE', 'gateway' => 'DOKU', 'label' => 'QRIS Instan (GoPay/OVO/BCA)']);
        $this->row('2026-10-05 14:00', 'SEWA_LAPANGAN', 'ONLINE_PADEL', 400000, ['order_number' => 'ORD-QR-MIDTRANS', 'gateway' => 'MIDTRANS', 'label' => 'QRIS Instan (GoPay/OVO/BCA)']);
        $this->row('2026-10-05 15:00', 'SEWA_LAPANGAN', 'POS_WALKIN_PADEL', 50000, ['order_number' => 'ORD-EDC', 'gateway' => 'CASHIER_POS', 'method' => 'EDC_BCA', 'label' => 'Debit/Kartu EDC BCA']);
        $this->actingAs($this->owner);

        // Pilihan filter: QRIS polos diganti tiga jenis; metode lain tetap.
        $options = LedgerReport::methodOptions();
        $this->assertArrayNotHasKey('QRIS', $options);
        $this->assertSame(array_keys(LedgerReport::QRIS_VARIANTS), array_values(array_intersect(array_keys($options), array_keys(LedgerReport::QRIS_VARIANTS))));
        $this->assertArrayHasKey('EDC_BCA', $options);

        $orders = fn (array $methods) => LedgerReport::applyFilters(LedgerEntry::query(), ['periode' => 'semua', 'method' => $methods])
            ->orderBy('order_number')->pluck('order_number')->all();
        $this->assertSame(['ORD-QR-LAMA', 'ORD-QR-MANUAL'], $orders([LedgerReport::QRIS_MANUAL]));
        $this->assertSame(['ORD-QR-KASIR'], $orders([LedgerReport::QRIS_KASIR]));
        $this->assertSame(['ORD-QR-MIDTRANS', 'ORD-QR-ONLINE'], $orders([LedgerReport::QRIS_ONLINE]));
        $this->assertEqualsCanonicalizing(['ORD-EDC', 'ORD-QR-LAMA', 'ORD-QR-MANUAL'], $orders([LedgerReport::QRIS_MANUAL, 'EDC_BCA']));
        $this->assertCount(5, $orders(['QRIS']), 'kode QRIS polos (tautan lama) tetap = semua QRIS');

        // Tabel Buku Transaksi memakai filter yang sama (bukan whereIn payment_method).
        Livewire::withQueryParams(['periode' => 'semua', 'metode' => [LedgerReport::QRIS_MANUAL]])
            ->test(BukuTransaksi::class)
            ->assertSet('tableFilters.payment_method.values', [LedgerReport::QRIS_MANUAL])
            ->assertSee('ORD-QR-MANUAL')
            ->assertSee('ORD-QR-LAMA')
            ->assertDontSee('ORD-QR-KASIR')
            ->assertDontSee('ORD-QR-ONLINE');

        // Analytics: satu baris per jenis QRIS, tautannya membuka jenis yang sama; total tetap = ringkasan.
        $base = LedgerReport::applyDateRange(LedgerEntry::query(), '2026-10-01', '2026-10-20');
        $method = collect(LedgerAnalytics::breakdown($base, 'method'))->keyBy('method_code');
        $this->assertSame(120000.0, $method[LedgerReport::QRIS_MANUAL]['money_net']);
        $this->assertSame(200000.0, $method[LedgerReport::QRIS_KASIR]['money_net']);
        $this->assertSame(700000.0, $method[LedgerReport::QRIS_ONLINE]['money_net']);
        $this->assertSame(LedgerReport::QRIS_VARIANTS[LedgerReport::QRIS_MANUAL], $method[LedgerReport::QRIS_MANUAL]['label']);
        $this->assertSame(50000.0, $method['EDC_BCA']['money_net']);
        $this->assertSame(LedgerReport::summary(clone $base)['money_net'], collect(LedgerAnalytics::breakdown($base, 'method'))->sum('money_net'));

        Livewire::test(Analytics::class)->call('setPreset', 'semua')
            ->assertSee(LedgerReport::QRIS_VARIANTS[LedgerReport::QRIS_MANUAL])
            ->assertSee('metode%5B0%5D='.LedgerReport::QRIS_MANUAL, false);

        // Nama di tabel (tampilan saja): QRIS kasir = QRIS Static / QR Online (gateway); QRIS online customer tetap.
        $this->assertSame(['QRIS Static', 'QR Online (Kasir)', 'QRIS (Online Customer)'], array_values(LedgerReport::QRIS_VARIANTS));
        $label = fn (string $order) => LedgerReport::methodLabel(LedgerReport::grouped(LedgerEntry::query())->where('order_number', $order)->first(), qrisNames: true);
        $this->assertSame(['QRIS Static', 'QRIS Static', 'QR Online (DOKU)', 'QRIS Instan (GoPay/OVO/BCA)'], array_map($label, ['ORD-QR-MANUAL', 'ORD-QR-LAMA', 'ORD-QR-KASIR', 'ORD-QR-ONLINE']));
        Livewire::withQueryParams(['periode' => 'semua'])->test(BukuTransaksi::class)
            ->assertSee('QRIS Static')->assertSee('QR Online (DOKU)');
        // Label yang tersimpan & export tidak berubah.
        $this->assertSame('QRIS Otomatis (Kasir)', LedgerEntry::where('order_number', 'ORD-QR-KASIR')->value('payment_method_label'));
        $this->assertSame('QRIS Otomatis (Kasir)', LedgerReport::methodLabel(LedgerEntry::where('order_number', 'ORD-QR-KASIR')->first()));
    }

    /**
     * Kartu debit / kredit EDC: Buku Transaksi (tabel, Excel, PDF) menampilkan mesin + bank penerbit, jaringan kartu & 4 digit
     * dari bukti EDC yang disimpan kasir — transaksi lama ikut tanpa mengubah data buku.
     */
    public function test_edc_card_details_are_shown_in_table_excel_and_pdf_without_changing_the_ledger(): void
    {
        $order = Order::create(['order_number' => 'ORD-PXDL-1', 'order_type' => 'WALK_IN', 'subtotal' => 600000, 'grand_total' => 600000, 'payment_status' => 'PAID']);
        $payment = Payment::create([
            'order_id' => $order->id, 'payment_gateway' => 'CASHIER_POS', 'transaction_id' => 'EDC-1', 'amount' => 600000, 'payment_method' => 'DEBIT_CARD', 'status' => 'SUCCESS',
            'payload_log' => ['edc_details' => ['terminal' => 'EDC_BCA', 'card_type' => 'DEBIT', 'card_network' => 'GPN', 'card_issuer' => 'BCA',
                'card_last_4' => '8842', 'approval_code' => '535698', 'trace_number' => '000103']],
        ]);
        $credit = Payment::create([
            'order_id' => $order->id, 'payment_gateway' => 'CASHIER_POS', 'transaction_id' => 'EDC-2', 'amount' => 100000, 'payment_method' => 'CREDIT_CARD', 'status' => 'SUCCESS',
            'payload_log' => ['edc_details' => ['terminal' => 'EDC_MANDIRI', 'card_type' => 'CREDIT', 'card_last_4' => '1111', 'approval_code' => 'A1B2C3', 'trace_number' => '000200']],
        ]);
        $this->row('2026-10-05 16:39', 'SEWA_LAPANGAN', 'POS_WALKIN_PADEL', 600000, ['order_number' => 'ORD-PXDL-1', 'payment_id' => $payment->id, 'gateway' => 'CASHIER_POS',
            'method' => 'DEBIT_CARD', 'label' => 'Kartu Debit (EDC)']);
        $this->row('2026-10-05 16:40', 'FNB', 'POS_FNB', 100000, ['order_number' => 'ORD-PXDL-2', 'payment_id' => $credit->id, 'gateway' => 'CASHIER_POS',
            'method' => 'CREDIT_CARD', 'label' => 'Kartu Kredit (EDC)']);
        $this->row('2026-10-05 16:41', 'FNB', 'POS_FNB', 50000, ['order_number' => 'ORD-EDC-NOPROOF', 'method' => 'DEBIT_CARD', 'label' => 'Kartu Debit (EDC)']); // tanpa bukti: label buku
        LedgerEntry::where('order_number', 'ORD-PXDL-1')->update(['payment_reference' => 'APPR 535698 · TRACE 000103']);
        $this->actingAs($this->owner);

        $row = fn (string $order) => LedgerReport::grouped(LedgerEntry::query())->where('order_number', $order)->first();
        $this->assertSame('Kartu Debit — Mesin EDC BCA', LedgerReport::methodLabel($row('ORD-PXDL-1')));
        $this->assertSame('BCA · GPN · ••••8842 · APPR 535698 · TRACE 000103', LedgerReport::referenceLabel($row('ORD-PXDL-1')));
        $this->assertSame('Kartu Kredit — Mesin EDC Mandiri', LedgerReport::methodLabel($row('ORD-PXDL-2')));
        $this->assertSame('••••1111 · APPR A1B2C3 · TRACE 000200', LedgerReport::referenceLabel($row('ORD-PXDL-2')), 'yang tidak diisi kasir dilewati');
        $this->assertSame('Kartu Debit (EDC)', LedgerReport::methodLabel($row('ORD-EDC-NOPROOF')));

        // Tabel.
        Livewire::withQueryParams(['periode' => 'semua'])->test(BukuTransaksi::class)
            ->assertSee('Kartu Debit — Mesin EDC BCA')
            ->assertSee('BCA · GPN · ••••8842 · APPR 535698 · TRACE 000103');

        // Excel (lembar Transaksi & Rincian Kategori) dan PDF memakai tampilan yang sama.
        $excel = \App\Http\Controllers\Admin\LedgerExportController::transactionRow($row('ORD-PXDL-1'));
        $this->assertSame(['Kartu Debit — Mesin EDC BCA', 'BCA · GPN · ••••8842 · APPR 535698 · TRACE 000103'], [$excel[7], $excel[8]]);
        $this->assertSame('Kartu Debit — Mesin EDC BCA', \App\Http\Controllers\Admin\LedgerExportController::detailRow(LedgerEntry::where('order_number', 'ORD-PXDL-1')->first())[7]);
        LedgerReport::flushMemo();
        LedgerReport::primeEdc([$row('ORD-PXDL-2')]);
        $this->assertSame('Kartu Kredit — Mesin EDC Mandiri', \App\Http\Controllers\Admin\LedgerExportController::pdfRow($row('ORD-PXDL-2'))[6]);

        // Data buku tidak berubah.
        $this->assertSame('Kartu Debit (EDC)', LedgerEntry::where('order_number', 'ORD-PXDL-1')->value('payment_method_label'));
        $this->assertSame('APPR 535698 · TRACE 000103', LedgerEntry::where('order_number', 'ORD-PXDL-1')->value('payment_reference'));
    }

    public function test_occupancy_uses_active_courts_operating_hours(): void
    {
        $this->actingAs($this->owner);
        $court = PadelCourt::create(['name' => 'Court A', 'type' => 'INDOOR', 'hourly_rate_regular' => 200000, 'hourly_rate_prime' => 300000, 'is_active' => true, 'open_time' => '08:00', 'close_time' => '18:00']);
        PadelCourt::create(['name' => 'Court Off', 'type' => 'INDOOR', 'hourly_rate_regular' => 200000, 'hourly_rate_prime' => 300000, 'is_active' => false]);
        $customer = User::factory()->customer()->create();
        $start = Carbon::parse('2026-10-20 08:00');
        PadelBooking::create([
            'booking_code' => 'BK-OCC-1', 'user_id' => $customer->id, 'court_id' => $court->id, 'booking_date' => '2026-10-20',
            'start_time' => $start, 'end_time' => $start->copy()->addHours(2), 'court_fee' => 400000, 'total_amount' => 400000, 'status' => 'PAID',
        ]);

        // Hari ini: 2 jam dari 10 jam buka 1 lapangan aktif = 20%.
        Livewire::test(Analytics::class)
            ->call('setPreset', 'hari_ini')
            ->assertSee('20%')
            ->assertSee('Kapasitas 1 Court');
    }
}
