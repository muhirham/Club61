<?php

namespace Tests\Feature\Sponsor;

use App\Filament\Resources\Sponsor\Pages\CreateSponsorOrganization;
use App\Filament\Resources\Sponsor\Pages\ListSponsorOrganizations;
use App\Models\Membership\MembershipPlan;
use App\Models\Membership\MembershipPlanBenefit;
use App\Models\Membership\UserMembership;
use App\Models\Sponsor\SponsorOrganization;
use App\Models\User;
use App\Services\Membership\MembershipBalanceService;
use App\Services\Permission\Club61PermissionMatrix;
use App\Services\Sponsor\SponsorOrganizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SponsorCorporateGrantTest extends TestCase
{
    use RefreshDatabase;

    protected MembershipPlan $corporatePlan;

    protected function setUp(): void
    {
        parent::setUp();
        Club61PermissionMatrix::syncAllPermissions('web');

        $this->corporatePlan = MembershipPlan::create([
            'code' => 'MBR-CORP-T',
            'name' => 'Corporate Tier Test',
            'ownership_type' => 'ORGANIZATIONAL',
            'duration_days' => 365,
            'price' => 25000000,
            'is_active' => true,
        ]);
        MembershipPlanBenefit::create([
            'plan_id' => $this->corporatePlan->id,
            'facility' => 'PADEL',
            'quota_type' => 'HOURS',
            'quota_value' => 200,
            'discount_percent' => 0,
        ]);
    }

    private function grantData(User $pic): array
    {
        return [
            'company_name' => 'PT Demo Sponsor',
            'pic_user_id' => $pic->id,
            'plan_id' => $this->corporatePlan->id,
            // Bawaan tombol = Transfer ke Rekening Perusahaan (Modul 34); test ini memakai jalur tanpa pembayaran.
            'payment' => \App\Filament\Resources\Sponsor\Pages\ListSponsorOrganizations::PAY_NONE,
            'reason' => 'Kontrak sponsor dibayar via transfer korporat.',
        ];
    }

    public function test_super_admin_grants_corporate_membership_without_purchase(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $pic = User::factory()->customer()->create(['name' => 'Rina PIC']);

        $this->actingAs($superAdmin);
        Livewire::test(ListSponsorOrganizations::class)
            ->assertActionVisible('grantCorporateMembership')
            ->callAction('grantCorporateMembership', data: $this->grantData($pic))
            ->assertHasNoActionErrors();

        $organization = SponsorOrganization::with('userMembership')->firstOrFail();
        $this->assertSame('PT Demo Sponsor', $organization->name);
        $this->assertSame($pic->id, $organization->sponsor_admin_user_id);

        $membership = $organization->userMembership;
        $this->assertSame('ACTIVE', $membership->status);
        $this->assertSame('ORGANIZATIONAL', $membership->owner_type);
        $this->assertEquals(0, (float) $membership->purchase_price_snapshot);
        $this->assertEquals(100, (float) $membership->manual_discount_percent);
        $this->assertStringContainsString($superAdmin->name, $membership->manual_discount_reason);
        $this->assertSame($superAdmin->id, $membership->sold_by_admin_id);
        $this->assertEquals(200, $organization->remainingQuota());
    }

    public function test_admin_cannot_see_or_trigger_grant_action(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ListSponsorOrganizations::class)
            ->assertActionHidden('grantCorporateMembership');

        $this->assertSame(0, UserMembership::count());
    }

    public function test_grant_rejects_individual_plan(): void
    {
        $individual = MembershipPlan::create([
            'code' => 'MBR-IND-T',
            'name' => 'Individual Test',
            'ownership_type' => 'INDIVIDUAL',
            'duration_days' => 30,
            'price' => 1000000,
            'is_active' => true,
        ]);

        $this->expectException(\DomainException::class);
        app(SponsorOrganizationService::class)->grantCorporateMembership(
            User::factory()->customer()->create(),
            $individual,
            'PT Salah Paket',
            User::factory()->superAdmin()->create(),
            'uji'
        );
    }

    public function test_relinking_membership_of_deleted_sponsor_restores_it_instead_of_crashing(): void
    {
        $pic = User::factory()->customer()->create();
        $service = app(MembershipBalanceService::class);
        $membership = $service->activateMembership($service->purchasePlan($pic, $this->corporatePlan, ['owner_type' => 'ORGANIZATIONAL']));

        $old = SponsorOrganization::create([
            'name' => 'Sponsor Lama',
            'user_membership_id' => $membership->id,
            'sponsor_admin_user_id' => $pic->id,
            'status' => 'ACTIVE',
        ]);
        $old->delete();

        $this->actingAs(User::factory()->admin()->create());
        Livewire::test(CreateSponsorOrganization::class)
            ->fillForm([
                'name' => 'Sponsor Baru',
                'sponsor_admin_user_id' => $pic->id,
                'user_membership_id' => $membership->id,
                'status' => 'ACTIVE',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, SponsorOrganization::withTrashed()->count());
        $restored = SponsorOrganization::findOrFail($old->id);
        $this->assertSame('Sponsor Baru', $restored->name);
    }

    public function test_auto_create_for_membership_restores_soft_deleted_sponsor(): void
    {
        $pic = User::factory()->customer()->create();
        $service = app(MembershipBalanceService::class);
        $membership = $service->activateMembership($service->purchasePlan($pic, $this->corporatePlan, ['owner_type' => 'ORGANIZATIONAL']));

        $sponsorService = app(SponsorOrganizationService::class);
        $first = $sponsorService->ensureOrganizationForMembership($membership);
        $first->delete();

        $again = $sponsorService->ensureOrganizationForMembership($membership);

        $this->assertSame($first->id, $again->id);
        $this->assertFalse($again->fresh()->trashed());
    }

    public function test_staff_can_preview_pic_dashboard_read_only_but_cashier_cannot(): void
    {
        $pic = User::factory()->customer()->create(['name' => 'Rina PIC']);
        $organization = app(SponsorOrganizationService::class)->grantCorporateMembership(
            $pic, $this->corporatePlan, 'PT Demo Sponsor', User::factory()->superAdmin()->create(), 'uji'
        );

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('corporate.preview', $organization->id))
            ->assertOk()
            ->assertSee('MODE PRATINJAU STAF')
            ->assertSee('Rina PIC')
            ->assertSee('inert', false)
            ->assertDontSee('const API_BASE', false);

        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('corporate.preview', $organization->id))
            ->assertForbidden();

        // PIC asli tetap dapat dashboard penuh (tanpa banner pratinjau, script aksi termuat).
        $this->actingAs($pic)
            ->get(route('customer.corporate'))
            ->assertOk()
            ->assertDontSee('MODE PRATINJAU STAF')
            ->assertSee('const API_BASE', false);
    }
}
