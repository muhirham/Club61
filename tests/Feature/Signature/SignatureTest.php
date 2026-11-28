<?php

namespace Tests\Feature\Signature;

use App\Filament\Pages\Signature;
use App\Models\Padel\PadelBooking;
use App\Models\Padel\PadelCourt;
use App\Models\Signature\SignatureContent;
use App\Models\Signature\SignatureRunningText;
use App\Models\Signature\SignatureScreen;
use App\Models\Signature\SignatureSponsor;
use App\Models\Signature\SignatureTemplate;
use App\Models\Signature\SignatureVersion;
use App\Models\Sponsor\SponsorOrganization;
use App\Models\User;
use App\Services\Permission\Club61PermissionMatrix;
use App\Services\Signature\SignatureService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Modul 26 — Signature: layar TV (konten bergantian + monitoring lapangan), draft → preview → tayangkan. */
class SignatureTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected PadelCourt $court;

    protected function setUp(): void
    {
        parent::setUp();
        Club61PermissionMatrix::syncAllPermissions('web');
        Carbon::setTestNow(Carbon::parse('2026-10-12 19:20:00', 'Asia/Jakarta')->setTimezone(config('app.timezone')));
        Storage::fake('public');

        $this->admin = User::factory()->superAdmin()->create(['name' => 'Owner']);
        $this->court = PadelCourt::create(['name' => 'Court BCA', 'type' => 'INDOOR', 'hourly_rate_regular' => 200000, 'hourly_rate_prime' => 300000, 'is_active' => true, 'open_time' => '06:00', 'close_time' => '23:00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function service(): SignatureService
    {
        return app(SignatureService::class);
    }

    protected function booking(string $start, string $end, string $status, array $extra = [], ?User $user = null): PadelBooking
    {
        $user ??= User::factory()->customer()->create(['name' => 'Budi Santoso', 'phone' => '081299887766', 'email' => 'budi.rahasia@example.com']);

        return PadelBooking::create(array_merge([
            'booking_code' => 'BK-'.strtoupper(\Illuminate\Support\Str::random(6)),
            'user_id' => $user->id,
            'court_id' => $this->court->id,
            'booking_date' => '2026-10-12',
            'start_time' => Carbon::parse("2026-10-12 {$start}:00"),
            'end_time' => Carbon::parse("2026-10-12 {$end}:00"),
            'court_fee' => 200000,
            'total_amount' => 200000,
            'status' => $status,
        ], $extra));
    }

    public function test_page_creates_defaults_and_follows_permissions(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(Signature::class)->assertOk()->assertSee('Digital Signage');

        $this->assertSame(1, SignatureScreen::count());
        $this->assertSame(['PRESET_A', 'PRESET_B', 'PRESET_C'], SignatureTemplate::orderBy('layout')->pluck('layout')->all());
        $this->assertSame(['CLOCK', 'MONITORING', 'SCHEDULE', 'SPONSOR'], SignatureContent::orderBy('type')->pluck('type')->all());
        $this->assertSame(4, SignatureRunningText::where('kind', '!=', 'MANUAL')->count());
        $this->get('/admin/signature')->assertOk();

        // Dipanggil lagi tidak menggandakan data.
        $this->service()->ensureDefaults();
        $this->assertSame(1, SignatureScreen::count());
        $this->assertSame(3, SignatureTemplate::count());

        $this->actingAs(User::factory()->admin()->create());
        $this->assertTrue(Signature::canAccess());

        $this->actingAs(User::factory()->cashier()->create());
        $this->assertFalse(Signature::canAccess());
    }

    public function test_tv_only_shows_what_was_published(): void
    {
        $screen = $this->service()->ensureDefaults();

        $this->get('/signature/tokensalah0000000000000000000000000')->assertNotFound();
        $this->get("/signature/{$screen->token}")->assertOk()->assertSee(route('signature.live', $screen->token), false);
        $this->assertSame('null', $this->getJson("/signature/{$screen->token}/payload")->assertOk()->getContent(), 'belum pernah ditayangkan');

        $this->actingAs($this->admin);
        Livewire::test(Signature::class)->call('publish');
        $this->assertSame(1, $this->getJson("/signature/{$screen->token}/payload")->json('version'));

        // Ubah draft: TV tetap menampilkan versi 1 sampai Tayangkan lagi.
        SignatureTemplate::find($screen->template_id)->update(['name' => 'Template Draft Baru']);
        $this->assertNotSame('Template Draft Baru', $this->getJson("/signature/{$screen->token}/payload")->json('template.name'));

        // Template yang sama → versi 1 diperbarui; TV memuat ulang karena revisinya naik.
        Livewire::test(Signature::class)->call('publish');
        $this->assertSame('Template Draft Baru', $this->getJson("/signature/{$screen->token}/payload")->json('template.name'));
        $live = $this->getJson("/signature/{$screen->token}/live")->json();
        $this->assertSame([1, 2], [$live['version'], $live['revision']]);
    }

    public function test_live_monitoring_shows_names_per_setting_and_never_contact_details(): void
    {
        $screen = $this->service()->ensureDefaults();
        $this->booking('19:00', '21:00', 'CHECKED_IN', ['checked_in_at' => now()->subMinutes(1)]);
        $this->booking('21:00', '22:00', 'PAID', [], User::factory()->customer()->create(['name' => 'Rina Kartika']));
        $this->service()->publish($screen, $this->admin);

        $response = $this->getJson("/signature/{$screen->token}/live")->assertOk();
        $court = $response->json('courts.0');
        $this->assertSame('PLAYING', $court['status']);
        $this->assertSame('Budi Santoso', $court['current']['name']);
        $this->assertSame(['19:00', '21:00'], [$court['current']['start'], $court['current']['end']]);
        $this->assertSame(100 * 60, $court['current']['remaining_seconds']);
        $this->assertSame(['21:00', 'Rina Kartika'], [$court['next']['start'], $court['next']['name']]);
        $this->assertStringNotContainsString('081299887766', $response->getContent());
        $this->assertStringNotContainsString('budi.rahasia@example.com', $response->getContent());

        // Notifikasi check-in & running text otomatis (slot kosong hari ini: 22:00).
        $this->assertSame('Selamat bermain Budi Santoso di Court BCA!', collect($response->json('events'))->firstWhere('type', 'CHECKIN')['text']);
        $this->assertTrue(collect($response->json('ticker'))->contains(fn ($t) => str_contains($t, 'Court BCA 22:00')));
        $this->assertNotNull($screen->fresh()->last_seen_at, 'TV melapor online');

        // Jadwal hari ini: blok booking bernama untuk timeline + posisi garis "sekarang".
        $this->assertSame(19 * 60 + 20, $response->json('now_minutes'));
        $this->assertSame(
            [['Budi Santoso', 19 * 60, 21 * 60, 'PLAYING'], ['Rina Kartika', 21 * 60, 22 * 60, 'UPCOMING']],
            collect($response->json('schedule.0.bookings'))->map(fn ($b) => [$b['name'], $b['start_minutes'], $b['end_minutes'], $b['status']])->all()
        );
        $this->assertSame([6 * 60, 23 * 60], [$response->json('schedule.0.open_minutes'), $response->json('schedule.0.close_minutes')]);

        $screen->update(['name_display' => 'INITIALS']);
        $this->service()->publish($screen, $this->admin);
        $this->assertSame('B. S.', $this->getJson("/signature/{$screen->token}/live")->json('courts.0.current.name'));
        $this->assertSame('R. K.', $this->getJson("/signature/{$screen->token}/live")->json('schedule.0.bookings.1.name'));

        $screen->update(['name_display' => 'BOOKED']);
        $this->service()->publish($screen, $this->admin);
        $this->assertNull($this->getJson("/signature/{$screen->token}/live")->json('courts.0.current.name'));
    }

    public function test_coaching_tab_session_shows_on_tv_but_unpaid_online_booking_does_not(): void
    {
        $screen = $this->service()->ensureDefaults();
        $coach = \App\Models\Coaching\Coach::create(['name' => 'Maimun', 'title' => 'HEAD_COACH', 'is_active' => true]);
        $tab = \App\Models\Coaching\CoachingBooking::create([
            'head_coach_id' => $coach->id, 'academy_name' => 'Akademi Padel', 'billing' => \App\Models\Coaching\CoachingAcademy::BILLING_TAB,
        ]);
        $payNow = \App\Models\Coaching\CoachingBooking::create([
            'head_coach_id' => $coach->id, 'academy_name' => 'Akademi Lain', 'billing' => \App\Models\Coaching\CoachingAcademy::BILLING_PER_BOOKING,
        ]);

        // Sesi coaching Tagihan Berkala sedang berjalan (belum dilunasi akademi = PENDING_PAYMENT) → lapangan terpakai.
        $this->booking('19:00', '21:00', 'PENDING_PAYMENT', ['coaching_booking_id' => $tab->id], User::factory()->customer()->create(['name' => 'Zuelfan']));
        // Booking online yang belum dibayar & sesi coaching bukan tagihan berkala yang belum dibayar → tidak tampil.
        $this->booking('21:00', '22:00', 'PENDING_PAYMENT', [], User::factory()->customer()->create(['name' => 'Belum Bayar']));
        $this->booking('22:00', '23:00', 'PENDING_PAYMENT', ['coaching_booking_id' => $payNow->id], User::factory()->customer()->create(['name' => 'Coaching Belum Bayar']));
        $this->service()->publish($screen, $this->admin);

        $response = $this->getJson("/signature/{$screen->token}/live")->assertOk();
        $court = $response->json('courts.0');
        $this->assertSame('WAITING', $court['status']);
        $this->assertSame(['Zuelfan', '19:00', '21:00'], [$court['current']['name'], $court['current']['start'], $court['current']['end']]);
        $this->assertNull($court['next']);
        $this->assertSame(['Zuelfan'], collect($response->json('schedule.0.bookings'))->pluck('name')->all());
        $this->assertStringNotContainsString('Belum Bayar', $response->getContent());
        // Slot sesi coaching tidak diiklankan sebagai slot kosong di teks berjalan.
        $this->assertFalse(collect($response->json('ticker'))->contains(fn ($t) => str_contains($t, 'Court BCA 20:00')));
    }

    public function test_tv_closes_outside_operating_hours_when_enabled(): void
    {
        $screen = $this->service()->ensureDefaults();
        $this->service()->publish($screen, $this->admin);
        Carbon::setTestNow(Carbon::parse('2026-10-12 23:30:00', 'Asia/Jakarta')->setTimezone(config('app.timezone')));
        $this->assertFalse($this->getJson("/signature/{$screen->token}/live")->json('is_open'));

        $screen->update(['operating_hours_only' => false]);
        $this->service()->publish($screen, $this->admin);
        $this->assertTrue($this->getJson("/signature/{$screen->token}/live")->json('is_open'));
    }

    public function test_preview_shows_unsaved_template_changes_and_needs_permission(): void
    {
        $this->actingAs($this->admin);
        $screen = $this->service()->ensureDefaults();
        $page = Livewire::test(Signature::class)
            ->call('openEditTemplate', $screen->template_id)
            ->set('tplName', 'Belum Disimpan')
            ->set('tplTheme.background', '#112233')
            ->call('previewTemplate')
            ->assertSet('showTemplateModal', true);

        $url = $page->get('previewUrl');
        $this->assertNotNull($url);
        $this->get($url)->assertOk()->assertSee('Belum Disimpan')->assertSee('#112233')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->getJson($url.'/live')->assertOk()->assertJsonStructure(['courts', 'schedule', 'ticker', 'is_open']);
        $this->assertNotSame('Belum Disimpan', SignatureTemplate::find($screen->template_id)->name, 'preview tidak menyimpan');
        $this->assertSame(0, SignatureVersion::count(), 'preview tidak menayangkan');

        $this->actingAs(User::factory()->cashier()->create());
        $this->get($url)->assertForbidden();
        auth()->logout();
        $this->get($url)->assertRedirect('/login');
    }

    public function test_template_playlist_and_contents_are_managed_from_the_page(): void
    {
        $this->actingAs($this->admin);
        $screen = $this->service()->ensureDefaults();
        $clock = SignatureContent::where('type', 'CLOCK')->first();

        $page = Livewire::test(Signature::class)
            ->call('openCreateContent')
            ->set('contentName', 'Promo Oktober')
            ->set('contentType', 'IMAGE')
            ->set('contentUpload', UploadedFile::fake()->image('promo.jpg', 1920, 1080))
            ->set('contentDuration', 8)
            ->call('saveContent')
            ->assertHasNoErrors();
        $image = SignatureContent::where('name', 'Promo Oktober')->sole();
        Storage::disk('public')->assertExists($image->file_path);

        $mp4 = "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 64);
        $page->call('openCreateContent')
            ->set('contentName', 'Video Promo')
            ->set('contentType', 'VIDEO')
            ->set('contentUpload', UploadedFile::fake()->createWithContent('promo.mp4', $mp4))
            ->set('contentSound', true)
            ->set('contentVolume', 60)
            ->call('saveContent')
            ->assertHasNoErrors();
        $video = SignatureContent::where('name', 'Video Promo')->sole();
        $this->assertTrue($video->sound);

        $page->call('openCreateContent')->set('contentName', 'Bukan Video')->set('contentType', 'VIDEO')
            ->set('contentUpload', UploadedFile::fake()->create('palsu.mp4', 10, 'text/plain'))
            ->call('saveContent')->assertHasErrors('contentUpload');

        // Video di atas 100 MB ditolak (storage server tidak cepat penuh).
        $this->assertSame(100, Signature::VIDEO_MAX_MB);
        $this->assertLessThanOrEqual(Signature::VIDEO_MAX_MB, $page->instance()->videoMaxMb);
        $page->call('openCreateContent')->set('contentName', 'Video Kebesaran')->set('contentType', 'VIDEO')
            ->set('contentUpload', UploadedFile::fake()->create('besar.mp4', 101 * 1024, 'video/mp4'))
            ->assertHasErrors('contentUpload');
        $this->assertFalse(SignatureContent::where('name', 'Video Kebesaran')->exists());

        // Playlist: gambar 8 dtk → video → jam 20 dtk.
        $page->call('openCreateTemplate')
            ->set('tplName', 'Promo Malam')
            ->set('tplLayout', 'PRESET_C')
            ->set('tplAddContentIds.main', $image->id)->call('addPlaylistItem')
            ->set('tplAddContentIds.main', $clock->id)->call('addPlaylistItem')
            ->set('tplAddContentIds.main', $video->id)->call('addPlaylistItem')
            ->call('movePlaylistItem', 2, -1)
            ->set('tplPlaylists.main.2.duration', 20)
            ->set('tplTicker.speed', 200)
            ->call('saveTemplate')
            ->assertHasNoErrors();
        $template = SignatureTemplate::where('name', 'Promo Malam')->sole();
        $this->assertSame([$image->id, $video->id, $clock->id], $template->playlistItems()->pluck('content_id')->all());

        $page->set('screenTemplateId', $template->id)->call('publish');
        $payload = $screen->fresh()->publishedVersion->payload;
        $this->assertSame('PRESET_C', $payload['template']['layout']);
        $this->assertSame(200, $payload['template']['ticker']['speed']);
        $this->assertSame([['IMAGE', 8], ['VIDEO', 15], ['CLOCK', 20]], array_map(fn ($i) => [$i['type'], $i['duration']], $payload['playlists']['main']));
        $this->assertTrue($payload['playlists']['main'][1]['sound']);

        // Template yang dipakai layar & widget sistem tidak bisa dihapus.
        $page->call('deleteTemplate', $template->id);
        $this->assertNotNull($template->fresh());
        $page->call('deleteContent', $clock->id);
        $this->assertNotNull($clock->fresh());

        // Hapus konten upload → item playlist ikut hilang. Filenya masih dipakai versi yang sedang tayang di TV, jadi
        // ditahan dulu (dihapus setelah 7 hari tidak tayang — SignatureFileJanitorTest).
        $page->call('deleteContent', $image->id);
        Storage::disk('public')->assertExists($image->file_path);
        $this->assertSame(2, $template->playlistItems()->count());
    }

    public function test_sponsors_from_brand_and_corporate_with_period(): void
    {
        $this->actingAs($this->admin);
        $screen = $this->service()->ensureDefaults();
        $pic = User::factory()->create();
        $plan = \App\Models\Membership\MembershipPlan::create(['code' => 'MBR-C', 'name' => 'Corp', 'ownership_type' => 'ORGANIZATIONAL', 'duration_days' => 30, 'price' => 1, 'is_active' => true]);
        $membership = \App\Models\Membership\UserMembership::create(['membership_code' => 'MBR-C-1', 'owner_type' => 'ORGANIZATIONAL', 'user_id' => $pic->id, 'plan_id' => $plan->id, 'status' => 'ACTIVE', 'start_date' => now(), 'end_date' => now()->addMonth()]);
        $org = SponsorOrganization::create(['name' => 'PT Indosat', 'user_membership_id' => $membership->id, 'sponsor_admin_user_id' => $pic->id]);

        Livewire::test(Signature::class)
            ->call('openCreateSponsor')->set('sponsorKind', 'BRAND')->set('sponsorName', 'Bank BCA')
            ->set('sponsorLogoUpload', UploadedFile::fake()->image('bca.png', 400, 200))->call('saveSponsor')->assertHasNoErrors()
            ->call('openCreateSponsor')->set('sponsorKind', 'CORPORATE')->set('sponsorOrgId', $org->id)->call('saveSponsor')->assertHasNoErrors()
            ->call('openCreateSponsor')->set('sponsorKind', 'BRAND')->set('sponsorName', 'Sponsor Lama')->set('sponsorEnds', '2026-10-01')->set('sponsorStarts', '2026-09-01')->call('saveSponsor')->assertHasNoErrors()
            ->call('openCreateSponsor')->set('sponsorKind', 'CORPORATE')->call('saveSponsor')->assertHasErrors(['sponsorOrgId' => 'required']);

        $this->assertSame(3, SignatureSponsor::count());
        $payload = $this->service()->buildPayload($screen);
        $this->assertSame(['Bank BCA', 'PT Indosat'], array_column($payload['sponsors'], 'name'), 'sponsor di luar periode tidak tampil');
        $this->assertNotNull($payload['sponsors'][0]['logo_url']);

        $this->service()->publish($screen, $this->admin);
        $this->assertContains('Didukung oleh Bank BCA, PT Indosat.', $this->getJson("/signature/{$screen->token}/live")->json('ticker'));
    }

    public function test_screen_link_is_short_enough_to_type_and_old_links_keep_working(): void
    {
        $screen = $this->service()->ensureDefaults();

        // Kode baru: 10 karakter, tanpa huruf/angka yang mirip (0/o, 1/l/i) supaya bisa diketik pakai remote.
        $this->assertMatchesRegularExpression('/^[abcdefghjkmnpqrstuvwxyz23456789]{10}$/', $screen->token);
        $this->get("/tv/{$screen->token}")->assertOk()->assertSee(route('signature.live', $screen->token), false);
        $this->actingAs($this->admin);
        $this->assertSame(url("/tv/{$screen->token}"), Livewire::test(Signature::class)->instance()->playerUrl);

        // Layar yang masih memakai link lama (48 karakter) tetap tampil sampai link dibuat ulang.
        $legacy = str_repeat('ab12', 12);
        $screen->update(['token' => $legacy]);
        $this->get("/signature/{$legacy}")->assertOk();
        $this->get("/tv/{$legacy}")->assertOk();
        $this->get('/tv/abc')->assertNotFound();

        // Tombol OK di remote juga meminta layar penuh (bukan cuma suara).
        $this->get("/tv/{$legacy}")->assertSee('requestFullscreen', false)->assertSee('webkitRequestFullscreen', false);
    }

    public function test_tv_screen_built_in_texts_are_in_english(): void
    {
        $screen = $this->service()->ensureDefaults();
        $page = $this->get("/tv/{$screen->token}")->assertOk();

        $page->assertSee('<html lang="en">', false)
            ->assertSee('Court Status')->assertSee("Today's Schedule", false)->assertSee('Awaiting check-in')
            ->assertSee('Supported by')->assertSee('See you tomorrow!')->assertSee('Press OK on the remote')
            ->assertSee('Wednesday')->assertSee('January');

        foreach (['Status Lapangan', 'Jadwal Hari Ini', 'Sedang main', 'Menunggu check-in', 'Didukung oleh', 'Sampai jumpa besok', 'Tekan OK', 'Koneksi terputus', 'Selamat datang', "'Senin'", "'Januari'"] as $indonesian) {
            $page->assertDontSee($indonesian, false);
        }
    }

    public function test_versions_can_be_restored_and_link_regenerated(): void
    {
        $this->actingAs($this->admin);
        $screen = $this->service()->ensureDefaults();
        $other = SignatureTemplate::whereKeyNot($screen->template_id)->orderBy('created_at')->firstOrFail();

        $page = Livewire::test(Signature::class)->call('publish');
        $page->set('screenTemplateId', $other->id)->call('publish');   // pindah template → versi 2
        $first = SignatureVersion::where('version', 1)->sole();

        // Kembalikan = versi 1 tayang lagi, tidak disalin jadi versi baru.
        $page->call('restoreVersion', $first->id);
        $this->assertSame(1, $screen->fresh()->publishedVersion->version);
        $this->assertSame(2, SignatureVersion::count());
        $this->assertSame(2, \App\Models\Audit\ActivityLog::where('event', 'signature.published')->count());
        $this->assertSame(1, \App\Models\Audit\ActivityLog::where('event', 'signature.restored')->count());

        $oldToken = $screen->fresh()->token;
        $page->call('regenerateToken');
        $this->get("/signature/{$oldToken}")->assertNotFound();
        $this->get('/signature/'.$screen->fresh()->token)->assertOk();
    }

    public function test_viewer_without_manage_permission_cannot_change_anything(): void
    {
        $viewer = User::factory()->cashier()->create();
        $viewer->givePermissionTo('View:Signature');
        $this->actingAs($viewer);

        Livewire::test(Signature::class)->assertOk()->call('saveScreen')->assertForbidden();
        Livewire::test(Signature::class)->call('publish')->assertForbidden();
        $this->assertSame(0, SignatureVersion::count());
    }
}
