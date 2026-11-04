<?php

namespace App\Filament\Pages;

use App\Models\Padel\CourtEquipment;
use App\Models\Padel\PadelBookingEquipment;
use App\Models\Padel\PadelCourt;
use App\Models\Padel\PadelHoliday;
use App\Models\Padel\PadelPeakHourRule;
use App\Models\Pos\OrderItem;
use App\Services\Audit\ActivityLogger;
use App\Services\Padel\PeakHourService;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class MasterData extends Page
{
    use HasPageShield;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?string $navigationLabel = 'Master Data & Tarif';

    protected static string | UnitEnum | null $navigationGroup = 'Master Data';

    protected static ?string $title = 'Master Data, Tarif & Add-ons';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.master-data';

    // Tab Aktif: 'courts' atau 'equipments'
    public string $activeTab = 'courts';

    // State Modal Lapangan
    public bool $showCourtModal = false;
    public ?string $editingCourtId = null;
    public string $courtName = '';
    public string $courtType = 'INDOOR';
    public ?string $courtDescription = '';
    public int|float|string|null $hourlyRateRegular = 300000;
    public int|float|string|null $hourlyRatePrime = 450000;
    public string $courtOpenTime = '06:00';
    public string $courtCloseTime = '23:00';
    public bool $courtIsActive = true;
    // Urutan tampil lapangan di seluruh sistem (kecil dulu; sama → abjad nama).
    public int $courtSortOrder = 0;

    // State Modal Atur Jam Operasional Massal (Seluruh Lapangan Sekaligus)
    public bool $showOperatingHoursModal = false;
    public string $bulkOpenTime = '06:00';
    public string $bulkCloseTime = '23:00';

    // State Modal Add-on / Equipment
    public bool $showEquipmentModal = false;
    public ?string $editingEquipmentId = null;
    public string $equipmentName = '';
    public string $equipmentType = 'RACKET';
    // Sifat alat: SALE (Jual) / RENTAL (Sewa) / '' (tanpa stok, mis. jasa). Lihat CourtEquipment::usageOf().
    public string $equipmentUsage = CourtEquipment::USAGE_RENTAL;
    public int|float|string|null $equipmentRentalPrice = 50000;
    public int|float|string|null $equipmentStock = 20;
    // Denda alat Sewa (Peminjaman Alat): lecet karena disengaja / patah / hilang. Kosong = tanpa denda.
    public int|float|string|null $equipmentFineMinor = null;
    public int|float|string|null $equipmentFineMajor = null;
    public int|float|string|null $equipmentFineLost = null;
    public bool $equipmentIsActive = true;

    // Filter status tampilan Add-ons ('ALL', 'ACTIVE', 'INACTIVE')
    public string $equipmentFilter = 'ALL';

    // Tab Jam Peak & Libur: grid jam peak diedit di browser (Alpine) lalu dikirim utuh ke savePeakGrid().
    public string $holidayDate = '';

    public string $holidayName = '';

    public const DAY_LABELS = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 0 => 'Minggu'];

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['courts', 'equipments', 'peak_hours']) ? $tab : 'courts';
    }

    public function setEquipmentFilter(string $filter): void
    {
        $this->equipmentFilter = in_array($filter, ['ALL', 'ACTIVE', 'INACTIVE']) ? $filter : 'ALL';
    }

    // ==========================================
    // LOGIKA TAB 1: LAPANGAN & TARIF
    // ==========================================

    public function openCreateCourtModal(): void
    {
        $this->authorizeCourtManagement();

        $this->editingCourtId = null;
        $this->courtName = '';
        $this->courtType = 'INDOOR';
        $this->courtDescription = '';
        $this->hourlyRateRegular = 300000;
        $this->hourlyRatePrime = 450000;
        $this->courtOpenTime = '06:00';
        $this->courtCloseTime = '23:00';
        $this->courtIsActive = true;
        // Lapangan baru tampil paling akhir.
        $this->courtSortOrder = min(999, (int) PadelCourt::max('sort_order') + 1);
        $this->showCourtModal = true;
    }

    public function openEditCourtModal(string $courtId): void
    {
        $this->authorizeCourtManagement();

        $court = PadelCourt::find($courtId);
        if (! $court) {
            Notification::make()->title('Lapangan tidak ditemukan')->danger()->send();
            return;
        }

        $this->editingCourtId = $court->id;
        $this->courtName = (string) $court->name;
        // Semua lapangan Club 61 indoor (30 Okt 2026) — lapangan lama bertipe OUTDOOR ikut jadi INDOOR saat disimpan.
        $this->courtType = 'INDOOR';
        $this->courtDescription = (string) ($court->description ?: 'Indoor');
        $this->hourlyRateRegular = (float) $court->hourly_rate_regular;
        $this->hourlyRatePrime = (float) $court->hourly_rate_prime;
        $this->courtOpenTime = (string) ($court->open_time ?: '06:00');
        $this->courtCloseTime = (string) ($court->close_time ?: '23:00');
        $this->courtIsActive = (bool) $court->is_active;
        $this->courtSortOrder = (int) $court->sort_order;
        $this->showCourtModal = true;
    }

    public function closeCourtModal(): void
    {
        $this->showCourtModal = false;
        $this->editingCourtId = null;
        $this->courtDescription = '';
    }

    public function openOperatingHoursModal(): void
    {
        $this->authorizeCourtManagement();

        $firstCourt = PadelCourt::first();
        $this->bulkOpenTime = (string) ($firstCourt?->open_time ?: '06:00');
        $this->bulkCloseTime = (string) ($firstCourt?->close_time ?: '23:00');
        $this->showOperatingHoursModal = true;
    }

    public function closeOperatingHoursModal(): void
    {
        $this->showOperatingHoursModal = false;
    }

    public function saveOperatingHoursAllCourts(): void
    {
        $this->authorizeCourtManagement();

        $this->validate([
            'bulkOpenTime' => ['required', 'string'],
            'bulkCloseTime' => ['required', 'string'],
        ], [
            'bulkOpenTime.required' => 'Jam buka wajib dipilih.',
            'bulkCloseTime.required' => 'Jam tutup wajib dipilih.',
        ]);

        $openHour = (int) substr($this->bulkOpenTime, 0, 2);
        $closeHour = ($this->bulkCloseTime === '00:00' || $this->bulkCloseTime === '24:00') ? 24 : (int) substr($this->bulkCloseTime, 0, 2);

        if ($openHour >= $closeHour) {
            $this->addError('bulkCloseTime', 'Jam tutup harus lebih malam dari jam buka.');
            return;
        }

        PadelCourt::query()->update([
            'open_time' => $this->bulkOpenTime,
            'close_time' => $this->bulkCloseTime,
        ]);

        $this->closeOperatingHoursModal();

        Notification::make()
            ->title('Jam Operasional Berhasil Disinkronkan')
            ->body("Seluruh lapangan kini diset buka jam {$this->bulkOpenTime} WIB dan tutup jam {$this->bulkCloseTime} WIB. Jadwal booking pelanggan langsung mengikuti perubahan ini.")
            ->success()
            ->send();
    }

    public function saveCourt(): void
    {
        $this->authorizeCourtManagement();

        $this->validate([
            'courtName' => ['required', 'string', 'max:50'],
            'courtType' => ['required', 'in:INDOOR'],
            'courtDescription' => ['nullable', 'string', 'max:100'],
            'hourlyRateRegular' => ['required', 'numeric', 'min:0'],
            'hourlyRatePrime' => ['required', 'numeric', 'min:0'],
            'courtOpenTime' => ['required', 'string'],
            'courtCloseTime' => ['required', 'string'],
            'courtSortOrder' => ['required', 'integer', 'min:0', 'max:999'],
        ], [
            'courtName.required' => 'Nama lapangan wajib diisi.',
            'hourlyRateRegular.required' => 'Tarif reguler wajib diisi.',
            'hourlyRatePrime.required' => 'Tarif prime time wajib diisi.',
            'courtOpenTime.required' => 'Jam buka lapangan wajib diisi.',
            'courtCloseTime.required' => 'Jam tutup lapangan wajib diisi.',
            'courtSortOrder.required' => 'Urutan tampil wajib diisi.',
            'courtSortOrder.integer' => 'Urutan tampil harus berupa angka bulat.',
            'courtSortOrder.min' => 'Urutan tampil minimal 0.',
            'courtSortOrder.max' => 'Urutan tampil maksimal 999.',
        ]);

        $openHour = (int) substr($this->courtOpenTime, 0, 2);
        $closeHour = ($this->courtCloseTime === '00:00' || $this->courtCloseTime === '24:00') ? 24 : (int) substr($this->courtCloseTime, 0, 2);

        if ($openHour >= $closeHour) {
            $this->addError('courtCloseTime', 'Jam tutup harus lebih malam dari jam buka.');
            return;
        }

        $regular = max(0, (float) ($this->hourlyRateRegular ?: 0));
        $prime = max(0, (float) ($this->hourlyRatePrime ?: 0));

        if ($this->editingCourtId) {
            $court = PadelCourt::find($this->editingCourtId);
            if (! $court) {
                Notification::make()->title('Lapangan tidak ditemukan')->danger()->send();
                return;
            }

            $court->update([
                'name' => trim($this->courtName),
                'type' => $this->courtType,
                'description' => trim($this->courtDescription ?? '') ?: null,
                'hourly_rate_regular' => $regular,
                'hourly_rate_prime' => $prime,
                'open_time' => $this->courtOpenTime,
                'close_time' => $this->courtCloseTime,
                'is_active' => $this->courtIsActive,
                'sort_order' => $this->courtSortOrder,
            ]);

            Notification::make()
                ->title('Tarif Lapangan Berhasil Diperbarui')
                ->body("Konfigurasi untuk {$court->name} (Jam Operasional: {$court->open_time} - {$court->close_time} WIB) telah aktif dan tersinkronisasi ke seluruh jadwal.")
                ->success()
                ->send();
        } else {
            $court = PadelCourt::create([
                'name' => trim($this->courtName),
                'type' => $this->courtType,
                'description' => trim($this->courtDescription ?? '') ?: null,
                'hourly_rate_regular' => $regular,
                'hourly_rate_prime' => $prime,
                'open_time' => $this->courtOpenTime,
                'close_time' => $this->courtCloseTime,
                'is_active' => $this->courtIsActive,
                'sort_order' => $this->courtSortOrder,
            ]);

            Notification::make()
                ->title('Lapangan Baru Berhasil Ditambahkan')
                ->body("Lapangan {$court->name} telah tersedia di sistem.")
                ->success()
                ->send();
        }

        $this->closeCourtModal();
    }

    public function toggleCourtStatus(string $courtId): void
    {
        $this->authorizeCourtManagement();

        $court = PadelCourt::find($courtId);
        if (! $court) {
            Notification::make()->title('Lapangan tidak ditemukan')->danger()->send();
            return;
        }

        $court->is_active = ! $court->is_active;
        $court->save();

        $statusText = $court->is_active ? 'diaktifkan' : 'dinonaktifkan';
        Notification::make()
            ->title("Status Lapangan Diperbarui")
            ->body("Lapangan {$court->name} kini telah {$statusText}.")
            ->success()
            ->send();
    }

    // ==========================================
    // LOGIKA TAB 2: PERLENGKAPAN PADEL
    // ==========================================

    public function openCreateEquipmentModal(): void
    {
        $this->authorizeEquipmentManagement();

        $this->editingEquipmentId = null;
        $this->equipmentName = '';
        $this->equipmentType = 'RACKET';
        $this->equipmentUsage = CourtEquipment::USAGE_RENTAL;
        $this->equipmentRentalPrice = 50000;
        $this->equipmentStock = 20;
        $this->equipmentFineMinor = null;
        $this->equipmentFineMajor = null;
        $this->equipmentFineLost = null;
        $this->equipmentIsActive = true;
        $this->showEquipmentModal = true;
    }

    public function openEditEquipmentModal(string $equipmentId): void
    {
        $this->authorizeEquipmentManagement();

        $equipment = CourtEquipment::find($equipmentId);
        if (! $equipment) {
            Notification::make()->title('Perlengkapan tidak ditemukan')->danger()->send();
            return;
        }

        $this->editingEquipmentId = $equipment->id;
        $this->equipmentName = (string) $equipment->name;
        $this->equipmentType = (string) ($equipment->type ?: 'RACKET');
        $this->equipmentUsage = (string) $equipment->resolvedUsage();
        $this->equipmentRentalPrice = (float) $equipment->rental_price;
        $this->equipmentStock = (int) $equipment->stock_quantity;
        $this->equipmentFineMinor = $equipment->fine_minor !== null ? (float) $equipment->fine_minor : null;
        $this->equipmentFineMajor = $equipment->fine_major !== null ? (float) $equipment->fine_major : null;
        $this->equipmentFineLost = $equipment->fine_lost !== null ? (float) $equipment->fine_lost : null;
        $this->equipmentIsActive = (bool) $equipment->is_active;
        $this->showEquipmentModal = true;
    }

    public function closeEquipmentModal(): void
    {
        $this->showEquipmentModal = false;
        $this->editingEquipmentId = null;
    }

    public function saveEquipment(): void
    {
        $this->authorizeEquipmentManagement();

        $this->validate([
            'equipmentName' => ['required', 'string', 'max:100'],
            'equipmentType' => ['required', 'in:RACKET,BALL,TOWEL,COACH,OTHER'],
            'equipmentUsage' => ['nullable', 'in:'.CourtEquipment::USAGE_SALE.','.CourtEquipment::USAGE_RENTAL],
            'equipmentRentalPrice' => ['required', 'numeric', 'min:0'],
            'equipmentStock' => ['required', 'integer', 'min:0'],
            'equipmentFineMinor' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'equipmentFineMajor' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'equipmentFineLost' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
        ], [
            'equipmentName.required' => 'Nama add-on wajib diisi.',
            'equipmentRentalPrice.required' => 'Tarif sewa wajib diisi.',
            'equipmentStock.required' => 'Jumlah stok wajib diisi.',
        ]);

        $price = max(0, (float) ($this->equipmentRentalPrice ?: 0));
        $stock = max(0, (int) ($this->equipmentStock ?: 0));
        $usage = $this->equipmentUsage !== '' ? $this->equipmentUsage : null;
        $fine = fn ($value) => $value === null || $value === '' ? null : max(0, (float) $value);
        $fines = [
            'fine_minor' => $fine($this->equipmentFineMinor),
            'fine_major' => $fine($this->equipmentFineMajor),
            'fine_lost' => $fine($this->equipmentFineLost),
        ];

        if ($this->editingEquipmentId) {
            $equipment = CourtEquipment::find($this->editingEquipmentId);
            if (! $equipment) {
                Notification::make()->title('Perlengkapan tidak ditemukan')->danger()->send();
                return;
            }

            // Sifat (atau jenis yang menentukannya) berubah selagi alat dipakai booking aktif → stok booking itu salah
            // hitung (mis. bola yang sudah dipotong saat bayar ikut "dikembalikan" sebagai alat sewa). Dikunci.
            $usageChanges = CourtEquipment::usageOf($usage, $this->equipmentType) !== $equipment->resolvedUsage();
            if ($usageChanges && $this->equipmentInUseByActiveBookings($equipment)) {
                Notification::make()->title('Sifat Alat Belum Bisa Diubah')
                    ->body("{$equipment->name} masih dipakai booking yang belum selesai (atau sewanya belum dikembalikan). Ubah sifat Jual / Sewa setelah booking tersebut selesai.")
                    ->warning()->persistent()->send();
                return;
            }

            $equipment->update([
                'name' => trim($this->equipmentName),
                'type' => $this->equipmentType,
                'usage' => $usage,
                'rental_price' => $price,
                'stock_quantity' => $stock,
                'is_active' => $this->equipmentIsActive,
            ] + $fines);

            Notification::make()
                ->title('Perlengkapan Berhasil Diperbarui')
                ->body("Data {$equipment->name} telah diperbarui di katalog perlengkapan.")
                ->success()
                ->send();
        } else {
            $equipment = CourtEquipment::create([
                'name' => trim($this->equipmentName),
                'type' => $this->equipmentType,
                'usage' => $usage,
                'rental_price' => $price,
                'stock_quantity' => $stock,
                'is_active' => $this->equipmentIsActive,
            ] + $fines);

            Notification::make()
                ->title('Perlengkapan Baru Berhasil Ditambahkan')
                ->body("Item {$equipment->name} telah masuk katalog perlengkapan.")
                ->success()
                ->send();
        }

        $this->closeEquipmentModal();
    }

    public function toggleEquipmentStatus(string $equipmentId): void
    {
        $this->authorizeEquipmentManagement();

        $equipment = CourtEquipment::find($equipmentId);
        if (! $equipment) {
            Notification::make()->title('Perlengkapan tidak ditemukan')->danger()->send();
            return;
        }

        $equipment->is_active = ! $equipment->is_active;
        $equipment->save();

        $statusText = $equipment->is_active ? 'diaktifkan' : 'dinonaktifkan';
        Notification::make()
            ->title("Status Perlengkapan Diperbarui")
            ->body("Item {$equipment->name} kini telah {$statusText}.")
            ->success()
            ->send();
    }

    /**
     * Hapus Pintar dengan Proteksi Integritas Transaksi Relasional (ACID Safe).
     * Jika item sudah pernah disewa, item hanya dinonaktifkan (is_active = false)
     * agar riwayat invoice masa lalu tidak terhapus oleh foreign key cascadeOnDelete.
     */
    public function deleteEquipment(string $equipmentId): void
    {
        $this->authorizeEquipmentManagement();

        $actionTaken = DB::transaction(function () use ($equipmentId) {
            $equipment = CourtEquipment::where('id', $equipmentId)->lockForUpdate()->first();
            if (! $equipment) {
                return 'NOT_FOUND';
            }

            $hasHistory = $this->equipmentIdsWithHistory(collect([$equipmentId]))->isNotEmpty();
            if ($hasHistory) {
                $equipment->update(['is_active' => false]);
                return 'DEACTIVATED';
            } else {
                $equipment->delete();
                return 'DELETED';
            }
        });

        if ($actionTaken === 'NOT_FOUND') {
            Notification::make()->title('Perlengkapan tidak ditemukan')->danger()->send();
        } elseif ($actionTaken === 'DEACTIVATED') {
            Notification::make()
                ->title('Perlengkapan Dinonaktifkan (Proteksi Integritas Data)')
                ->body('Item ini memiliki riwayat transaksi, sehingga otomatis dialihkan ke status NONAKTIF agar seluruh struk dan invoice historis tetap utuh.')
                ->warning()
                ->send();
        } else {
            Notification::make()
                ->title('Perlengkapan Berhasil Dihapus Permanen')
                ->body('Item belum pernah dipakai transaksi apa pun sehingga telah dihapus dari sistem.')
                ->success()
                ->send();
        }
    }

    // ==========================================
    // LOGIKA TAB 3: JAM PEAK (PRIME TIME) & TANGGAL MERAH
    // Berlaku untuk SEMUA lapangan; dipakai grid POS, booking customer, checkout & selisih reschedule
    // lewat PeakHourService. Booking yang sudah dibayar tidak berubah (nominalnya sudah tersimpan).
    // ==========================================

    /**
     * Grid jam peak untuk editor: [hari => [24 boolean]] (true = jam peak). Hari 0 = Minggu ... 6 = Sabtu.
     *
     * @return array<int, array<int, bool>>
     */
    public function getPeakGridProperty(): array
    {
        $rules = app(PeakHourService::class)->rules();
        $grid = [];
        foreach (array_keys(self::DAY_LABELS) as $day) {
            $grid[$day] = array_fill(0, 24, false);
            foreach ($rules[$day] ?? [] as [$start, $end]) {
                for ($h = $start; $h < min(24, $end); $h++) {
                    $grid[$day][$h] = true;
                }
            }
        }

        return $grid;
    }

    /** Rentang jam yang ditampilkan di editor = jam operasional lapangan aktif (jam di luar itu tetap tersimpan). */
    public function getPeakEditorHoursProperty(): array
    {
        $courts = PadelCourt::where('is_active', true)->get();
        $open = (int) ($courts->min(fn ($c) => (int) substr($c->open_time ?: '06:00', 0, 2)) ?? 6);
        $close = (int) ($courts->max(fn ($c) => in_array($c->close_time, ['00:00', '24:00'], true) ? 24 : (int) substr($c->close_time ?: '23:00', 0, 2)) ?? 24);

        return $open < $close ? range($open, $close - 1) : range(0, 23);
    }

    /** Contoh tarif untuk keterangan warna di editor (rentang tarif seluruh lapangan aktif). */
    public function getPeakRateExampleProperty(): array
    {
        $courts = PadelCourt::where('is_active', true)->get();
        $fmt = function ($min, $max) {
            $label = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');

            return $min == $max ? $label($min) : $label($min).' – '.$label($max);
        };

        return $courts->isEmpty() ? ['regular' => null, 'prime' => null] : [
            'regular' => $fmt($courts->min('hourly_rate_regular'), $courts->max('hourly_rate_regular')),
            'prime' => $fmt($courts->min('hourly_rate_prime'), $courts->max('hourly_rate_prime')),
        ];
    }

    /**
     * Simpan grid jam peak dari editor. Grid datang dari browser → divalidasi ulang di sini; jam yang ditandai
     * peak secara berurutan digabung jadi rentang [mulai, selesai).
     *
     * @param  array<int|string, mixed>  $grid
     */
    public function savePeakGrid(array $grid): bool
    {
        $this->authorizeCourtManagement();

        $parsed = [];
        foreach (array_keys(self::DAY_LABELS) as $day) {
            $hours = $grid[$day] ?? $grid[(string) $day] ?? null;
            if (! is_array($hours) || count($hours) !== 24) {
                Notification::make()->title('Gagal Menyimpan')->body('Data jam peak tidak lengkap. Muat ulang halaman lalu coba lagi.')->danger()->send();

                return false;
            }

            $ranges = [];
            $start = null;
            foreach (array_values($hours) as $hour => $isPeak) {
                $isPeak = filter_var($isPeak, FILTER_VALIDATE_BOOLEAN);
                if ($isPeak && $start === null) {
                    $start = $hour;
                } elseif (! $isPeak && $start !== null) {
                    $ranges[] = [$start, $hour];
                    $start = null;
                }
            }
            if ($start !== null) {
                $ranges[] = [$start, 24];
            }
            $parsed[$day] = $ranges;
        }

        $before = $this->peakSummary(app(PeakHourService::class)->rules());

        DB::transaction(function () use ($parsed) {
            PadelPeakHourRule::query()->delete();
            foreach ($parsed as $day => $ranges) {
                foreach ($ranges as [$start, $end]) {
                    PadelPeakHourRule::create(['day_of_week' => $day, 'start_hour' => $start, 'end_hour' => $end]);
                }
            }
        });
        app(PeakHourService::class)->flush();

        $after = $this->peakSummary($parsed);
        if ($before !== $after) {
            ActivityLogger::record(
                module: 'MASTER_DATA',
                event: 'peak_hours.updated',
                description: 'Mengubah jam peak (prime time) padel untuk semua lapangan',
                changes: collect($after)->mapWithKeys(fn ($v, $day) => [$day => ['old' => $before[$day] ?? '-', 'new' => $v]])
                    ->filter(fn ($c) => $c['old'] !== $c['new'])->all(),
                severity: ActivityLogger::WARNING,
            );
        }

        Notification::make()
            ->title('Jam Peak Tersimpan')
            ->body('Harga slot baru di POS, booking online, dan selisih reschedule langsung mengikuti jam peak ini. Booking yang sudah dibayar tidak berubah.')
            ->success()
            ->send();

        return true;
    }

    public function addHoliday(): void
    {
        $this->authorizeCourtManagement();

        $this->validate([
            'holidayDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'unique:padel_holidays,date'],
            'holidayName' => ['required', 'string', 'max:100'],
        ], [
            'holidayDate.required' => 'Tanggal wajib diisi.',
            'holidayDate.after_or_equal' => 'Tanggal merah tidak boleh di masa lampau.',
            'holidayDate.unique' => 'Tanggal ini sudah ada di daftar.',
            'holidayName.required' => 'Nama hari libur wajib diisi (contoh: Hari Raya Natal).',
        ]);

        $holiday = PadelHoliday::create(['date' => $this->holidayDate, 'name' => trim($this->holidayName)]);
        app(PeakHourService::class)->flush();

        ActivityLogger::record(
            module: 'MASTER_DATA',
            event: 'holiday.created',
            description: "Menambah tanggal merah {$holiday->date->translatedFormat('d M Y')} ({$holiday->name}) — tarif mengikuti jam peak hari Minggu",
            subject: $holiday,
            subjectLabel: $holiday->name,
        );

        $this->holidayDate = '';
        $this->holidayName = '';
        Notification::make()->title('Tanggal Merah Ditambahkan')->body("{$holiday->name} memakai jam peak hari Minggu.")->success()->send();
    }

    public function deleteHoliday(string $holidayId): void
    {
        $this->authorizeCourtManagement();

        $holiday = PadelHoliday::find($holidayId);
        if (! $holiday) {
            return;
        }
        $label = $holiday->date->translatedFormat('d M Y').' ('.$holiday->name.')';
        $holiday->delete();
        app(PeakHourService::class)->flush();

        ActivityLogger::record(
            module: 'MASTER_DATA',
            event: 'holiday.deleted',
            description: "Menghapus tanggal merah {$label}",
            severity: ActivityLogger::WARNING,
        );

        Notification::make()->title('Tanggal Merah Dihapus')->success()->send();
    }

    public function getHolidaysProperty(): Collection
    {
        return PadelHoliday::where('date', '>=', now('Asia/Jakarta')->toDateString())->orderBy('date')->get();
    }

    /** Ringkasan jam peak per hari untuk header tab Lapangan, mis. "Senin: 17:00–24:00". */
    public function getPeakSummaryProperty(): array
    {
        return $this->peakSummary(app(PeakHourService::class)->rules());
    }

    /** @param  array<int, array<int, array{0: int, 1: int}>>  $rules */
    protected function peakSummary(array $rules): array
    {
        $summary = [];
        foreach (self::DAY_LABELS as $day => $label) {
            $ranges = collect($rules[$day] ?? [])->sortBy(0)
                ->map(fn ($r) => sprintf('%02d:00–%02d:00', $r[0], $r[1]))->implode(', ');
            $summary[$label] = $ranges !== '' ? $ranges : 'Reguler seharian';
        }

        return $summary;
    }

    // ==========================================
    // DATA COMPUTED PROPERTIES UNTUK BLADE
    // ==========================================

    public function getCourtsProperty(): Collection
    {
        return PadelCourt::ordered()->get();
    }

    public function getEquipmentsProperty(): Collection
    {
        $query = CourtEquipment::query();

        if ($this->equipmentFilter === 'ACTIVE') {
            $query->where('is_active', true);
        } elseif ($this->equipmentFilter === 'INACTIVE') {
            $query->where('is_active', false);
        }

        $equipments = $query->orderBy('type')->orderBy('name')->get();
        $ids = $equipments->pluck('id');

        // Unit yang sudah lunas: setiap transaksi alat (checkout booking, walk-in, tambah alat, jual langsung di
        // Kasir Pro Shop, tagihan coaching) tercatat sebagai item EQUIPMENT di order.
        $paidUnits = OrderItem::where('item_type', 'EQUIPMENT')
            ->whereIn('reference_id', $ids)
            ->whereHas('order', fn ($q) => $q->where('payment_status', 'PAID'))
            ->selectRaw('reference_id, SUM(quantity) as units')
            ->groupBy('reference_id')
            ->pluck('units', 'reference_id');
        $withHistory = $this->equipmentIdsWithHistory($ids)->flip();

        return $equipments->map(function ($eq) use ($paidUnits, $withHistory) {
            $eq->setAttribute('paid_units', (int) ($paidUnits[$eq->id] ?? 0));
            $eq->setAttribute('has_transaction_history', $withHistory->has($eq->id));
            return $eq;
        });
    }

    /**
     * Alat yang pernah dipakai transaksi — lewat booking maupun order (termasuk jual langsung di Kasir Pro Shop,
     * lunas atau belum). Alat ini tidak boleh dihapus permanen, hanya dinonaktifkan.
     */
    protected function equipmentIdsWithHistory(Collection $ids): Collection
    {
        return PadelBookingEquipment::whereIn('equipment_id', $ids)->distinct()->pluck('equipment_id')
            ->merge(OrderItem::where('item_type', 'EQUIPMENT')->whereIn('reference_id', $ids)->distinct()->pluck('reference_id'))
            ->unique()
            ->values();
    }

    public function getCanManageCourtsProperty(): bool
    {
        return (bool) auth()->user()?->can('manage_court_pricing');
    }

    public function getCanManageEquipmentProperty(): bool
    {
        return (bool) auth()->user()?->can('manage_court_equipment');
    }

    protected function authorizeCourtManagement(): void
    {
        abort_unless(
            $this->canManageCourts,
            403,
            'Akses ditolak: Anda tidak memiliki izin [manage_court_pricing] untuk mengelola lapangan, jam operasional & tarif.'
        );
    }

    /** Booking yang belum selesai, atau alat sewa yang sudah diserahkan tapi belum dikembalikan. */
    protected function equipmentInUseByActiveBookings(CourtEquipment $equipment): bool
    {
        return PadelBookingEquipment::where('equipment_id', $equipment->id)
            ->where(fn ($q) => $q->whereHas('booking', fn ($b) => $b->whereIn('status', ['LOCKED', 'PENDING_PAYMENT', 'PENDING', 'PAID', 'CHECKED_IN']))
                ->orWhere(fn ($open) => $open->whereNotNull('stock_deducted_at')->whereNull('returned_at')))
            ->exists();
    }

    /** Alat baru: sifat mengikuti jenisnya (bola = Jual, raket / handuk = Sewa) sampai admin memilih sendiri. */
    public function updatedEquipmentType(): void
    {
        if (! $this->editingEquipmentId) {
            $this->equipmentUsage = (string) CourtEquipment::usageOf(null, $this->equipmentType);
        }
    }

    protected function authorizeEquipmentManagement(): void
    {
        abort_unless(
            $this->canManageEquipment,
            403,
            'Akses ditolak: Anda tidak memiliki izin [manage_court_equipment] untuk mengelola alat sewa.'
        );
    }
}
