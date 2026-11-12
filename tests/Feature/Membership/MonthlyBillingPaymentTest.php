<?php

namespace Tests\Feature\Membership;

use App\Filament\Pages\TagihanBulanan;
use App\Filament\Resources\Sponsor\Pages\ListSponsorOrganizations;
use App\Models\Finance\LedgerEntry;
use App\Models\Membership\MembershipPlan;
use App\Models\Membership\MembershipPlanBenefit;
use App\Models\Membership\UserMembership;
use App\Models\Membership\UserMembershipBalance;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Padel\PadelPeakHourRule;
use App\Models\Padel\PadelRecurringSchedule;
use App\Models\Pos\ClubFinanceSetting;
use App\Models\Pos\Order;
use App\Models\Pos\Payment;
use App\Models\Sponsor\SponsorOrganization;
use App\Models\User;
use App\Services\Membership\MembershipBalanceService;
use App\Services\Membership\MonthlyBilling;
use App\Services\Membership\MonthlyBillingPayment;
use App\Services\Membership\MonthlyBillingRecapExport;
use App\Services\Padel\PeakHourService;
use App\Services\Padel\RecurringScheduleService;
use App\Services\Permission\Club61PermissionMatrix;
use App\Services\Sponsor\CorporateTransfer;
use App\Services\Sponsor\SponsorOrganizationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Modul 34 — rekap jam main Tagihan Bulanan untuk Finance (halaman + Excel / PDF) dan pencatatan transfer perusahaan
 * dari Kelola Sponsor (pembayaran Tagihan Bulanan dengan nominal manual & pemberian membership corporate berbayar):
 * tanpa shift kasir, tetap masuk pembayaran, Buku Transaksi & Analitik.
 */
class MonthlyBillingPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $pic;

    private PadelCourt $court;

    private UserMembershipBalance $balance;

    private SponsorOrganization $org;

    protected function setUp(): void
    {
        parent::setUp();
        Club61PermissionMatrix::syncAllPermissions('web');
        ClubFinanceSetting::getSettings()->update(['is_admin_fee_enabled' => false, 'is_tax_enabled' => false]);
        Cache::forget(ClubFinanceSetting::CACHE_KEY);
        Storage::fake('local');

        // Senin 2 Nov 2026 10:00; jam peak Senin–Jumat 17–24.
        Carbon::setTestNow(Carbon::parse('2026-11-02 10:00:00'));
        PadelPeakHourRule::query()->delete();
        foreach (range(1, 5) as $day) {
            PadelPeakHourRule::create(['day_of_week' => $day, 'start_hour' => 17, 'end_hour' => 24]);
        }
        app(PeakHourService::class)->flush();

        $this->superAdmin = User::factory()->superAdmin()->create(['is_active' => true]);
        $this->court = PadelCourt::create([
            'name' => 'Court A', 'type' => 'INDOOR', 'hourly_rate_regular' => 200000, 'hourly_rate_prime' => 300000,
            'open_time' => '06:00', 'close_time' => '24:00', 'is_active' => true,
        ]);

        $plan = MembershipPlan::create([
            'code' => 'CORP-HUAWEI', 'name' => 'Huawei Jumat Malam', 'ownership_type' => 'ORGANIZATIONAL', 'duration_days' => 90,
            'price' => 0, 'is_active' => true, 'sales_channel' => MembershipPlan::SALES_POS_ONLY,
        ]);
        MembershipPlanBenefit::create(['plan_id' => $plan->id, 'facility' => 'PADEL', 'quota_type' => 'NONE', 'extra_benefits' => [MonthlyBilling::KEY => ['enabled' => true]]]);
        $this->pic = User::factory()->customer()->create(['name' => 'PIC Huawei']);
        $membership = app(MembershipBalanceService::class)->purchasePlan($this->pic, $plan, ['status' => 'ACTIVE']);
        $membership->update(['start_date' => '2026-11-01', 'end_date' => '2027-01-30']);
        $this->balance = $membership->balances->firstWhere('facility', 'PADEL');
        $this->org = SponsorOrganization::create(['name' => 'Huawei', 'user_membership_id' => $membership->id, 'sponsor_admin_user_id' => $this->pic->id, 'status' => 'ACTIVE']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Jadwal Tetap Jumat 16–19 (1 jam reguler + 2 jam peak), 6 & 13 Nov. */
    private function bookings()
    {
        $schedule = PadelRecurringSchedule::create([
            'name' => 'Huawei Jumat', 'user_membership_id' => $this->balance->user_membership_id,
            'day_of_week' => 5, 'start_time' => '16:00', 'end_time' => '19:00', 'court_ids' => [$this->court->id],
            'starts_on' => '2026-11-02', 'weeks_ahead' => 2, 'status' => PadelRecurringSchedule::STATUS_ACTIVE, 'created_by_id' => $this->superAdmin->id,
        ]);
        $this->assertSame([], app(RecurringScheduleService::class)->generate($schedule, $this->superAdmin)['failed']);

        return PadelBooking::where('recurring_schedule_id', $schedule->id)->orderBy('start_time')->get();
    }

    /** Isian transfer dengan bukti yang sudah tersimpan di folder bukti (seperti hasil FileUpload). */
    private function transfer(string $reference = 'TRF-HUAWEI-11'): array
    {
        $path = CorporateTransfer::PROOF_DIR.'/'.\Illuminate\Support\Str::uuid().'.jpg';
        Storage::disk('local')->put($path, 'bukti');

        return ['transfer_date' => now()->toDateString(), 'sender_bank' => 'BCA', 'reference' => $reference, 'proof_path' => $path];
    }

    private function expectRejected(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail("Seharusnya ditolak: {$message}");
        } catch (HttpException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_recap_counts_played_no_show_and_scheduled_hours_split_by_peak(): void
    {
        [$first, $second] = $this->bookings()->all();

        // Minggu pertama tidak hadir (tetap dihitung); yang dibatalkan tidak masuk rekap.
        $this->travelTo(Carbon::parse('2026-11-07 08:00'));
        app(\App\Services\Padel\PadelBookingService::class)->syncExpiredAndCompletedBookings();
        $this->assertSame('EXPIRED', $first->fresh()->status);
        $cancelled = PadelBooking::create($second->replicate(['booking_code', 'qr_code_hash'])->fill(['booking_code' => 'CANCEL-1', 'status' => 'CANCELLED', 'start_time' => '2026-11-20 16:00', 'end_time' => '2026-11-20 17:00', 'booking_date' => '2026-11-20'])->getAttributes());

        $recap = MonthlyBilling::recap($this->balance, '2026-11');
        $this->assertSame(
            ['bookings' => 2, 'hours' => 6.0, 'regular_hours' => 2.0, 'peak_hours' => 4.0, 'played_hours' => 0.0, 'no_show_hours' => 3.0, 'scheduled_hours' => 3.0],
            $recap['totals'],
        );
        $this->assertSame(['Tidak Hadir', 'Belum Main'], $recap['rows']->pluck('status_label')->all());
        $this->assertSame(['Jadwal Tetap'], $recap['rows']->pluck('source')->unique()->values()->all());
        $this->assertNotContains($cancelled->booking_code, $recap['rows']->pluck('code')->all());
        $this->assertSame(0, MonthlyBilling::recap($this->balance, '2026-12')['totals']['bookings']);
    }

    public function test_finance_records_the_monthly_payment_with_a_manual_amount_outside_the_cashier(): void
    {
        $this->bookings();
        $service = app(MonthlyBillingPayment::class);

        $payment = $service->record($this->balance, '2026-11', 4500000, $this->transfer(), $this->superAdmin);

        $this->assertSame([CorporateTransfer::GATEWAY, 'TRANSFER_BANK', 'SUCCESS', null], [$payment->payment_gateway, $payment->payment_method, $payment->status, $payment->pos_shift_id]);
        $this->assertEquals(['2026-11', 'Huawei', 6], [$payment->payload_log['monthly_period'], $payment->payload_log['monthly_billed_name'], $payment->payload_log['monthly_hours']]);
        $order = Order::findOrFail($payment->order_id);
        $this->assertSame(['CORPORATE', 'PAID', 4500000.0], [$order->order_type, $order->payment_status, (float) $order->grand_total]);
        $this->assertStringContainsString('Tagihan Bulanan Huawei — November 2026 (6 jam)', $order->items->first()->item_name);

        // Buku Transaksi: Sewa Lapangan, sumber Transfer Corporate, "kasir" = staf yang mencatat; tanpa shift.
        $row = LedgerEntry::where('payment_id', $payment->id)->sole();
        $this->assertSame(['SEWA_LAPANGAN', 'CORPORATE_TRANSFER', $this->superAdmin->name, CorporateTransfer::LABEL], [$row->category, $row->source, $row->cashier_name, $row->payment_method_label]);
        $this->assertEquals(4500000, (float) $row->total_amount);
        $this->assertNull($row->pos_shift_id);
        Livewire::actingAs($this->superAdmin)->test(\App\Filament\Pages\Analytics::class)->assertSee('Transfer Corporate');

        $this->assertSame($payment->id, MonthlyBilling::paymentFor($this->balance->id, '2026-11')?->id);
        $this->assertDatabaseHas('activity_logs', ['event' => 'monthly_bill.payment_recorded']);

        // Periode yang sama / nomor referensi yang sama / periode di luar masa aktif / tanpa izin → ditolak.
        $this->expectRejected(fn () => $service->record($this->balance, '2026-11', 100000, $this->transfer('TRF-LAIN'), $this->superAdmin), 'sudah dicatat');
        $this->expectRejected(fn () => $service->record($this->balance, '2027-03', 100000, $this->transfer('TRF-LAIN'), $this->superAdmin), 'Periode tagihan tidak valid');
        $this->travelTo(Carbon::parse('2026-12-02 10:00'));
        $this->expectRejected(fn () => $service->record($this->balance, '2026-12', 100000, $this->transfer('TRF-HUAWEI-11'), $this->superAdmin), 'sudah pernah dicatat');
        $staff = User::factory()->create(['is_active' => true]);
        $this->expectRejected(fn () => $service->record($this->balance, '2026-12', 100000, $this->transfer('TRF-DES'), $staff), 'Akses ditolak');
        $this->assertSame(1, Payment::where('payment_gateway', CorporateTransfer::GATEWAY)->count());
        $this->assertCount(1, Storage::disk('local')->files(CorporateTransfer::PROOF_DIR), 'bukti dari pencatatan yang gagal dihapus');

        // Isian bukti direkayasa: menunjuk bukti pencatatan lain / berkas yang tidak ada / di luar folder → ditolak, dan bukti
        // lama TIDAK ikut terhapus oleh pembersihan pencatatan yang gagal.
        $existing = CorporateTransfer::of($payment)['proof_path'];
        $this->expectRejected(fn () => $service->record($this->balance, '2026-12', 100000, ['proof_path' => $existing] + $this->transfer('TRF-REUSE'), $this->superAdmin), 'sudah dipakai pencatatan lain');
        Storage::disk('local')->assertExists($existing);
        $this->expectRejected(fn () => $service->record($this->balance, '2026-12', 100000, ['proof_path' => CorporateTransfer::PROOF_DIR.'/tidak-ada.jpg'] + $this->transfer('TRF-X1'), $this->superAdmin), 'Bukti transfer wajib');
        $this->expectRejected(fn () => $service->record($this->balance, '2026-12', 100000, ['proof_path' => CorporateTransfer::PROOF_DIR.'/../.env'] + $this->transfer('TRF-X2'), $this->superAdmin), 'Bukti transfer wajib');
        $this->assertSame(['2026-11'], MonthlyBilling::paidPeriods($this->balance->id));
    }

    public function test_kelola_sponsor_records_transfers_for_monthly_and_one_time_packages(): void
    {
        $this->bookings();

        // Perusahaan Tagihan Bulanan: pilih jenis Tagihan Bulanan + periode, bukti diunggah.
        Livewire::actingAs($this->superAdmin)->test(ListSponsorOrganizations::class)
            ->assertTableActionVisible('recordTransfer', $this->org)
            ->callTableAction('recordTransfer', $this->org, data: [
                'kind' => \App\Filament\Resources\Sponsor\SponsorOrganizationResource::TRANSFER_MONTHLY,
                'month' => '2026-11', 'amount' => 3600000, 'transfer_date' => '2026-11-02', 'sender_bank' => 'Mandiri', 'reference' => 'REF778899',
                'proof_path' => UploadedFile::fake()->image('bukti.jpg', 600, 900),
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Pembayaran Transfer Tercatat');

        $payment = MonthlyBilling::paymentFor($this->balance->id, '2026-11');
        $this->assertEquals(3600000, (float) $payment->amount);
        $this->assertSame(['Mandiri', 'REF778899'], [CorporateTransfer::of($payment)['sender_bank'], CorporateTransfer::of($payment)['reference']]);
        Storage::disk('local')->assertExists(CorporateTransfer::of($payment)['proof_path']);

        // Perusahaan paket biasa (bayar sekali, mis. IM3): jenis otomatis Pembayaran Paket, kartu & kuota tidak berubah.
        $regular = MembershipPlan::create(['code' => 'CORP-IM3', 'name' => 'IM3 Platinum', 'ownership_type' => 'ORGANIZATIONAL', 'duration_days' => 120, 'price' => 0, 'is_active' => true]);
        MembershipPlanBenefit::create(['plan_id' => $regular->id, 'facility' => 'PADEL', 'quota_type' => 'HOURS', 'quota_value' => 100]);
        $im3Pic = User::factory()->customer()->create();
        $im3Membership = app(MembershipBalanceService::class)->purchasePlan($im3Pic, $regular, ['status' => 'ACTIVE']);
        $im3 = SponsorOrganization::create(['name' => 'IM3 Platinum', 'user_membership_id' => $im3Membership->id, 'sponsor_admin_user_id' => $im3Pic->id, 'status' => 'ACTIVE']);
        $quotaBefore = (float) $im3Membership->balanceFor('PADEL')->remaining_quota;

        $this->actingAs($this->superAdmin);
        Livewire::test(\App\Filament\Resources\Sponsor\Pages\EditSponsorOrganization::class, ['record' => $im3->getRouteKey()])
            ->assertActionVisible('recordTransfer')
            ->callAction('recordTransfer', data: [
                'amount' => 25000000, 'transfer_date' => '2026-11-02', 'sender_bank' => 'BCA', 'reference' => 'TRF-IM3-01',
                'proof_path' => UploadedFile::fake()->image('bukti-im3.jpg'),
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Pembayaran Transfer Tercatat');

        $package = CorporateTransfer::payments()->where('payload_log->corporate_kind', CorporateTransfer::KIND_PACKAGE)->sole();
        $this->assertEquals(25000000, (float) $package->amount);
        $row = LedgerEntry::where('payment_id', $package->id)->sole();
        $this->assertSame(['MEMBERSHIP', 'CORPORATE_TRANSFER'], [$row->category, $row->source]);
        $this->assertEquals($quotaBefore, (float) $im3Membership->fresh()->balanceFor('PADEL')->remaining_quota);
        $this->assertSame('ACTIVE', $im3Membership->fresh()->status);
        $this->assertSame(1, UserMembership::count() - 1, 'tidak ada kartu baru');
        $this->assertSame('Pembayaran Paket · IM3 Platinum', CorporateTransfer::history()->firstWhere('id', $package->id)['kind']);
        $this->assertTrue(CorporateTransfer::history()->firstWhere('id', $package->id)['voidable']);
    }
    public function test_a_wrong_entry_is_voided_from_the_reports_and_can_be_recorded_again(): void
    {
        $this->bookings();
        $service = app(MonthlyBillingPayment::class);
        $payment = $service->record($this->balance, '2026-11', 5000000, $this->transfer(), $this->superAdmin);

        $this->actingAs($this->superAdmin);
        Livewire::test(TagihanBulanan::class)
            ->assertSee('Riwayat Transfer Perusahaan')->assertSee('Tagihan Bulanan November 2026')
            ->call('downloadProof', $payment->id)->assertFileDownloaded('bukti-transfer-'.$payment->transaction_id.'.jpg')
            ->call('openVoid', $payment->id)->assertSet('voidPaymentId', $payment->id)
            ->set('voidReason', 'Nominal salah ketik')
            ->call('confirmVoid')->assertSet('voidPaymentId', null)->assertNotified('Pencatatan Dibatalkan');

        $this->assertNotNull($payment->fresh()->payload_log['voided'] ?? null);
        $this->assertSame('REFUNDED', Order::findOrFail($payment->order_id)->payment_status);
        $this->assertEquals(0, (float) LedgerEntry::where('payment_id', $payment->id)->sum('total_amount'), 'baris pembalik menolkan pendapatan');
        $this->assertNull(MonthlyBilling::paymentFor($this->balance->id, '2026-11'));
        $this->assertDatabaseHas('activity_logs', ['event' => 'corporate_transfer.voided']);
        $this->expectRejected(fn () => CorporateTransfer::void($payment->fresh(), 'Dua kali', $this->superAdmin), 'sudah dibatalkan');

        // Periode bisa dicatat ulang dengan nominal yang benar (referensi transfer yang sama boleh dipakai lagi).
        $again = $service->record($this->balance, '2026-11', 4500000, $this->transfer(), $this->superAdmin);
        $this->assertEquals(4500000, (float) $again->amount);
    }

    public function test_corporate_membership_can_be_granted_with_a_recorded_transfer(): void
    {
        $plan = MembershipPlan::create([
            'code' => 'CORP-TELKOM', 'name' => 'Telkom 50 Jam', 'ownership_type' => 'ORGANIZATIONAL', 'duration_days' => 90,
            'price' => 10000000, 'is_active' => true, 'sales_channel' => MembershipPlan::SALES_POS_ONLY,
        ]);
        MembershipPlanBenefit::create(['plan_id' => $plan->id, 'facility' => 'PADEL', 'quota_type' => 'HOURS', 'quota_value' => 50]);
        $pic = User::factory()->customer()->create(['name' => 'PIC Telkom']);

        $organization = app(SponsorOrganizationService::class)->grantCorporateMembershipPaid($pic, $plan, 'Telkom', $this->superAdmin, $this->transfer('TRF-TELKOM-1'));

        $membership = UserMembership::findOrFail($organization->user_membership_id);
        $this->assertSame(['ACTIVE', 'Telkom', $pic->id], [$membership->status, $organization->name, $organization->sponsor_admin_user_id]);
        $this->assertEquals(50, (float) $membership->balanceFor('PADEL')->remaining_quota);
        $payment = Payment::where('order_id', $membership->order_id)->sole();
        $this->assertSame([CorporateTransfer::GATEWAY, 10000000.0, null], [$payment->payment_gateway, (float) $payment->amount, $payment->pos_shift_id]);
        $row = LedgerEntry::where('payment_id', $payment->id)->sole();
        $this->assertSame(['MEMBERSHIP', 'CORPORATE_TRANSFER'], [$row->category, $row->source]);
        $this->assertDatabaseHas('activity_logs', ['event' => 'membership.granted_paid']);

        $history = CorporateTransfer::history();
        $this->assertSame(['Telkom', 'Membership Corporate · Telkom 50 Jam', 10000000.0, false], [$history[0]['company'], $history[0]['kind'], $history[0]['amount'], $history[0]['voidable']]);

        // Pemberian tanpa pembayaran tetap tersedia (alasan wajib) dari tombol yang sama.
        $freePic = User::factory()->customer()->create(['name' => 'PIC Gratis']);
        Livewire::actingAs($this->superAdmin)->test(ListSponsorOrganizations::class)
            ->callAction('grantCorporateMembership', data: [
                'company_name' => 'Sponsor Event', 'pic_user_id' => $freePic->id, 'plan_id' => $plan->id,
                'payment' => ListSponsorOrganizations::PAY_NONE, 'reason' => 'Kompensasi kerja sama event',
            ])
            ->assertHasNoActionErrors()->assertNotified('Membership Corporate Aktif');
        $this->assertTrue(SponsorOrganization::where('name', 'Sponsor Event')->exists());
        $this->assertSame(1, Payment::where('payment_gateway', CorporateTransfer::GATEWAY)->count());
    }

    public function test_tagihan_bulanan_page_shows_the_recap_and_exports_it_for_finance(): void
    {
        $this->bookings();
        $this->actingAs($this->superAdmin);

        $page = Livewire::test(TagihanBulanan::class)
            ->assertSet('balanceId', $this->balance->id)->assertSet('month', '2026-11')
            ->assertSee('Rincian Jam Main · Huawei · November 2026')->assertSee('Belum Dibayar')
            ->assertDontSee('Rp 1.080.000');
        $page->call('exportExcel')->assertFileDownloaded('Rekap-Tagihan-Bulanan-huawei-2026-11.xlsx');
        $page->call('exportPdf')->assertFileDownloaded('Rekap-Tagihan-Bulanan-huawei-2026-11.pdf');
        $this->assertDatabaseHas('activity_logs', ['event' => 'monthly_bill.recap_exported']);

        $html = view('exports.rekap-tagihan-bulanan-pdf', app(MonthlyBillingRecapExport::class)->data($this->balance, '2026-11') + ['printedBy' => 'Finance', 'printedAt' => '-'])->render();
        foreach (['Rekap Jam Main Tagihan Bulanan', 'Huawei', 'PIC Huawei', 'November 2026', 'Belum dibayar', '6 jam'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        // Bulan / kartu rekayasa → tidak bisa diunduh.
        $page->set('month', '1999-01')->call('exportPdf')->assertNoFileDownloaded()->assertNotified('Pilih Perusahaan dan Periode');
    }

    public function test_page_and_recording_need_their_permissions(): void
    {
        $receptionist = User::factory()->create(['is_active' => true]);
        $receptionist->givePermissionTo(Club61PermissionMatrix::defaultRolePermissions('receptionist'));
        $this->actingAs($receptionist);
        $this->assertFalse(TagihanBulanan::canAccess());
        $this->assertNotContains(CorporateTransfer::PERMISSION, Club61PermissionMatrix::defaultRolePermissions('receptionist'));

        $receptionist->givePermissionTo('View:TagihanBulanan');
        $this->assertTrue(TagihanBulanan::canAccess());
        $this->assertFalse(Livewire::test(TagihanBulanan::class)->instance()->canVoid);
    }
}
