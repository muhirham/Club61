<?php

namespace App\Filament\Pages;

use App\Models\Fnb\FnbCategory;
use App\Models\Fnb\FnbMenu;
use App\Models\Fnb\FnbModifierGroup;
use App\Models\Fnb\FnbModifierOption;
use App\Models\Fnb\FnbStation;
use App\Services\Media\SecureImageUploader;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\WithFileUploads;
use UnitEnum;

class KelolaMenuFnb extends Page
{
    use HasPageShield;
    use WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cake';

    protected static ?string $navigationLabel = 'Menu F&B';

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?string $title = 'Kelola Menu F&B & Tambahan';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.kelola-menu-fnb';

    // Tab Aktif: 'categories', 'menus', 'modifiers', atau 'stations'
    public string $activeTab = 'categories';

    // Guard pindah tab kalau ada modal yang lagi kebuka & belum disimpan
    public ?string $pendingTab = null;

    public bool $showUnsavedChangesModal = false;

    // --- State Modal Kategori ---
    public bool $showCategoryModal = false;

    public ?string $editingCategoryId = null;

    public string $categoryName = '';

    public int|string $categorySortOrder = 0;

    // --- State Modal Menu ---
    public bool $showMenuModal = false;

    public ?string $editingMenuId = null;

    public ?string $menuCategoryId = null;

    public string $menuName = '';

    public string $menuDescription = '';

    public $menuPhotoUpload = null;

    public ?string $existingMenuPhotoPath = null;

    public bool $removeMenuPhoto = false;

    public int|float|string $menuBasePrice = 0;

    /** Stasiun pembuat menu; '' = dibuat langsung di kasir (tanpa slip). */
    public string $menuStationId = '';

    public bool $menuIsAvailable = true;

    public array $menuModifierGroupIds = [];

    public string $menuSearch = '';

    public string $menuCategoryFilter = 'ALL';

    // --- State Modal Grup Tambahan / Modifier ---
    public bool $showModifierGroupModal = false;

    public ?string $editingModifierGroupId = null;

    public string $modifierGroupName = '';

    public bool $modifierGroupIsRequired = false;

    public int|string $modifierGroupMaxSelection = 1;

    public array $modifierOptions = [];

    // --- State Modal Stasiun & Printer ---
    public bool $showStationModal = false;

    public ?string $editingStationId = null;

    public string $stationName = '';

    public string $stationPrinterHost = '';

    public int|string $stationPrinterPort = 9100;

