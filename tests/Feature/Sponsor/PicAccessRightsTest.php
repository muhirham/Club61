<?php

namespace Tests\Feature\Sponsor;

use App\Filament\Resources\Sponsor\Pages\EditSponsorOrganization;
use App\Models\Audit\ActivityLog;
use App\Models\Membership\MembershipPlan;
use App\Models\Membership\UserMembership;
use App\Models\Membership\UserMembershipBalance;
use App\Models\Sponsor\SponsorMemberVoucher;
use App\Models\Sponsor\SponsorOrganization;
use App\Models\Sponsor\SponsorOrganizationMember;
use App\Models\User;
use App\Services\Permission\Club61PermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Hak Akses PIC per perusahaan (Kelola Sponsor > Edit): aksi yang dimatikan admin disembunyikan dari halaman Tim
 * Corporate DAN ditolak server. Kolom kosong (perusahaan lama) = semua aksi tetap boleh seperti sebelumnya.
 */
class PicAccessRightsTest extends TestCase
{
    use RefreshDatabase;

    protected User $pic;

    protected SponsorOrganization $org;

    protected SponsorOrganizationMember $member;

    protected UserMembershipBalance $balance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pic = User::factory()->create();
        $plan = MembershipPlan::create([
            'code' => 'MBR-CORP-'.uniqid(), 'name' => 'IM3 Platinum', 'ownership_type' => 'ORGANIZATIONAL',
            'duration_days' => 365, 'price' => 50000000, 'is_active' => true,
        ]);
        $membership = UserMembership::create([
            'membership_code' => 'MBR-CORP-'.uniqid(), 'owner_type' => 'ORGANIZATIONAL', 'user_id' => $this->pic->id,
            'plan_id' => $plan->id, 'status' => 'ACTIVE', 'start_date' => now(), 'end_date' => now()->addYear(),
        ]);
        $this->org = SponsorOrganization::create([
            'name' => 'Indosat Im3 Platinum', 'user_membership_id' => $membership->id, 'sponsor_admin_user_id' => $this->pic->id,
        ]);
        $this->balance = UserMembershipBalance::create([
            'user_membership_id' => $membership->id, 'facility' => 'PADEL', 'quota_type' => 'HOURS', 'initial_quota' => 100, 'remaining_quota' => 100,
        ]);
        $employee = User::factory()->create(['name' => 'Budi Karyawan', 'phone' => '081234500001']);
        $this->member = SponsorOrganizationMember::create(['sponsor_organization_id' => $this->org->id, 'user_id' => $employee->id, 'status' => 'ACTIVE']);
    }

    /** Halaman web setelah panggilan API: guard sanctum yang sudah ter-resolve dibuang dulu (artefak test saja). */
    private function page(): \Illuminate\Testing\TestResponse
    {
        auth()->forgetGuards();
        auth()->shouldUse('web');

        return $this->actingAs($this->pic, 'web')->get('/corporate')->assertOk();
    }

    private function restrict(array $actions): void
    {
        $this->org->update(['pic_restricted_actions' => $actions]);
    }

    public function test_existing_companies_keep_every_action_and_voucher_actions_are_logged(): void
    {
        $this->assertNull($this->org->fresh()->pic_restricted_actions);
        $this->actingAs($this->pic);

        $this->getJson('/api/v1/sponsor/organization')->assertOk()
            ->assertJsonPath('data.pic_allowed_actions', array_keys(SponsorOrganization::PIC_ACTIONS));

        $this->postJson("/api/v1/sponsor/organization/members/{$this->member->id}/release-voucher", ['hours' => 3])->assertStatus(201);
        $voucher = SponsorMemberVoucher::sole();
        $this->patchJson("/api/v1/sponsor/organization/vouchers/{$voucher->id}", ['hours_granted' => 4])->assertOk();
        $this->postJson('/api/v1/sponsor/organization/members/bulk-release-voucher', ['mode' => 'uniform', 'hours' => 2])->assertOk();
        $this->deleteJson("/api/v1/sponsor/organization/vouchers/{$voucher->id}")->assertOk();

        $this->assertSame('PIC memberikan 3 jam ke Budi Karyawan — Indosat Im3 Platinum', ActivityLog::where('event', 'sponsor.voucher_released')->sole()->description);
        $this->assertStringContainsString('dari 3 menjadi 4 jam', ActivityLog::where('event', 'sponsor.voucher_updated')->sole()->description);
        $this->assertSame(1, ActivityLog::where('event', 'sponsor.voucher_bulk_released')->count());
        $this->assertSame('WARNING', ActivityLog::where('event', 'sponsor.voucher_deleted')->sole()->severity);

        $this->page()
            ->assertSee('data-pic-add-members', false)->assertSee('data-pic-bulk', false)
            ->assertSee('data-pic-give-hours', false)->assertSee('data-pic-edit-voucher', false)
            ->assertSee('initial_hours_input', false)
            ->assertDontSee('data-pic-restricted', false);
    }

    public function test_disabled_actions_are_rejected_by_the_server_without_touching_the_quota(): void
    {
        $this->actingAs($this->pic);
        $this->postJson("/api/v1/sponsor/organization/members/{$this->member->id}/release-voucher", ['hours' => 3])->assertStatus(201);
        $voucher = SponsorMemberVoucher::sole();
        $this->restrict(array_keys(SponsorOrganization::PIC_ACTIONS));
        $denied = 'Aksi ini tidak diizinkan untuk akun Anda. Hubungi Club 61 untuk mengubah hak akses.';

        $this->postJson("/api/v1/sponsor/organization/members/{$this->member->id}/release-voucher", ['hours' => 1])
            ->assertStatus(403)->assertJsonPath('message', $denied);
        $this->postJson('/api/v1/sponsor/organization/members/bulk-release-voucher', ['mode' => 'uniform', 'hours' => 1])->assertStatus(403);
        $this->patchJson("/api/v1/sponsor/organization/vouchers/{$voucher->id}", ['hours_granted' => 5])->assertStatus(403);
        $this->deleteJson("/api/v1/sponsor/organization/vouchers/{$voucher->id}")->assertStatus(403);
        $this->postJson('/api/v1/sponsor/organization/members', ['name' => 'Siti Rahma', 'phone' => '081399887766'])->assertStatus(403);
        $this->post('/api/v1/sponsor/organization/members/import-csv', [
            'file' => UploadedFile::fake()->createWithContent('roster.csv', "name,phone\nRina Satu,081211110001\n"),
        ], ['Accept' => 'application/json'])->assertStatus(403);
        $this->member->update(['status' => 'REVOKED']);
        $this->postJson("/api/v1/sponsor/organization/members/{$this->member->id}/reactivate")->assertStatus(403);

        $this->assertEquals(97, (float) $this->balance->fresh()->remaining_quota);
        $this->assertSame(1, SponsorMemberVoucher::count());
        $this->assertEquals(3, (float) $voucher->fresh()->hours_granted);
        $this->assertSame('REVOKED', $this->member->fresh()->status);
        $this->assertFalse(User::where('phone', '081399887766')->exists());
        $this->assertFalse(User::where('phone', '081211110001')->exists());

        $this->page()
            ->assertSee('data-pic-restricted', false)
            ->assertSee('Beberapa aksi di halaman ini tidak diaktifkan untuk akun Anda.')
            ->assertDontSee('data-pic-add-members', false)->assertDontSee('data-pic-bulk', false)
            ->assertDontSee('data-pic-give-hours', false)->assertDontSee('data-pic-edit-voucher', false)
            ->assertDontSee("reactivateMember('{$this->member->id}')", false);
    }

    public function test_members_can_be_added_without_hours_when_only_giving_hours_is_disabled(): void
    {
        $this->actingAs($this->pic);
        $this->restrict([SponsorOrganization::PIC_RELEASE_SINGLE]);

        $this->postJson('/api/v1/sponsor/organization/members', ['name' => 'Siti Rahma', 'phone' => '081399887766', 'initial_hours' => 2])->assertStatus(403);
        $this->assertFalse(User::where('phone', '081399887766')->exists());
        $this->postJson('/api/v1/sponsor/organization/members', ['name' => 'Siti Rahma', 'phone' => '081399887766'])->assertStatus(201);

        $csv = "name,phone,hours\nRina Satu,081211110001,2\nDodi Dua,081211110002,\n";
        $data = $this->post('/api/v1/sponsor/organization/members/import-csv', [
            'file' => UploadedFile::fake()->createWithContent('roster.csv', $csv),
        ])->assertOk()->json('data');
        $this->assertSame(1, $data['imported']);
        $this->assertSame(0, $data['vouchers_issued']);
        $this->assertSame(2, $data['failed_rows'][0]['row']);
        $this->assertStringContainsString('tidak diizinkan memberikan jam', $data['failed_rows'][0]['reason']);
        $this->assertFalse(User::where('phone', '081211110001')->exists(), 'baris berjam ditolak utuh');

        $this->assertSame(0, SponsorMemberVoucher::count());
        $this->assertEquals(100, (float) $this->balance->fresh()->remaining_quota);

        $this->postJson('/api/v1/sponsor/organization/members/bulk-release-voucher', ['mode' => 'uniform', 'hours' => 1])->assertOk();
        $this->page()
            ->assertSee('data-pic-add-members', false)->assertDontSee('initial_hours_input', false)
            ->assertDontSee('data-pic-give-hours', false)->assertSee('data-pic-bulk', false);
    }

    public function test_staff_set_access_rights_from_the_kelola_sponsor_edit_form(): void
    {
        Club61PermissionMatrix::syncAllPermissions('web');
        $this->actingAs(User::factory()->admin()->create());
        $all = array_keys(SponsorOrganization::PIC_ACTIONS);

        Livewire::test(EditSponsorOrganization::class, ['record' => $this->org->getRouteKey()])
            ->assertFormSet(['pic_restricted_actions' => $all])
            ->assertSee('Hak Akses PIC')
            ->fillForm(['pic_restricted_actions' => [SponsorOrganization::PIC_MANAGE_MEMBERS, SponsorOrganization::PIC_RELEASE_SINGLE]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([SponsorOrganization::PIC_RELEASE_BULK, SponsorOrganization::PIC_EDIT_VOUCHERS], $this->org->fresh()->pic_restricted_actions);
        $this->assertFalse($this->org->fresh()->picAllows(SponsorOrganization::PIC_RELEASE_BULK));
        $this->assertSame('WARNING', ActivityLog::where('subject_id', $this->org->id)->latest('id')->first()->severity);

        Livewire::test(EditSponsorOrganization::class, ['record' => $this->org->getRouteKey()])
            ->assertFormSet(['pic_restricted_actions' => [SponsorOrganization::PIC_MANAGE_MEMBERS, SponsorOrganization::PIC_RELEASE_SINGLE]])
            ->fillForm(['pic_restricted_actions' => $all])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($this->org->fresh()->pic_restricted_actions);
    }
}
