<?php

namespace Tests\Feature\Signature;

use App\Filament\Pages\Signature;
use App\Models\Signature\SignatureContent;
use App\Models\Signature\SignatureScreen;
use App\Models\Signature\SignatureTemplate;
use App\Models\User;
use App\Services\Permission\Club61PermissionMatrix;
use App\Services\Signature\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Digital Signage Tahap 2: konten YouTube, transisi antar konten, template custom drag & drop (maks 6 zona). */
class SignatureStage2Test extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected SignatureScreen $screen;

    protected function setUp(): void
    {
        parent::setUp();
        Club61PermissionMatrix::syncAllPermissions('web');
        Storage::fake('public');
        $this->admin = User::factory()->superAdmin()->create();
        $this->screen = app(SignatureService::class)->ensureDefaults();
        $this->actingAs($this->admin);
    }

    // ================================================================== YOUTUBE

    public function test_only_real_youtube_links_are_accepted_and_only_the_video_id_is_kept(): void
    {
        foreach ([
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtube.com/watch?v=dQw4w9WgXcQ&t=42s&list=PL123',
            'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ?si=abc',
            'https://www.youtube.com/shorts/dQw4w9WgXcQ',
            'https://www.youtube.com/embed/dQw4w9WgXcQ',
            'https://www.youtube.com/live/dQw4w9WgXcQ',
            'youtu.be/dQw4w9WgXcQ',
        ] as $link) {
            $this->assertSame('dQw4w9WgXcQ', SignatureContent::youtubeId($link), $link);
        }

        foreach ([
            'https://evil.example/watch?v=dQw4w9WgXcQ',
            'https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ',
            'https://www.youtube.com/watch?v=terlalu-pendek',
            'https://www.youtube.com/watch?v="><script>',
            'javascript:alert(1)//youtube.com/watch?v=dQw4w9WgXcQ',
            'https://www.youtube.com/channel/UC123',
            '',
        ] as $link) {
            $this->assertNull(SignatureContent::youtubeId($link), $link);
        }
    }

    public function test_admin_adds_a_youtube_video_and_the_tv_gets_only_its_id(): void
    {
        $page = Livewire::test(Signature::class)
            ->call('openCreateContent')
            ->set('contentName', 'Highlight Turnamen')
            ->set('contentType', 'YOUTUBE')
            ->set('contentYoutube', 'https://evil.example/watch?v=dQw4w9WgXcQ')
            ->call('saveContent')
            ->assertHasErrors('contentYoutube');

        $page->set('contentYoutube', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10')
            ->set('contentDuration', 120)
            ->set('contentSound', true)
            ->set('contentVolume', 40)
            ->call('saveContent')
            ->assertHasNoErrors();

        $video = SignatureContent::where('name', 'Highlight Turnamen')->sole();
        $this->assertSame('YOUTUBE', $video->type);
        $this->assertSame('dQw4w9WgXcQ', $video->url);
        $this->assertNull($video->file_path);
        $this->assertTrue($video->sound);

        $template = SignatureTemplate::find($this->screen->template_id);
        $template->playlistItems()->delete();
        $template->playlistItems()->create(['zone_key' => 'main', 'content_id' => $video->id, 'sort_order' => 1]);
        $page->call('publish');

        $item = $this->getJson("/signature/{$this->screen->token}/payload")->json('playlists.main.0');
        $this->assertSame('YOUTUBE', $item['type']);
        $this->assertSame('dQw4w9WgXcQ', $item['youtube_id']);
        $this->assertNull($item['url']);
        $this->assertSame(120, $item['duration'], 'durasi = batas maksimal tayang');
        $this->assertTrue($item['sound']);

        // Isi kolom yang dirusak langsung di database tidak pernah sampai ke TV.
        $video->forceFill(['url' => 'https://evil.example/x'])->save();
        $page->call('publish');
        $this->assertSame([], $this->getJson("/signature/{$this->screen->token}/payload")->json('playlists.main'));
    }

    public function test_youtube_content_can_be_edited_and_deleted_like_other_library_content(): void
    {
        $video = SignatureContent::create(['name' => 'Promo YT', 'type' => 'YOUTUBE', 'url' => 'dQw4w9WgXcQ', 'duration_seconds' => 60]);

        Livewire::test(Signature::class)
            ->call('openEditContent', $video->id)
            ->assertSet('contentYoutube', 'https://youtu.be/dQw4w9WgXcQ')
            ->set('contentYoutube', 'https://youtu.be/aaaaaaaaaaa')
            ->call('saveContent')
            ->assertHasNoErrors()
            ->call('deleteContent', $video->id);

        $this->assertNull($video->fresh());
    }

    // ================================================================== DUA LAYAR

    public function test_two_screens_each_show_their_own_template_through_their_own_link(): void
    {
        $lobbyTemplate = SignatureTemplate::find($this->screen->template_id);
        $courtTemplate = SignatureTemplate::where('id', '!=', $lobbyTemplate->id)->orderBy('created_at')->firstOrFail();
        $lobbyPromo = SignatureContent::create(['name' => 'Promo Lobby', 'type' => 'IMAGE', 'file_path' => 'signature/images/lobby.jpg', 'duration_seconds' => 10]);
        $courtPromo = SignatureContent::create(['name' => 'Promo Lapangan', 'type' => 'IMAGE', 'file_path' => 'signature/images/court.jpg', 'duration_seconds' => 10]);
        Storage::disk('public')->put('signature/images/lobby.jpg', 'x');
        Storage::disk('public')->put('signature/images/court.jpg', 'x');
        foreach ([[$lobbyTemplate, $lobbyPromo], [$courtTemplate, $courtPromo]] as [$template, $content]) {
            $template->playlistItems()->delete();
            $template->playlistItems()->create(['zone_key' => 'main', 'content_id' => $content->id, 'sort_order' => 1]);
        }

        $page = Livewire::test(Signature::class)->call('publish');
        $page->call('addScreen')->assertNotified('Layar 2 ditambahkan');
        $second = SignatureScreen::where('name', 'Layar 2')->sole();
        $this->assertNotSame($this->screen->token, $second->token);
        $this->assertSame($second->id, $page->get('screenId'));

        $page->set('screenName', 'TV Lapangan')->set('screenTemplateId', $courtTemplate->id)->call('publish');
        $this->assertSame($lobbyTemplate->id, $this->screen->fresh()->template_id, 'layar pertama tidak ikut berubah');

        $names = fn (SignatureScreen $s) => collect($this->getJson("/signature/{$s->fresh()->token}/payload")->json('playlists'))->flatten(1)->pluck('name')->all();
        $this->assertSame(['Promo Lobby'], $names($this->screen));
        $this->assertSame(['Promo Lapangan'], $names($second));

        // Pindah layar memuat pengaturannya sendiri; screenId tidak bisa diubah langsung dari browser.
        $page->call('selectScreen', $this->screen->id)->assertSet('screenTemplateId', $lobbyTemplate->id)->assertSee('TV Lapangan');
        $this->expectExceptionMessageMatches('/locked/i');
        $page->set('screenId', $second->id);
    }

    public function test_a_screen_can_be_deleted_but_never_the_last_one(): void
    {
        $page = Livewire::test(Signature::class)->call('deleteScreen')->assertNotified('Layar tidak bisa dihapus');
        $this->assertSame(1, SignatureScreen::count());

        $page->call('addScreen')->call('publish');
        $second = SignatureScreen::where('name', 'Layar 2')->sole();
        $token = $second->token;
        $page->call('deleteScreen')->assertNotified('Layar 2 dihapus')->assertSet('screenId', $this->screen->id);

        $this->assertNull($second->fresh());
        $this->assertSame(0, \App\Models\Signature\SignatureVersion::where('screen_id', $second->id)->count());
        $this->get("/signature/{$token}")->assertNotFound();
        $this->assertTrue(\App\Models\Audit\ActivityLog::where('event', 'signature.screen_deleted')->exists());
    }

    // ================================================================== COURTSEE

    public function test_admin_adds_a_courtsee_live_and_the_tv_gets_only_its_id(): void
    {
        $uuid = '01a19b61-4982-7399-854c-bd9105b04760';
        $this->assertSame($uuid, SignatureContent::courtseeId("https://courtsee.com/watch/{$uuid}"));
        $this->assertSame($uuid, SignatureContent::courtseeId('www.courtsee.com/watch/'.strtoupper($uuid).'/'));
        foreach ([
            "https://courtsee.com.evil.example/watch/{$uuid}",
            "https://evil.example/watch/{$uuid}",
            "https://courtsee.com/watch/{$uuid}/../../login",
            'https://courtsee.com/watch/bukan-uuid',
            "javascript:alert(1)//courtsee.com/watch/{$uuid}",
            '',
        ] as $link) {
            $this->assertNull(SignatureContent::courtseeId($link), $link);
        }

        $page = Livewire::test(Signature::class)
            ->call('openCreateContent')
            ->set('contentName', 'Live Lapangan 2')
            ->set('contentType', 'COURTSEE')
            ->set('contentCourtsee', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ')
            ->call('saveContent')
            ->assertHasErrors('contentCourtsee');

        $page->set('contentCourtsee', "https://courtsee.com/watch/{$uuid}")
            ->set('contentDuration', 3600)
            ->call('saveContent')
            ->assertHasNoErrors();

        $live = SignatureContent::where('name', 'Live Lapangan 2')->sole();
        $this->assertSame('COURTSEE', $live->type);
        $this->assertSame($uuid, $live->url);
        $this->assertNull($live->file_path);
        $this->assertFalse($live->sound, 'suara diatur pemutar Courtsee');

        $template = SignatureTemplate::find($this->screen->template_id);
        $template->playlistItems()->delete();
        $template->playlistItems()->create(['zone_key' => 'main', 'content_id' => $live->id, 'sort_order' => 1]);
        $page->call('publish');

        $item = $this->getJson("/signature/{$this->screen->token}/payload")->json('playlists.main.0');
        $this->assertSame('COURTSEE', $item['type']);
        $this->assertSame($uuid, $item['courtsee_id']);
        $this->assertNull($item['youtube_id']);
        $this->assertNull($item['url']);
        $this->assertSame(3600, $item['duration']);

        // Edit memuat link utuh; isi kolom yang dirusak langsung di database tidak pernah sampai ke TV.
        Livewire::test(Signature::class)->call('openEditContent', $live->id)->assertSet('contentCourtsee', "https://courtsee.com/watch/{$uuid}");
        $live->forceFill(['url' => 'https://evil.example/x'])->save();
        $page->call('publish');
        $this->assertSame([], $this->getJson("/signature/{$this->screen->token}/payload")->json('playlists.main'));
    }

    // ================================================================== TRANSISI

    public function test_template_transition_is_saved_validated_and_sent_to_the_tv(): void
    {
        $template = SignatureTemplate::find($this->screen->template_id);
        $this->assertSame('fade', app(SignatureService::class)->buildPayload($this->screen)['template']['theme']['transition'], 'template lama = fade');

        Livewire::test(Signature::class)
            ->call('openEditTemplate', $template->id)
            ->set('tplTheme.transition', 'meledak')
            ->call('saveTemplate')
            ->assertHasErrors('tplTheme.transition')
            ->set('tplTheme.transition', 'slide')
            ->call('saveTemplate')
            ->assertHasNoErrors();

        $this->assertSame('slide', app(SignatureService::class)->buildPayload($this->screen->fresh())['template']['theme']['transition']);
    }

    // ================================================================== TEMPLATE CUSTOM

    public function test_custom_template_starts_from_the_preset_and_keeps_a_playlist_per_zone(): void
    {
        $page = Livewire::test(Signature::class)
            ->call('openCreateTemplate')
            ->set('tplName', 'Dua Layar')
            ->set('tplLayout', 'PRESET_A')
            ->set('tplLayout', 'CUSTOM');

        // Titik awal = preset A dalam satuan grid 24 × 24 (konten 18 × 22, panel 6 × 22, running text 24 × 2).
        $this->assertSame(
            [['main', 'playlist', 0, 0, 18, 22], ['side', 'side_panel', 18, 0, 6, 22], ['ticker', 'ticker', 0, 22, 24, 2]],
            array_map(fn ($z) => [$z['key'], $z['kind'], $z['x'], $z['y'], $z['w'], $z['h']], $page->get('tplZones'))
        );

        // Layar sudah penuh → zona baru ditolak sampai ada yang dikecilkan.
        $page->call('addZone', 'playlist')->assertNotified('Tidak ada tempat kosong');
        $page->call('moveZones', [['key' => 'main', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 22]])
            ->call('addZone', 'playlist');
        $added = collect($page->get('tplZones'))->firstWhere('key', 'z1');
        $this->assertSame([12, 0, 4, 4], [$added['x'], $added['y'], $added['w'], $added['h']]);
        $this->assertFalse($added['sound'], 'zona utama sudah bersuara');

        $page->call('moveZones', [['key' => 'z1', 'x' => 12, 'y' => 0, 'w' => 6, 'h' => 22]]);
        $clock = SignatureContent::where('type', 'CLOCK')->sole();
        $schedule = SignatureContent::where('type', 'SCHEDULE')->sole();
        $page->set('tplAddContentIds.main', $schedule->id)->call('addPlaylistItem', 'main')
            ->set('tplAddContentIds.z1', $clock->id)->call('addPlaylistItem', 'z1')
            ->call('setSoundZone', 'z1')
            ->call('saveTemplate')
            ->assertHasNoErrors();

        $template = SignatureTemplate::where('name', 'Dua Layar')->sole();
        $this->assertSame('CUSTOM', $template->layout);
        $this->assertCount(4, $template->zones);
        $this->assertSame(['main' => [$schedule->id], 'z1' => [$clock->id]], $template->playlistItems->groupBy('zone_key')->map(fn ($items) => $items->pluck('content_id')->all())->all());

        $this->screen->update(['template_id' => $template->id]);
        $payload = app(SignatureService::class)->buildPayload($this->screen->fresh());
        $zones = collect($payload['template']['zones'])->keyBy('key');
        $this->assertSame('CUSTOM', $payload['template']['layout']);
        $this->assertEquals(50.0, $zones['main']['w'], 'satuan grid → persen layar');
        $this->assertEquals(25.0, $zones['z1']['w']);
        $this->assertTrue($zones['z1']['sound']);
        $this->assertFalse($zones['main']['sound']);
        $this->assertSame('SCHEDULE', $payload['playlists']['main'][0]['type']);
        $this->assertSame('CLOCK', $payload['playlists']['z1'][0]['type']);

        // Dibuka lagi: susunan & playlist per zona kembali utuh.
        Livewire::test(Signature::class)->call('openEditTemplate', $template->id)
            ->assertSet('tplLayout', 'CUSTOM')
            ->assertSet('tplPlaylists.z1.0.content_id', $clock->id);
    }

    public function test_zone_rules_are_enforced_on_the_server(): void
    {
        $zone = fn (string $key, string $kind, int $x, int $y, int $w, int $h, array $extra = []) => compact('key', 'kind', 'x', 'y', 'w', 'h') + $extra;
        $valid = [$zone('main', 'playlist', 0, 0, 24, 22), $zone('t', 'ticker', 0, 22, 24, 2)];
        $this->assertCount(2, SignatureService::normalizeCustomZones($valid));

        $cases = [
            'tanpa zona konten' => [$zone('t', 'ticker', 0, 22, 24, 2)],
            'dua running text' => [$zone('main', 'playlist', 0, 0, 24, 20), $zone('a', 'ticker', 0, 20, 24, 2), $zone('b', 'ticker', 0, 22, 24, 2)],
            'running text terlalu tinggi' => [$zone('main', 'playlist', 0, 0, 24, 19), $zone('t', 'ticker', 0, 19, 24, 5)],
            'keluar layar' => [$zone('main', 'playlist', 20, 0, 8, 8)],
            'menumpuk' => [$zone('main', 'playlist', 0, 0, 12, 12), $zone('b', 'playlist', 6, 6, 12, 12)],
            'terlalu kecil' => [$zone('main', 'playlist', 0, 0, 2, 2)],
            'dua zona bersuara' => [$zone('main', 'playlist', 0, 0, 12, 12, ['sound' => true]), $zone('b', 'playlist', 12, 0, 12, 12, ['sound' => true])],
            'kunci aneh' => [$zone('<b>x</b>', 'playlist', 0, 0, 12, 12)],
            'jenis tidak dikenal' => [$zone('main', 'iframe', 0, 0, 12, 12)],
            'koordinat pecahan' => [['key' => 'main', 'kind' => 'playlist', 'x' => 0.5, 'y' => 0, 'w' => 12, 'h' => 12]],
            'koordinat teks' => [['key' => 'main', 'kind' => 'playlist', 'x' => '0; drop', 'y' => 0, 'w' => 12, 'h' => 12]],
            'lebih dari 6 zona' => array_map(fn ($i) => $zone('z'.$i, 'playlist', ($i % 6) * 4, intdiv($i, 6) * 4, 4, 4), range(0, 6)),
        ];
        foreach ($cases as $name => $zones) {
            try {
                SignatureService::normalizeCustomZones($zones);
                $this->fail("Harus ditolak: {$name}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('tplZones', $e->errors(), $name);
            }
        }
    }

    public function test_editor_rejects_overlaps_limits_zones_and_keeps_one_content_zone(): void
    {
        $page = Livewire::test(Signature::class)
            ->call('openCreateTemplate')
            ->set('tplLayout', 'PRESET_B')
            ->set('tplLayout', 'CUSTOM');
        $this->assertSame([['main', 0, 0, 24, 24]], array_map(fn ($z) => [$z['key'], $z['x'], $z['y'], $z['w'], $z['h']], $page->get('tplZones')));

        $page->call('moveZones', [['key' => 'main', 'x' => 0, 'y' => 0, 'w' => 8, 'h' => 8]]);
        foreach (range(1, 5) as $i) {
            $page->call('addZone', $i === 5 ? 'ticker' : 'playlist');
        }
        $this->assertCount(6, $page->get('tplZones'));
        $page->call('addZone', 'playlist')->assertNotified('Maksimal 6 zona');
        $this->assertCount(6, $page->get('tplZones'));

        // Digeser menumpuk → ditolak, posisi lama dipertahankan.
        $before = $page->get('tplZones');
        $page->call('moveZones', [['key' => 'z1', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 4]])->assertNotified('Susunan tidak bisa dipakai');
        $this->assertSame($before, $page->get('tplZones'));

        // Zona konten terakhir tidak bisa dihapus.
        $page->set('tplZones', [['key' => 'main', 'kind' => 'playlist', 'x' => 0, 'y' => 0, 'w' => 24, 'h' => 24, 'sound' => true]])
            ->call('removeZone', 'main')
            ->assertNotified('Minimal satu zona konten');
        $this->assertCount(1, $page->get('tplZones'));
    }

    public function test_removed_zone_drops_its_playlist_and_preview_shows_unsaved_custom_layout(): void
    {
        $clock = SignatureContent::where('type', 'CLOCK')->sole();
        $page = Livewire::test(Signature::class)
            ->call('openCreateTemplate')
            ->set('tplName', 'Hapus Zona')
            ->set('tplLayout', 'PRESET_B')
            ->set('tplLayout', 'CUSTOM')
            ->call('moveZones', [['key' => 'main', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 24]])
            ->call('addZone', 'playlist')
            ->set('tplAddContentIds.z1', $clock->id)->call('addPlaylistItem', 'z1');
        $this->assertCount(1, $page->get('tplPlaylists.z1'));

        $page->call('previewTemplate');
        $this->assertNotNull($page->get('previewUrl'));
        $preview = app(SignatureService::class)->previewPayload(basename(parse_url($page->get('previewUrl'), PHP_URL_PATH)));
        $this->assertSame(['main', 'z1'], array_column($preview['template']['zones'], 'key'));
        $this->assertSame('CLOCK', $preview['playlists']['z1'][0]['type']);

        $page->call('removeZone', 'z1')->call('saveTemplate')->assertHasNoErrors();
        $template = SignatureTemplate::where('name', 'Hapus Zona')->sole();
        $this->assertSame(['main'], array_column($template->zones, 'key'));
        $this->assertSame(0, $template->playlistItems()->count());
    }

    public function test_viewer_cannot_use_the_zone_editor(): void
    {
        $viewer = User::factory()->cashier()->create();
        $viewer->givePermissionTo('View:Signature');
        $this->actingAs($viewer);

        Livewire::test(Signature::class)->call('addZone', 'playlist')->assertForbidden();
    }
}