    public bool $stationIsActive = true;

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['categories', 'menus', 'modifiers', 'stations'], true) ? $tab : 'categories';
    }

    public function setMenuCategoryFilter(string $filter): void
    {
        $this->menuCategoryFilter = $filter;
    }

    // ==========================================
    // GUARD PINDAH TAB — modal terbuka = ada perubahan yang belum disimpan
    // ==========================================

    protected function hasOpenModal(): bool
    {
        return $this->showCategoryModal || $this->showMenuModal || $this->showModifierGroupModal || $this->showStationModal;
    }

    public function requestTabChange(string $tab): void
    {
        if ($this->hasOpenModal()) {
            $this->pendingTab = $tab;
            $this->showUnsavedChangesModal = true;

            return;
        }

        $this->setActiveTab($tab);
    }

    public function discardAndSwitchTab(): void
    {
        $this->closeCategoryModal();
        $this->closeMenuModal();
        $this->closeModifierGroupModal();
        $this->closeStationModal();
        $this->showUnsavedChangesModal = false;

        if ($this->pendingTab !== null) {
            $this->setActiveTab($this->pendingTab);
            $this->pendingTab = null;
        }
    }

    public function cancelTabChange(): void
    {
        $this->showUnsavedChangesModal = false;
        $this->pendingTab = null;
    }

    public function saveAndSwitchTab(): void
    {
        $targetTab = $this->pendingTab;
        $this->showUnsavedChangesModal = false;
        $this->pendingTab = null;

        if ($this->showCategoryModal) {
            $this->saveCategory();
        } elseif ($this->showMenuModal) {
            $this->saveMenu();
        } elseif ($this->showModifierGroupModal) {
            $this->saveModifierGroup();
        } elseif ($this->showStationModal) {
            $this->saveStation();
        }

        // Kalau validasi save gagal, modal masih kebuka (hasOpenModal() masih true) —
        // JANGAN pindah tab, biar staf lihat pesan error di modalnya dulu.
        if (! $this->hasOpenModal() && $targetTab !== null) {
            $this->setActiveTab($targetTab);
        }
    }

    // ==========================================
    // TAB 1: KATEGORI MENU
    // ==========================================

    public function openCreateCategoryModal(): void
    {
        $this->editingCategoryId = null;
        $this->categoryName = '';
        $this->categorySortOrder = (int) (FnbCategory::max('sort_order') ?? 0) + 1;
        $this->showCategoryModal = true;
    }

    public function openEditCategoryModal(string $categoryId): void
    {
        $category = FnbCategory::find($categoryId);
        if (! $category) {
            Notification::make()->title('Kategori tidak ditemukan')->danger()->send();

            return;
        }

        $this->editingCategoryId = $category->id;
        $this->categoryName = (string) $category->name;
        $this->categorySortOrder = $category->sort_order;
        $this->showCategoryModal = true;
    }

    public function closeCategoryModal(): void
    {
        $this->showCategoryModal = false;
        $this->editingCategoryId = null;
    }

    public function saveCategory(): void
    {
        $this->authorizeManage();

        $this->validate([
            'categoryName' => ['required', 'string', 'max:50'],
            'categorySortOrder' => ['required', 'integer', 'min:0'],
        ], [
            'categoryName.required' => 'Nama kategori wajib diisi.',
        ]);

        if ($this->editingCategoryId) {
            $category = FnbCategory::find($this->editingCategoryId);
            if (! $category) {
                Notification::make()->title('Kategori tidak ditemukan')->danger()->send();

                return;
            }

            $category->update([
                'name' => trim($this->categoryName),
                'sort_order' => (int) $this->categorySortOrder,
            ]);

            Notification::make()->title('Kategori Diperbarui')->success()->send();
        } else {
            FnbCategory::create([
                'name' => trim($this->categoryName),
                'sort_order' => (int) $this->categorySortOrder,
            ]);

            Notification::make()->title('Kategori Ditambahkan')->success()->send();
        }

        $this->closeCategoryModal();
    }

    /**
     * Guard "kategori masih dipakai menu" dijalankan di dalam 1 transaction dengan row lock
     * (lockForUpdate) — mengunci baris kategori supaya INSERT fnb_menus.category_id yang
     * sedang berjalan bersamaan (FK check InnoDB butuh shared lock ke baris kategori ini)
     * ikut menunggu, tidak bisa lolos di antara "cek jumlah menu" dan "hapus" (celah
     * TOCTOU/race condition).
     */
    public function deleteCategory(string $categoryId): void
    {
        $this->authorizeManage();

        DB::transaction(function () use ($categoryId) {
            $locked = FnbCategory::query()->lockForUpdate()->find($categoryId);

            if (! $locked) {
                return;
            }

            if ($locked->menus()->exists()) {
                Notification::make()
                    ->title('Kategori Masih Dipakai')
                    ->body('Kategori ini masih dipakai oleh minimal 1 menu — pindahkan menunya ke kategori lain dulu sebelum menghapus.')
                    ->danger()
                    ->send();

                return;
            }

            $locked->delete();
            Notification::make()->title('Kategori Dihapus')->success()->send();
        });
    }

    public function getCategoriesProperty(): Collection
    {
        return FnbCategory::withCount('menus')->orderBy('sort_order')->get();
    }

    // ==========================================
    // TAB 2: MENU F&B
    // ==========================================

    public function openCreateMenuModal(): void
    {
        $this->editingMenuId = null;
        $this->menuCategoryId = FnbCategory::orderBy('sort_order')->value('id');
        $this->menuName = '';
        $this->menuDescription = '';
        $this->menuPhotoUpload = null;
        $this->existingMenuPhotoPath = null;
        $this->removeMenuPhoto = false;
        $this->menuBasePrice = 0;
        $this->menuStationId = '';
        $this->menuIsAvailable = true;
        $this->menuModifierGroupIds = [];
        $this->showMenuModal = true;
    }

    public function openEditMenuModal(string $menuId): void
    {
        $menu = FnbMenu::find($menuId);
        if (! $menu) {
            Notification::make()->title('Menu tidak ditemukan')->danger()->send();

            return;
        }

        $this->editingMenuId = $menu->id;
        $this->menuCategoryId = $menu->category_id;
        $this->menuName = (string) $menu->name;
        $this->menuDescription = (string) $menu->description;
        $this->menuPhotoUpload = null;
        $this->existingMenuPhotoPath = $menu->image_url;
        $this->removeMenuPhoto = false;
        $this->menuBasePrice = (float) $menu->base_price;
        $this->menuStationId = (string) $menu->station_id;
        $this->menuIsAvailable = (bool) $menu->is_available;
        $this->menuModifierGroupIds = $menu->modifierGroups()->pluck('fnb_modifier_groups.id')->all();
        $this->showMenuModal = true;
    }

    public function closeMenuModal(): void
    {
        $this->showMenuModal = false;
        $this->editingMenuId = null;
        $this->menuPhotoUpload = null;
    }

    public function removeMenuPhotoNow(): void
    {
        $this->removeMenuPhoto = true;
        $this->existingMenuPhotoPath = null;
    }

    public function saveMenu(): void
    {
        $this->authorizeManage();

        $this->validate([
            'menuCategoryId' => ['required', 'exists:fnb_categories,id'],
            'menuName' => ['required', 'string', 'max:100'],
            'menuDescription' => ['nullable', 'string', 'max:1000'],
            'menuBasePrice' => ['required', 'numeric', 'min:0'],
            'menuStationId' => ['nullable', 'exists:fnb_stations,id'],
            'menuPhotoUpload' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], [
            'menuCategoryId.required' => 'Kategori wajib dipilih.',
            'menuName.required' => 'Nama menu wajib diisi.',
            'menuBasePrice.required' => 'Harga dasar wajib diisi.',
        ]);

        // Foto SELALU diproses ulang lewat SecureImageUploader (GD re-encode, buang
        // EXIF/metadata, resize) — staf tidak pernah bisa menempelkan URL gambar eksternal
        // bebas, cuma upload file yang divalidasi dari isinya.
        $photoPath = $this->existingMenuPhotoPath;
        if ($this->menuPhotoUpload) {
            $photoPath = SecureImageUploader::store($this->menuPhotoUpload, 'fnb/menu');
        } elseif ($this->removeMenuPhoto) {
            $photoPath = null;
        }

        $data = [
            'category_id' => $this->menuCategoryId,
            'name' => trim($this->menuName),
            'description' => trim($this->menuDescription) ?: null,
            'image_url' => $photoPath,
            'base_price' => max(0, (float) $this->menuBasePrice),
            'station_id' => $this->menuStationId ?: null,
            'is_available' => $this->menuIsAvailable,
        ];

        if ($this->editingMenuId) {
            $menu = FnbMenu::find($this->editingMenuId);
            if (! $menu) {
                Notification::make()->title('Menu tidak ditemukan')->danger()->send();

                return;
            }

            // Event model FnbMenu::booted() otomatis membuang foto lama dari disk kalau
            // image_url berubah — tidak perlu diulang manual di sini.
            $menu->update($data);
            Notification::make()->title('Menu Diperbarui')->success()->send();
        } else {
            $menu = FnbMenu::create($data);
            Notification::make()->title('Menu Ditambahkan')->success()->send();
        }

        $menu->modifierGroups()->sync($this->menuModifierGroupIds);

        $this->closeMenuModal();
    }

    public function deleteMenu(string $menuId): void
    {
        $this->authorizeManage();

        $menu = FnbMenu::find($menuId);
        if (! $menu) {
            Notification::make()->title('Menu tidak ditemukan')->danger()->send();

            return;
        }

        $menu->delete();
        Notification::make()->title('Menu Dihapus')->success()->send();
    }

    public function getMenusProperty(): Collection
    {
        $query = FnbMenu::query()->with(['category', 'modifierGroups', 'station']);

        if ($this->menuCategoryFilter !== 'ALL') {
            $query->where('category_id', $this->menuCategoryFilter);
        }

        if (trim($this->menuSearch) !== '') {
            $query->where('name', 'like', '%'.trim($this->menuSearch).'%');
        }

        return $query->orderBy('name')->get();
    }

    // ==========================================
    // TAB 3: GRUP TAMBAHAN / MODIFIER
    // ==========================================

    public function openCreateModifierGroupModal(): void
    {
        $this->editingModifierGroupId = null;
        $this->modifierGroupName = '';
        $this->modifierGroupIsRequired = false;
        $this->modifierGroupMaxSelection = 1;
        $this->modifierOptions = [['id' => null, 'name' => '', 'extra_price' => 0]];
        $this->showModifierGroupModal = true;
    }

    public function openEditModifierGroupModal(string $groupId): void
    {
        $group = FnbModifierGroup::with('options')->find($groupId);
        if (! $group) {
            Notification::make()->title('Grup tidak ditemukan')->danger()->send();

            return;
        }

        $this->editingModifierGroupId = $group->id;
        $this->modifierGroupName = (string) $group->name;
        $this->modifierGroupIsRequired = (bool) $group->is_required;
        $this->modifierGroupMaxSelection = $group->max_selection;
        $this->modifierOptions = $group->options->map(fn ($option) => [
            'id' => $option->id,
            'name' => $option->name,
            'extra_price' => (float) $option->extra_price,
        ])->all();

        if (empty($this->modifierOptions)) {
            $this->modifierOptions = [['id' => null, 'name' => '', 'extra_price' => 0]];
        }

        $this->showModifierGroupModal = true;
    }

    public function closeModifierGroupModal(): void
    {
        $this->showModifierGroupModal = false;
        $this->editingModifierGroupId = null;
    }

    public function addModifierOptionRow(): void
    {
        $this->modifierOptions[] = ['id' => null, 'name' => '', 'extra_price' => 0];
    }

    public function removeModifierOptionRow(int $index): void
    {
        unset($this->modifierOptions[$index]);
        $this->modifierOptions = array_values($this->modifierOptions);
    }

    public function saveModifierGroup(): void
    {
        $this->authorizeManage();

        $this->validate([
            'modifierGroupName' => ['required', 'string', 'max:50'],
            'modifierGroupMaxSelection' => ['required', 'integer', 'min:1'],
            'modifierOptions.*.name' => ['required', 'string', 'max:50'],
            'modifierOptions.*.extra_price' => ['required', 'numeric', 'min:0'],
        ], [
            'modifierGroupName.required' => 'Nama grup wajib diisi.',
            'modifierOptions.*.name.required' => 'Nama opsi wajib diisi.',
        ]);

        if ($this->editingModifierGroupId) {
            $group = FnbModifierGroup::find($this->editingModifierGroupId);
            if (! $group) {
                Notification::make()->title('Grup tidak ditemukan')->danger()->send();

                return;
            }
        } else {
            $group = new FnbModifierGroup();
        }

        $group->name = trim($this->modifierGroupName);
        $group->is_required = $this->modifierGroupIsRequired;
        $group->max_selection = (int) $this->modifierGroupMaxSelection;
        $group->save();

        $keptOptionIds = [];
        foreach ($this->modifierOptions as $option) {
            $name = trim((string) ($option['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $payload = [
                'group_id' => $group->id,
                'name' => $name,
                'extra_price' => max(0, (float) ($option['extra_price'] ?? 0)),
            ];

            // "id" sengaja BUKAN kolom fillable di FnbModifierOption (lihat modelnya) — kalau
            // pakai updateOrCreate(['id' => $optionId], ...) untuk baris baru, ID custom yang
            // kita tentukan tidak pernah benar-benar ter-set (mass-assignment guard membuang
            // key "id" itu diam-diam), dan HasUlids menghasilkan ID lain sendiri. Akibatnya
            // $keptOptionIds berisi ID yang salah, dan whereNotIn() di bawah malah balik
            // menghapus baris yang baru saja dibuat. Solusinya: baris baru selalu lewat
            // create() biasa dan ambil ID ASLI dari hasilnya, bukan ditebak di muka.
            if (! empty($option['id'])) {
                FnbModifierOption::where('id', $option['id'])->update($payload);
                $keptOptionIds[] = $option['id'];
            } else {
                $created = FnbModifierOption::create($payload);
                $keptOptionIds[] = $created->id;
            }
        }

        // Buang opsi yang dihapus dari form (bukan dipertahankan diam-diam di database)
        $group->options()->whereNotIn('id', $keptOptionIds)->delete();

        Notification::make()
            ->title($this->editingModifierGroupId ? 'Grup Tambahan Diperbarui' : 'Grup Tambahan Ditambahkan')
            ->success()
            ->send();

        $this->closeModifierGroupModal();
    }

    public function deleteModifierGroup(string $groupId): void
    {
        $this->authorizeManage();

        $group = FnbModifierGroup::find($groupId);
        if (! $group) {
            Notification::make()->title('Grup tidak ditemukan')->danger()->send();

            return;
        }

        // cascadeOnDelete di fnb_modifier_options.group_id & fnb_menu_modifier_group.group_id
        // membuang opsi & keterkaitan ke menu secara atomic di level DB.
        $group->delete();
        Notification::make()->title('Grup Tambahan Dihapus')->success()->send();
    }

    public function getModifierGroupsProperty(): Collection
    {
        return FnbModifierGroup::withCount(['options', 'menus'])->orderBy('name')->get();
    }

    // ==========================================
    // TAB 4: STASIUN & PRINTER (Kitchen, Bar, ...)
    // ==========================================

    public function openCreateStationModal(): void
    {
        $this->editingStationId = null;
        $this->stationName = '';
        $this->stationPrinterHost = '';
        $this->stationPrinterPort = 9100;
        $this->stationIsActive = true;
        $this->resetValidation();
        $this->showStationModal = true;
    }

    public function openEditStationModal(string $stationId): void
    {
        $station = FnbStation::find($stationId);
        if (! $station) {
            Notification::make()->title('Stasiun tidak ditemukan')->danger()->send();

            return;
        }

        $this->editingStationId = $station->id;
        $this->stationName = (string) $station->name;
        $this->stationPrinterHost = (string) $station->printer_host;
        $this->stationPrinterPort = $station->printer_port;
        $this->stationIsActive = (bool) $station->is_active;
        $this->resetValidation();
        $this->showStationModal = true;
    }

    public function closeStationModal(): void
    {
        $this->showStationModal = false;
        $this->editingStationId = null;
    }

    public function saveStation(): void
    {
        $this->authorizeManage();

        $this->stationName = trim($this->stationName);
        $this->stationPrinterHost = trim($this->stationPrinterHost);

        $this->validate([
            'stationName' => ['required', 'string', 'max:50', \Illuminate\Validation\Rule::unique('fnb_stations', 'name')->ignore($this->editingStationId)],
            // IP printer LAN (mis. 192.168.1.50) atau nama host lokal — tanpa skema / path.
            'stationPrinterHost' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9](?:[A-Za-z0-9.\-]*[A-Za-z0-9])?$/'],
            'stationPrinterPort' => ['required', 'integer', 'min:1', 'max:65535'],
        ], [
            'stationName.required' => 'Nama stasiun wajib diisi.',
            'stationName.unique' => 'Nama stasiun sudah dipakai.',
            'stationPrinterHost.regex' => 'Isi IP printer saja, contoh: 192.168.1.50',
            'stationPrinterPort.required' => 'Port printer wajib diisi (umumnya 9100).',
        ]);

        $data = [
            'name' => $this->stationName,
            'printer_host' => $this->stationPrinterHost ?: null,
            'printer_port' => (int) $this->stationPrinterPort,
            'is_active' => $this->stationIsActive,
        ];

        if ($this->editingStationId) {
            $station = FnbStation::find($this->editingStationId);
            if (! $station) {
                Notification::make()->title('Stasiun tidak ditemukan')->danger()->send();

                return;
            }

            $station->update($data);
            Notification::make()->title('Stasiun Diperbarui')->success()->send();
        } else {
            FnbStation::create($data + ['sort_order' => (int) (FnbStation::max('sort_order') ?? 0) + 1]);
            Notification::make()->title('Stasiun Ditambahkan')->success()->send();
        }

        $this->closeStationModal();
    }

    /** Menu stasiun yang dihapus kembali dibuat di kasir (tanpa slip); riwayat slip lama tetap tersimpan. */
    public function deleteStation(string $stationId): void
    {
        $this->authorizeManage();

        DB::transaction(function () use ($stationId) {
            $station = FnbStation::query()->lockForUpdate()->find($stationId);
            if (! $station) {
                return;
            }

            $moved = FnbMenu::where('station_id', $station->id)->update(['station_id' => null]);
            $station->delete();

            Notification::make()
                ->title('Stasiun Dihapus')
                ->body($moved > 0 ? "{$moved} menu dipindah ke ".FnbStation::CASHIER_LABEL.'.' : null)
                ->success()
                ->send();
        });
    }

    public function getStationsProperty(): Collection
    {
        return FnbStation::withCount('menus')->orderBy('sort_order')->orderBy('name')->get();
    }

    protected function authorizeManage(): void
    {
        abort_unless(
            auth()->user() && auth()->user()->can('manage_fnb_menu'),
            403,
            'Akses ditolak: Anda tidak memiliki izin [manage_fnb_menu] untuk mengelola menu F&B.'
        );
    }
}
