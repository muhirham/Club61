<?php

namespace Tests\Feature\Coaching;

use App\Filament\Pages\Analytics;
use App\Filament\Pages\BookingSystem;
use App\Filament\Pages\JadwalCoaching;
use App\Filament\Resources\Coaching\CoachResource;
use App\Filament\Resources\Coaching\Pages\ManageCoaches;
use App\Filament\Resources\Marketing\LinkBioLinkResource;
use App\Filament\Resources\Marketing\Pages\ManageLinkBioLinks;
use App\Models\Coaching\Coach;
use App\Models\Coaching\CoachingAcademy;
use App\Models\Coaching\CoachingSetting;
use App\Models\Marketing\LinkBioLink;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Pos\PosCashierShift;
use App\Models\User;
use App\Services\Coaching\CoachingPricingService;
use App\Services\Padel\PadelBookingService;
use App\Services\Permission\Club61PermissionMatrix;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Modul 24 Opsi B Tahap 2 — info coaching di Monitoring Lapangan, profil coach (web & API), link bio, rekap Analytics.
 */
class CoachingTahap2Test extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private PadelCourt $court;

    private Coach $maimun;

    private Coach $nalda;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();
        Club61PermissionMatrix::syncAllPermissions('web');
        app(CoachingPricingService::class)->flush();

        $this->owner = User::factory()->superAdmin()->create();
        $this->court = PadelCourt::create(['name' => 'Court A', 'type' => 'INDOOR', 'hourly_rate_regular' => 300000, 'hourly_rate_prime' => 400000, 'is_active' => true]);
        $academy = CoachingAcademy::where('name', 'SR Academy')->firstOrFail();
        $this->maimun = Coach::create(['academy_id' => $academy->id, 'name' => 'Maimun', 'title' => 'HEAD_COACH', 'phone' => '081234500001', 'email' => 'maimun@rahasia.test', 'notes' => 'catatan internal', 'is_active' => true]);
        $this->nalda = Coach::create(['academy_id' => $academy->id, 'name' => 'Nalda', 'title' => 'COACH', 'phone' => '081234500002', 'is_active' => true]);
        $this->date = Carbon::parse('next Tuesday')->format('Y-m-d');

        PosCashierShift::create([
            'shift_number' => PosCashierShift::generateShiftNumber('PADEL_FRONTDESK'), 'counter' => 'PADEL_FRONTDESK', 'status' => 'OPEN',
            'opened_by_id' => $this->owner->id, 'opened_at' => now(), 'starting_cash' => 0, 'expected_cash' => 0,
        ]);
        $this->actingAs($this->owner);
    }

    private function coachingCheckout(string $hour, array $coaching)
    {
        $service = app(PadelBookingService::class);

        return $service->processWalkInCheckout(
            customer: $service->findOrCreateWalkInCustomer(name: 'Norfita', phone: '081299887700'),
            slots: [['court_id' => $this->court->id, 'start_time' => "{$hour}:00", 'end_time' => sprintf('%02d:00', (int) $hour + 2)]],
            bookingDate: $this->date, equipments: [], paymentMethod: 'EDC_BCA', cashier: $this->owner,
            paymentMeta: ['terminal' => 'EDC_BCA', 'card_last_4' => '1234', 'approval_code' => 'A'.$hour, 'trace_number' => 'T'.$hour],
            coaching: $coaching,
        );
    }

    // ── Monitoring Lapangan ─────────────────────────────────────────────────────────────────────────────────────

    public function test_court_timetable_shows_the_coach_and_the_detail_popup_lists_the_session(): void
    {
        $this->coachingCheckout('08', ['head_coach_id' => $this->maimun->id, 'extra_coach_ids' => [$this->nalda->id], 'students' => ['Norfita'], 'notes' => 'Ballboy 1']);
        $booking = PadelBooking::whereNotNull('coaching_booking_id')->sole();

        $page = Livewire::test(BookingSystem::class)
            ->set('selectedDate', $this->date)
            ->assertSee('Coach Maimun +1')
            ->call('inspectBooking', $booking->id)
            ->assertSee('Sesi Coaching · SR Academy')
            ->assertSee('Coach tambahan')
            ->assertSee('Norfita')
            ->assertSee('Ballboy 1');

        $coaching = $page->get('inspectData')['coaching'];
        $this->assertSame(['Maimun', 'Nalda'], array_column($coaching['coaches'], 'name'));
        $this->assertStringStartsWith('https://wa.me/6281234500001?text=', $coaching['coaches'][0]['wa_url']);

        // Sel sempit: nama depan saja, awalan "Coach" di nama tidak dobel.
        $this->maimun->update(['name' => 'Coach Maimun Hasibuan']);
        $this->assertSame('Maimun +1', $booking->coachingBooking()->first()->coachSummary());

        // Booking reguler tidak punya bagian coaching.
        $regular = PadelBooking::create([
            'booking_code' => 'BK-REG-1', 'user_id' => $this->owner->id, 'court_id' => $this->court->id, 'booking_date' => $this->date,
            'start_time' => "{$this->date} 12:00:00", 'end_time' => "{$this->date} 13:00:00", 'court_fee' => 300000, 'total_amount' => 300000, 'status' => 'PAID',
        ]);
        $page->call('inspectBooking', $regular->id);
        $this->assertNull($page->get('inspectData')['coaching']);
    }

    public function test_schedule_shows_todays_sessions_when_first_opened_without_touching_the_filter(): void
    {
        $result = $this->coachingCheckout('15', ['head_coach_id' => $this->maimun->id]);
        $booking = PadelBooking::where('order_id', $result['order']->id)->sole();
        $this->travelTo(Carbon::parse($this->date.' 14:50:25', 'Asia/Jakarta'));

        // Default DatePicker membawa jam ("… 14:50:25") — dulu DATE(booking_date) = '… 14:50:25' tidak pernah cocok.
        Livewire::test(JadwalCoaching::class)
            ->assertCanSeeTableRecords([$booking])
            ->assertSee('Norfita');

        $this->assertSame('2026-10-23', JadwalCoaching::filterDate('2026-10-23 14:50:25'));
        $this->assertSame($this->date, JadwalCoaching::filterDate('bukan-tanggal'), 'nilai aneh → hari ini');
        $this->assertSame($this->date, JadwalCoaching::filterDate('2026-02-31'));
    }

    public function test_clearing_the_schedule_date_shows_all_sessions(): void
    {
        $result = $this->coachingCheckout('15', ['head_coach_id' => $this->maimun->id]);
        $booking = PadelBooking::where('order_id', $result['order']->id)->sole();
        $this->travelTo(Carbon::parse($this->date, 'Asia/Jakarta')->addDays(3)->setTime(9, 0));

        // Hari lain: sesi tidak tampil; tanggal dikosongkan (× pada label filter) → label hilang & semua sesi tampil.
        Livewire::test(JadwalCoaching::class)
            ->assertCanNotSeeTableRecords([$booking])
            ->filterTable('tanggal', ['date' => null])
            ->assertCanSeeTableRecords([$booking])
            ->assertDontSee('Tanggal: ');
    }

    // ── Profil coach (web & API)───────────────────────────────────────────────────────────────────────────────

    public function test_customer_sees_only_published_coaches_without_private_contact(): void
    {
        CoachingSetting::query()->update(['admin_whatsapp' => '081277776666']);
        $this->maimun->update(['show_on_profile' => true, 'motto' => 'Main cerdas, bukan cuma keras.', 'highlights' => ['Sertifikasi pelatih padel', '  ', 'Mantan atlet nasional']]);
        $this->nalda->update(['show_on_profile' => true, 'is_active' => false]); // nonaktif → tidak tampil
        Coach::create(['name' => 'Belum Siap', 'title' => 'COACH', 'is_active' => true]); // show_on_profile default false

        $customer = User::factory()->customer()->create();
        $this->actingAs($customer)->get(route('customer.coaching'))
            ->assertOk()
            ->assertSee('Maimun')
            ->assertSee('Sertifikasi pelatih padel')
            ->assertSee('Mantan atlet nasional')
            ->assertSee('Main cerdas, bukan cuma keras.')
            ->assertSee('https://wa.me/6281277776666?text=', false)
            ->assertDontSee('Nalda')
            ->assertDontSee('Belum Siap')
            ->assertDontSee('081234500001')
            ->assertDontSee('maimun@rahasia.test')
            ->assertDontSee('catatan internal');

        $json = $this->getJson('/api/v1/coaching/coaches')->assertOk()->json('data');
        $this->assertSame(['Maimun'], array_column($json['coaches'], 'name'));
        $this->assertSame(['Sertifikasi pelatih padel', 'Mantan atlet nasional'], $json['coaches'][0]['highlights']);
        $this->assertSame('Padel', $json['coaches'][0]['sport']);
        $this->assertSame('Main cerdas, bukan cuma keras.', $json['coaches'][0]['motto']);
        $this->assertStringStartsWith('https://wa.me/6281277776666?text=', $json['admin_whatsapp_url']);
        $this->assertStringContainsString(rawurlencode('Coach Maimun'), $json['coaches'][0]['whatsapp_url']);
        foreach (['phone', 'email', 'notes'] as $private) {
            $this->assertArrayNotHasKey($private, $json['coaches'][0]);
        }

        // Halaman coaching portal hanya untuk yang login.
        auth()->logout();
        $this->get(route('customer.coaching'))->assertRedirect(route('login'));
    }

    public function test_admin_uploads_a_coach_photo_through_the_secure_uploader_only(): void
    {
        Storage::fake('public');

        Livewire::test(ManageCoaches::class)
            ->callAction('create', [
                'name' => 'Coach Foto', 'title' => 'COACH', 'sort_order' => 1, 'is_active' => true,
                'sport' => 'Padel', 'show_on_profile' => true, 'highlights' => [['text' => 'Ramah anak']],
                'motto' => '  Main   cerdas

  bukan cuma keras 
baris 3
baris 4',
                'photo_path' => UploadedFile::fake()->image('coach.png', 600, 800),
            ])
            ->assertHasNoActionErrors();

        $coach = Coach::where('name', 'Coach Foto')->sole();
        $this->assertMatchesRegularExpression('#^coaches/[0-9a-f-]{36}\.jpg$#', $coach->photo_path, 'disimpan ulang sebagai JPEG oleh SecureImageUploader');
        Storage::disk('public')->assertExists($coach->photo_path);
        $this->assertSame(['Ramah anak'], $coach->highlightList());
        // Foto di tabel admin pakai host yang sedang dibuka (url()), bukan APP_URL.
        Livewire::test(ManageCoaches::class)->assertSeeHtml(url('/storage/'.$coach->photo_path));
        $this->get('http://127.0.0.1:8000'.parse_url(CoachResource::getUrl(), PHP_URL_PATH))
            ->assertOk()->assertSee('http://127.0.0.1:8000/storage/'.$coach->photo_path, false);
        // Motto boleh multi-baris: spasi / baris kosong dirapikan, maks. 3 baris.
        $this->assertSame('Main cerdas
bukan cuma keras
baris 3', $coach->motto);
        $this->assertNull(Coach::cleanMotto('  
 '));

        // Ganti foto lewat form edit → file lama langsung dihapus, file baru tersimpan.
        $first = $coach->photo_path;
        Livewire::test(ManageCoaches::class)
            ->callTableAction('edit', $coach, ['photo_path' => [UploadedFile::fake()->image('baru.png', 500, 700)]])
            ->assertHasNoTableActionErrors();
        $coach->refresh();
        $this->assertNotSame($first, $coach->photo_path);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($coach->photo_path);

        // Path kiriman browser yang bukan hasil upload ditolak.
        try {
            $coach->update(['photo_path' => 'coaches/../../.env']);
            $this->fail('path foto bebas harus ditolak');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // Foto dihapus → file lama ikut dibuang.
        $old = $coach->refresh()->photo_path;
        $this->assertMatchesRegularExpression('#^coaches/[0-9a-f-]{36}\.jpg$#', $old, 'path lama tetap tersimpan');
        $coach->update(['photo_path' => null]);
        Storage::disk('public')->assertMissing($old);
    }

    // ── Link bio ───────────────────────────────────────────────────────────────────────────────────────────────

    public function test_link_bio_page_shows_only_buttons_with_a_destination(): void
    {
        auth()->logout();

        // Default: WA admin coaching belum diisi → tombolnya belum tampil; pricelist nonaktif sampai gambarnya diunggah.
        $this->get(route('link-bio'))->assertOk()
            ->assertSee('Book Court')
            ->assertSee('Lokasi')
            ->assertSee('https://www.google.com/maps/search/?api=1&amp;query=', false)
            ->assertDontSee('Admin Coaching')
            ->assertDontSee('Pricelist');

        CoachingSetting::query()->update(['admin_whatsapp' => '081277776666']);
        $this->get(route('link-bio'))->assertSee('Admin Coaching')->assertSee('https://wa.me/6281277776666?text=', false);

        // Link tidak aman yang terlanjur tersimpan (mis. lewat DB) tetap tidak dirender.
        LinkBioLink::create(['label' => 'Jahat', 'type' => 'URL', 'url' => 'javascript:alert(1)', 'is_active' => true, 'sort_order' => 99]);
        $this->get(route('link-bio'))->assertDontSee('Jahat')->assertDontSee('javascript:alert(1)', false);
    }

    public function test_admin_manages_link_bio_buttons_and_unsafe_links_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $this->assertTrue($admin->can('manage_link_bio'));
        $this->actingAs($admin);

        Livewire::test(ManageLinkBioLinks::class)
            ->callAction('create', ['label' => 'Jahat', 'type' => 'URL', 'url' => 'javascript:alert(1)', 'sort_order' => 1, 'is_active' => true])
            ->assertHasActionErrors(['url']);

        Livewire::test(ManageLinkBioLinks::class)
            ->callAction('create', ['label' => 'Turnamen', 'type' => 'URL', 'url' => 'https://club61.id/turnamen', 'sort_order' => 1, 'is_active' => true])
            ->assertHasNoActionErrors();
        $this->get(route('link-bio'))->assertSee('Turnamen')->assertSee('https://club61.id/turnamen', false);

        // Gambar pricelist: path bebas ditolak.
        $pricelist = LinkBioLink::where('type', 'IMAGE')->sole();
        try {
            $pricelist->update(['image_path' => '../config/app.php']);
            $this->fail('path gambar bebas harus ditolak');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->actingAs(User::factory()->cashier()->create(['is_active' => true]));
        $this->get(LinkBioLinkResource::getUrl())->assertDenied();
    }

    // ── Analytics ──────────────────────────────────────────────────────────────────────────────────────────────

    public function test_analytics_recaps_coaching_per_academy_and_coach(): void
    {
        $this->coachingCheckout('08', ['head_coach_id' => $this->maimun->id, 'extra_coach_ids' => [$this->nalda->id]]);
        $this->coachingCheckout('18', ['head_coach_id' => $this->nalda->id]);

        $recap = (fn () => $this->coachingRecap(null, null))->call(Livewire::test(Analytics::class)->instance());

        $this->assertSame('SR Academy', $recap['academies'][0]['name']);
        $this->assertSame(2, $recap['academies'][0]['sessions']);
        $this->assertEquals(4.0, $recap['academies'][0]['hours']);
        // Tarif awal: 08–10 Rp100rb/jam, 18–20 Rp150rb/jam.
        $this->assertEquals(500000.0, $recap['academies'][0]['value']);

        $coaches = collect($recap['coaches'])->keyBy('name');
        $this->assertSame(['head' => 1, 'extra' => 1], ['head' => $coaches['Nalda']['head'], 'extra' => $coaches['Nalda']['extra']]);
        $this->assertEquals(4.0, $coaches['Nalda']['hours']);
        $this->assertSame(1, $coaches['Maimun']['head']);
        $this->assertEquals(2.0, $coaches['Maimun']['hours']);

        Livewire::test(Analytics::class)->call('setPreset', 'semua')
            ->assertSee('Rekap Coaching per Academy')
            ->assertSee('Rekap Coaching per Coach')
            ->assertSee('1 sesi utama · 1 sesi tambahan');
    }
}
