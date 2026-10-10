<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\KelolaMenuFnb;
use App\Models\Fnb\FnbCategory;
use App\Models\Fnb\FnbMenu;
use App\Models\Fnb\FnbModifierGroup;
use App\Models\Fnb\FnbStation;
use App\Models\Fnb\RawMaterial;
use App\Models\Fnb\RecipeBom;
use App\Models\User;
use App\Services\Media\SecureImageUploader;
use App\Services\Permission\Club61PermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class FnbMenuManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Club61PermissionMatrix::syncAllPermissions('web');
    }

    public function test_staff_without_permission_cannot_access_kelola_menu_fnb(): void
    {
        $cashier = User::factory()->cashier()->create();
        $this->actingAs($cashier);

        $this->assertFalse(KelolaMenuFnb::canAccess());
    }

    public function test_kitchen_role_can_view_but_not_manage_fnb_menu(): void
    {
        // kitchen digranting 'view_fnb_menu' di DatabaseSeeder tapi bukan 'manage_fnb_menu' —
        // pastikan bisa buka halamannya tapi aksi tulis (create/update/delete) tetap ditolak.
        $kitchen = User::factory()->kitchen()->create();
        \App\Models\Role::findByName('kitchen', 'web')->syncPermissions(['View:KelolaMenuFnb', 'view_fnb_menu']);
        $this->actingAs($kitchen);

        $this->assertTrue(KelolaMenuFnb::canAccess());

        Livewire::test(KelolaMenuFnb::class)
            ->call('saveCategory');

        $this->assertSame(0, FnbCategory::count());
    }

    public function test_admin_can_access_kelola_menu_fnb(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(KelolaMenuFnb::class)
            ->assertSuccessful()
            ->assertSee('Kelola Menu F&B & Tambahan');
    }

    public function test_admin_can_create_and_edit_category(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(KelolaMenuFnb::class)
            ->call('openCreateCategoryModal')
            ->set('categoryName', 'Coffee & Drinks')
            ->set('categorySortOrder', 1)
            ->call('saveCategory')
            ->assertHasNoErrors();

        $category = FnbCategory::where('name', 'Coffee & Drinks')->firstOrFail();

        Livewire::test(KelolaMenuFnb::class)
            ->call('openEditCategoryModal', $category->id)
            ->set('categoryName', 'Coffee & Non-Coffee')
            ->call('saveCategory')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('fnb_categories', [
            'id' => $category->id,
            'name' => 'Coffee & Non-Coffee',
        ]);
    }

    public function test_category_still_referenced_by_menu_cannot_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $category = FnbCategory::create(['name' => 'Toast & Meals', 'sort_order' => 1]);
        FnbMenu::create([
            'category_id' => $category->id,
            'name' => 'Smashed Avocado Toast',
            'base_price' => 55000,
            'is_available' => true,
        ]);

        Livewire::test(KelolaMenuFnb::class)
            ->call('deleteCategory', $category->id);

        $this->assertDatabaseHas('fnb_categories', ['id' => $category->id]);
    }

    public function test_category_without_menu_can_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $category = FnbCategory::create(['name' => 'Kategori Kosong', 'sort_order' => 9]);

        Livewire::test(KelolaMenuFnb::class)
            ->call('deleteCategory', $category->id);

        $this->assertDatabaseMissing('fnb_categories', ['id' => $category->id]);
    }

    public function test_admin_can_create_menu_with_photo_upload_and_photo_is_reencoded(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $category = FnbCategory::create(['name' => 'Coffee & Drinks', 'sort_order' => 1]);

        Livewire::test(KelolaMenuFnb::class)
            ->call('openCreateMenuModal')
            ->set('menuCategoryId', $category->id)
            ->set('menuName', 'Iced Spanish Latte')
            ->set('menuDescription', 'Espresso double shot dengan susu segar dingin.')
            ->set('menuBasePrice', 38000)
            ->set('menuStationId', '')
            ->set('menuIsAvailable', true)
            ->set('menuPhotoUpload', UploadedFile::fake()->image('latte.jpg', 2400, 1600))
            ->call('saveMenu')
            ->assertHasNoErrors();

        $menu = FnbMenu::where('name', 'Iced Spanish Latte')->firstOrFail();

        $this->assertNotNull($menu->image_url);
        Storage::disk('public')->assertExists($menu->image_url);
        $this->assertStringEndsWith('.jpg', $menu->image_url);

        [$width] = getimagesize(Storage::disk('public')->path($menu->image_url));
        $this->assertLessThanOrEqual(\App\Services\Media\SecureImageUploader::MAX_WIDTH, $width);
    }

    public function test_menu_upload_with_spoofed_mime_but_invalid_image_bytes_is_rejected(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $category = FnbCategory::create(['name' => 'Coffee & Drinks', 'sort_order' => 1]);

        // File mengaku "image/jpeg" (lolos validasi rule "image" di level form, yang cuma
        // percaya ekstensi/mime yang diklaim klien) tapi isinya BUKAN gambar sungguhan —
        // SecureImageUploader wajib tetap menolaknya karena memvalidasi dari ISI file
        // (getimagesize()), bukan cuma ekstensi/mime yang diklaim.
        Livewire::test(KelolaMenuFnb::class)
            ->call('openCreateMenuModal')
            ->set('menuCategoryId', $category->id)
            ->set('menuName', 'Menu Nakal')
            ->set('menuBasePrice', 10000)
            ->set('menuStationId', '')
            ->set('menuPhotoUpload', UploadedFile::fake()->create('disguised.jpg', 10, 'image/jpeg'))
            ->call('saveMenu');

        $this->assertDatabaseMissing('fnb_menus', ['name' => 'Menu Nakal']);
    }

    public function test_replacing_menu_photo_deletes_the_old_file_from_disk(): void
    {
        // Diuji langsung lewat SecureImageUploader + event model (bukan lewat Livewire) —
        // yang mau dibuktikan adalah logic milik kita sendiri (SecureImageUploader +
        // FnbMenu::booted()) benar-benar membuang file lama begitu image_url berubah.
        Storage::fake('public');

        $category = FnbCategory::create(['name' => 'Coffee & Drinks', 'sort_order' => 1]);
        $oldPath = SecureImageUploader::store(UploadedFile::fake()->image('latte-v1.jpg', 800, 600), 'fnb/menu');
        $menu = FnbMenu::create([
            'category_id' => $category->id,
            'name' => 'Iced Spanish Latte',
            'base_price' => 38000,
            'is_available' => true,
            'image_url' => $oldPath,
        ]);
        Storage::disk('public')->assertExists($oldPath);

        $newPath = SecureImageUploader::store(UploadedFile::fake()->image('latte-v2.jpg', 800, 600), 'fnb/menu');
        $menu->update(['image_url' => $newPath]);

        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);
    }

    public function test_deleting_menu_removes_its_photo_from_disk(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $category = FnbCategory::create(['name' => 'Coffee & Drinks', 'sort_order' => 1]);
        $photoPath = SecureImageUploader::store(UploadedFile::fake()->image('latte.jpg', 800, 600), 'fnb/menu');
        $menu = FnbMenu::create([
            'category_id' => $category->id,
            'name' => 'Iced Spanish Latte',
            'base_price' => 38000,
            'is_available' => true,
            'image_url' => $photoPath,
        ]);

        Livewire::test(KelolaMenuFnb::class)
            ->call('deleteMenu', $menu->id);

        $this->assertDatabaseMissing('fnb_menus', ['id' => $menu->id]);
        Storage::disk('public')->assertMissing($photoPath);
    }

    public function test_admin_can_toggle_menu_availability_via_edit(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $category = FnbCategory::create(['name' => 'Coffee & Drinks', 'sort_order' => 1]);
        $menu = FnbMenu::create([
            'category_id' => $category->id,
            'name' => 'Ceremonial Oat Matcha',
            'base_price' => 45000,
            'is_available' => true,
        ]);

        Livewire::test(KelolaMenuFnb::class)
            ->call('openEditMenuModal', $menu->id)
            ->set('menuIsAvailable', false)
            ->call('saveMenu')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('fnb_menus', [
            'id' => $menu->id,
            'is_available' => false,
        ]);
    }

    public function test_admin_can_create_modifier_group_with_nested_options(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(KelolaMenuFnb::class)
            ->call('openCreateModifierGroupModal')
            ->set('modifierGroupName', 'Milk Option')
            ->set('modifierGroupIsRequired', false)
            ->set('modifierGroupMaxSelection', 1)
            ->set('modifierOptions', [
                ['id' => null, 'name' => 'Full Cream', 'extra_price' => 0],
                ['id' => null, 'name' => 'Oat Milk', 'extra_price' => 8000],
            ])
            ->call('saveModifierGroup')
            ->assertHasNoErrors();

        $group = FnbModifierGroup::where('name', 'Milk Option')->firstOrFail();
        $this->assertCount(2, $group->options);
        $this->assertDatabaseHas('fnb_modifier_options', [
            'group_id' => $group->id,
            'name' => 'Oat Milk',
            'extra_price' => 8000,
        ]);
    }

    public function test_removing_option_row_and_resaving_deletes_it_from_database(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $group = FnbModifierGroup::create(['name' => 'Sugar Level', 'is_required' => false, 'max_selection' => 1]);
        $keptOption = $group->options()->create(['name' => 'Normal', 'extra_price' => 0]);
        $removedOption = $group->options()->create(['name' => 'Extra Sweet', 'extra_price' => 0]);

        Livewire::test(KelolaMenuFnb::class)
            ->call('openEditModifierGroupModal', $group->id)
            ->call('removeModifierOptionRow', 1)
            ->call('saveModifierGroup')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('fnb_modifier_options', ['id' => $keptOption->id]);
        $this->assertDatabaseMissing('fnb_modifier_options', ['id' => $removedOption->id]);
    }

    public function test_menu_can_be_attached_to_modifier_group_and_detached_automatically_when_group_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $category = FnbCategory::create(['name' => 'Coffee & Drinks', 'sort_order' => 1]);
        $menu = FnbMenu::create([
            'category_id' => $category->id,
            'name' => 'Iced Spanish Latte',
            'base_price' => 38000,
            'is_available' => true,
        ]);
        $group = FnbModifierGroup::create(['name' => 'Sugar Level', 'is_required' => false, 'max_selection' => 1]);

        Livewire::test(KelolaMenuFnb::class)
            ->call('openEditMenuModal', $menu->id)
            ->set('menuModifierGroupIds', [$group->id])
            ->call('saveMenu')
            ->assertHasNoErrors();

        $this->assertTrue($menu->fresh()->modifierGroups->contains('id', $group->id));

        Livewire::test(KelolaMenuFnb::class)
            ->call('deleteModifierGroup', $group->id);

        $this->assertDatabaseMissing('fnb_menu_modifier_group', ['group_id' => $group->id]);
        $this->assertDatabaseHas('fnb_menus', ['id' => $menu->id]);
        $this->assertCount(0, $menu->fresh()->modifierGroups);
    }

    public function test_deleting_menu_cascades_recipe_bom_rows_without_error(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $category = FnbCategory::create(['name' => 'Coffee & Drinks', 'sort_order' => 1]);
        $menu = FnbMenu::create([
            'category_id' => $category->id,
            'name' => 'Iced Spanish Latte',
            'base_price' => 38000,
            'is_available' => true,
        ]);
        $rawMaterial = RawMaterial::create([
            'code' => 'RAW-TEST',
            'name' => 'Bahan Baku Test',
            'unit' => 'ML',
            'current_stock' => 1000,
            'min_alert_stock' => 100,
        ]);
        $recipe = RecipeBom::create([
            'menu_id' => $menu->id,
            'raw_material_id' => $rawMaterial->id,
            'quantity_used' => 160,
        ]);

        Livewire::test(KelolaMenuFnb::class)
            ->call('deleteMenu', $menu->id);

        $this->assertDatabaseMissing('fnb_menus', ['id' => $menu->id]);
        $this->assertDatabaseMissing('recipe_boms', ['id' => $recipe->id]);
        $this->assertDatabaseHas('raw_materials', ['id' => $rawMaterial->id]);
    }

    public function test_switching_tab_with_open_modal_shows_unsaved_changes_prompt(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $component = Livewire::test(KelolaMenuFnb::class)
            ->call('openCreateCategoryModal')
            ->set('categoryName', 'Belum Disimpan')
            ->call('requestTabChange', 'menus');

        $component->assertSet('showUnsavedChangesModal', true)
            ->assertSet('activeTab', 'categories')
            ->assertSet('pendingTab', 'menus');
    }

    public function test_discarding_unsaved_category_switches_tab_without_saving(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(KelolaMenuFnb::class)
            ->call('openCreateCategoryModal')
            ->set('categoryName', 'Batal Aja')
            ->call('requestTabChange', 'menus')
            ->call('discardAndSwitchTab')
            ->assertSet('activeTab', 'menus')
            ->assertSet('showCategoryModal', false);

        $this->assertDatabaseMissing('fnb_categories', ['name' => 'Batal Aja']);
    }

    public function test_saving_before_tab_switch_persists_data_and_switches_tab(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(KelolaMenuFnb::class)
            ->call('openCreateCategoryModal')
            ->set('categoryName', 'Kategori Sempat Kepending')
            ->set('categorySortOrder', 5)
            ->call('requestTabChange', 'menus')
            ->call('saveAndSwitchTab')
            ->assertSet('activeTab', 'menus')
            ->assertSet('showCategoryModal', false);

        $this->assertDatabaseHas('fnb_categories', ['name' => 'Kategori Sempat Kepending']);
    }

    public function test_tab_switch_is_immediate_when_no_modal_is_open(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(KelolaMenuFnb::class)
            ->call('requestTabChange', 'menus')
            ->assertSet('activeTab', 'menus')
            ->assertSet('showUnsavedChangesModal', false);
    }

    public function test_admin_manages_stations_and_assigns_menu_to_a_station(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $category = FnbCategory::create(['name' => 'Toast & Meals', 'sort_order' => 1]);

        Livewire::test(KelolaMenuFnb::class)
            ->call('requestTabChange', 'stations')
            ->call('openCreateStationModal')
            ->set('stationName', 'Kitchen')
            ->set('stationPrinterHost', 'http://192.168.1.50/print')
            ->call('saveStation')
            ->assertHasErrors(['stationPrinterHost' => 'regex'])
            ->set('stationPrinterHost', ' 192.168.1.50 ')
            ->call('saveStation')
            ->assertHasNoErrors()
            ->assertSee('192.168.1.50:9100');

        $kitchen = FnbStation::where('name', 'Kitchen')->sole();
        $this->assertSame('192.168.1.50', $kitchen->printer_host);
        $this->assertTrue($kitchen->is_active);

        Livewire::test(KelolaMenuFnb::class)
            ->call('openCreateStationModal')
            ->set('stationName', 'Kitchen')
            ->call('saveStation')
            ->assertHasErrors(['stationName' => 'unique']);

        Livewire::test(KelolaMenuFnb::class)
            ->call('openCreateMenuModal')
            ->set('menuCategoryId', $category->id)
            ->set('menuName', 'Smashed Avocado Toast')
            ->set('menuBasePrice', 55000)
            ->set('menuStationId', $kitchen->id)
            ->call('saveMenu')
            ->assertHasNoErrors();

        $this->assertSame($kitchen->id, FnbMenu::where('name', 'Smashed Avocado Toast')->value('station_id'));
    }

    public function test_deleting_a_station_moves_its_menus_back_to_the_cashier_and_it_can_be_added_again(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $category = FnbCategory::create(['name' => 'Drinks', 'sort_order' => 1]);
        $bar = FnbStation::create(['name' => 'Bar', 'printer_host' => '192.168.1.51']);
        $menu = FnbMenu::create(['category_id' => $category->id, 'name' => 'Latte', 'base_price' => 38000, 'station_id' => $bar->id, 'is_available' => true]);

        Livewire::test(KelolaMenuFnb::class)->call('deleteStation', $bar->id);

        $this->assertDatabaseMissing('fnb_stations', ['id' => $bar->id]);
        $this->assertNull($menu->fresh()->station_id);

        // Nanti bar dibuka lagi: tambah stasiun baru, lalu pindahkan menunya.
        Livewire::test(KelolaMenuFnb::class)
            ->call('openCreateStationModal')->set('stationName', 'Bar')->set('stationPrinterHost', '192.168.1.52')->call('saveStation')
            ->call('openEditMenuModal', $menu->id)->assertSet('menuStationId', '')
            ->set('menuStationId', FnbStation::where('name', 'Bar')->value('id'))->call('saveMenu')->assertHasNoErrors();

        $this->assertSame('Bar', $menu->fresh()->station->name);
    }

    public function test_staff_without_manage_permission_cannot_change_stations(): void
    {
        // Dapur boleh melihat halaman menu, tapi tidak boleh mengubah stasiun / IP printer.
        $kitchen = User::factory()->kitchen()->create();
        \App\Models\Role::findByName('kitchen', 'web')->syncPermissions(['View:KelolaMenuFnb', 'view_fnb_menu']);
        $this->actingAs($kitchen);

        Livewire::test(KelolaMenuFnb::class)
            ->set('stationName', 'Bar')
            ->call('saveStation')
            ->assertForbidden();

        $this->assertSame(0, FnbStation::count());
    }
}
