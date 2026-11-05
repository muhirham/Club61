<?php

namespace Tests\Feature\Sponsor;

use App\Filament\Pages\BookingSystem;
use App\Filament\Pages\KelolaPemesanan;
use App\Models\Membership\MembershipPlan;
use App\Models\Membership\UserMembership;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Sponsor\SponsorMemberVoucher;
use App\Models\Sponsor\SponsorOrganization;
use App\Models\Sponsor\SponsorOrganizationMember;
use App\Models\User;
use App\Services\Padel\PadelBookingService;
use App\Services\Permission\Club61PermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Booking yang dibayar jam voucher sponsor ditandai di hasil check-in (nama perusahaan + pengingat cek ID card
 * karyawan). Booking biasa tidak berubah. Logika check-in sendiri tidak berubah.
 */
class SponsorCheckInBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected PadelCourt $court;

    protected function setUp(): void
    {
        parent::setUp();
        Club61PermissionMatrix::syncAllPermissions('web');
        $this->actingAs(User::factory()->admin()->create());
        $this->court = PadelCourt::create(['name' => 'Court Gate', 'type' => 'INDOOR', 'hourly_rate_regular' => 200000, 'hourly_rate_prime' => 300000, 'is_active' => true]);
    }

    private function booking(string $code, array $extra = []): PadelBooking
    {
        $start = now()->addMinutes(10)->startOfMinute();

        return PadelBooking::create($extra + [
            'booking_code' => $code, 'user_id' => User::factory()->customer()->create(['name' => 'Budi Karyawan'])->id,
            'court_id' => $this->court->id, 'booking_date' => $start->format('Y-m-d'), 'start_time' => $start,
            'end_time' => $start->copy()->addHour(), 'court_fee' => 200000, 'total_amount' => 0, 'status' => 'PAID',
            'qr_code_hash' => 'hash-'.$code,
        ]);
    }

    private function sponsorBooking(string $code, float $hoursConsumed = 1): PadelBooking
    {
        $pic = User::factory()->create();
        $plan = MembershipPlan::create(['code' => 'MBR-CORP-'.uniqid(), 'name' => 'IM3 Platinum', 'ownership_type' => 'ORGANIZATIONAL', 'duration_days' => 365, 'price' => 1, 'is_active' => true]);
        $membership = UserMembership::create(['membership_code' => 'MBR-CORP-'.uniqid(), 'owner_type' => 'ORGANIZATIONAL', 'user_id' => $pic->id, 'plan_id' => $plan->id, 'status' => 'ACTIVE', 'start_date' => now(), 'end_date' => now()->addYear()]);
        $org = SponsorOrganization::create(['name' => 'Indosat Im3 Platinum', 'user_membership_id' => $membership->id, 'sponsor_admin_user_id' => $pic->id]);
        $member = SponsorOrganizationMember::create(['sponsor_organization_id' => $org->id, 'user_id' => $pic->id, 'status' => 'ACTIVE']);
        $voucher = SponsorMemberVoucher::create(['sponsor_organization_member_id' => $member->id, 'hours_granted' => 5, 'hours_used' => 1, 'issued_at' => now(), 'expires_at' => now()->addMonth()]);

        return $this->booking($code, [
            'sponsor_organization_id' => $org->id, 'sponsor_member_voucher_id' => $voucher->id,
            'sponsor_discount_court' => 200000 * $hoursConsumed, 'sponsor_hours_consumed' => $hoursConsumed,
        ]);
    }

    public function test_check_in_result_carries_the_sponsor_only_for_voucher_bookings(): void
    {
        $sponsor = $this->sponsorBooking('BK-SPN-001');
        $regular = $this->booking('BK-REG-001');
        $dropped = $this->sponsorBooking('BK-SPN-002', 0);
        $service = app(PadelBookingService::class);

        $result = $service->checkIn('hash-BK-SPN-001', auth()->user());
        $this->assertSame(['organization' => 'Indosat Im3 Platinum'], $result['sponsor']);
        $this->assertSame('CHECKED_IN', $sponsor->fresh()->status, 'logika check-in tidak berubah');
        $this->assertSame(['organization' => 'Indosat Im3 Platinum'], $service->checkIn('BK-SPN-001', auth()->user())['sponsor'], 'scan ulang juga');

        $this->assertNull($service->checkIn('hash-BK-REG-001', auth()->user())['sponsor']);
        $this->assertNull($service->checkIn('hash-BK-SPN-002', auth()->user())['sponsor'], 'benefit sponsor gugur → bukan voucher');
        $this->assertSame('CHECKED_IN', $regular->fresh()->status);
        $this->assertSame('CHECKED_IN', $dropped->fresh()->status);
    }

    public function test_gate_screens_show_the_badge_for_sponsor_bookings_only(): void
    {
        $this->sponsorBooking('BK-SPN-010');
        $this->booking('BK-REG-010');

        Livewire::test(KelolaPemesanan::class)
            ->call('openCheckInModal')->set('checkInQuery', 'hash-BK-SPN-010')->call('executeCheckIn')
            ->assertSee('Voucher Sponsor: Indosat Im3 Platinum')
            ->assertSee('Cocokkan nama pemain dengan ID card karyawan');

        Livewire::test(BookingSystem::class)
            ->call('openCheckInModal')->set('checkInQuery', 'BK-SPN-010')->call('executeCheckIn')
            ->assertSee('Voucher Sponsor: Indosat Im3 Platinum');

        Livewire::test(KelolaPemesanan::class)
            ->call('openCheckInModal')->set('checkInQuery', 'hash-BK-REG-010')->call('executeCheckIn')
            ->assertDontSee('Voucher Sponsor:');
    }
}
