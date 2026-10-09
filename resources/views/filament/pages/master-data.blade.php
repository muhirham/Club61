<div class="c61 md-page">
    @include('filament.partials.c61-admin-style')
    {{-- Gaya khusus halaman Master Data (prefix md- / pk-) di atas design system c61. --}}
    <style>
        .md-tabs-row { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }

        /* Kepala kartu */
        .md-card-head-text { min-width: 0; flex: 1 1 320px; }
        .md-peak-line { font-size: 0.75rem; color: var(--c-muted); margin-top: 0.3rem; line-height: 1.6; }
        .md-peak-line .md-range { white-space: nowrap; font-weight: 700; color: var(--c-brown); }

        /* Tabel tarif */
        .md-table { min-width: 860px; }
        .md-table th:first-child, .md-table td:first-child { padding-left: 1.25rem; }
        .md-table th:last-child, .md-table td:last-child { padding-right: 1.25rem; }
        .md-table .md-center { text-align: center; }
        .md-table .md-right { text-align: right; white-space: nowrap; }
        .md-name { font-weight: 700; font-size: 0.875rem; color: var(--c-brown); }
        .md-desc { font-size: 0.71875rem; color: var(--c-muted); font-weight: 600; margin-top: 0.15rem; }
        .md-hours { display: inline-flex; align-items: center; margin-top: 0.4rem; height: 22px; padding: 0 0.55rem; border-radius: 999px; background: var(--c-paper); border: 1px solid var(--c-line); color: var(--c-brown); font-size: 0.6875rem; font-weight: 700; white-space: nowrap; }
        .md-id { font-size: 0.65625rem; color: var(--c-faint); font-family: var(--font-mono); margin-top: 0.3rem; }
        .md-price { font-family: var(--font-mono); font-weight: 800; font-size: 0.9375rem; color: var(--c-brown); white-space: nowrap; font-variant-numeric: tabular-nums; }
        .md-price.is-prime { color: var(--c-terra); }
        .md-price-sub { font-size: 0.6875rem; color: var(--c-faint); margin-top: 0.1rem; white-space: nowrap; }
        .md-stock { font-family: var(--font-mono); font-weight: 800; font-size: 0.875rem; color: var(--c-brown); white-space: nowrap; }
        .md-stock.is-empty { color: #B42318; }
        .md-muted { font-size: 0.75rem; color: var(--c-faint); }

        /* Switch status (hijau = aktif) */
        .md-toggle { display: inline-flex; align-items: center; gap: 0.5rem; }
        .md-toggle.is-clickable { cursor: pointer; }
        .md-switch { position: relative; width: 40px; height: 22px; background: #E7E0D3; border-radius: 999px; border: 1px solid #D9CFBE; display: inline-block; flex-shrink: 0; transition: background-color 0.2s ease, border-color 0.2s ease; }
        .md-switch.on { background: #16A34A; border-color: #15803D; }
        .md-switch-knob { position: absolute; top: 1px; left: 1px; width: 18px; height: 18px; background: #FFFFFF; border-radius: 50%; box-shadow: 0 1px 3px rgba(42, 20, 16, 0.25); transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1); }
        .md-switch.on .md-switch-knob { transform: translateX(18px); }
        .md-toggle-text { font-size: 0.6875rem; font-weight: 800; letter-spacing: 0.04em; color: var(--c-faint); }
        .md-toggle-text.is-on { color: #047857; }

        /* Tombol aksi teks */
        .md-btn-warn { background: #FEF3C7; border-color: #FDE68A; color: #92400E; }
        .md-btn-warn:hover { background: #FDE68A; }
        .md-btn-del { background: #FEF2F2; border-color: #FECACA; color: #B42318; }
        .md-btn-del:hover { background: #FEE2E2; }
        .md-btn-wrap { white-space: normal; height: auto; min-height: 32px; padding-top: 0.35rem; padding-bottom: 0.35rem; text-align: left; line-height: 1.3; }

        /* Form */
        .md-form { display: flex; flex-direction: column; gap: 1rem; }
        .md-grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
        .md-field { display: flex; flex-direction: column; min-width: 0; }
        .md-field .c61-label { margin-bottom: 0.35rem; }
        .md-err { font-size: 0.71875rem; font-weight: 600; color: #B42318; margin-top: 0.3rem; }
        .md-radio-row { display: flex; align-items: stretch; gap: 0.5rem; flex-wrap: wrap; }
        .md-radio { display: inline-flex; align-items: center; gap: 0.5rem; flex: 1 1 200px; padding: 0.6rem 0.8rem; border: 1px solid var(--c-line); border-radius: 10px; background: var(--c-paper); font-size: 0.8125rem; font-weight: 700; cursor: pointer; }
        .md-radio:hover { border-color: var(--c-terra); }
        .md-radio input { accent-color: var(--c-terra); }
        .md-radio .is-on { color: #047857; }
        .md-radio .is-off { color: var(--c-muted); }
        .md-modal-sub { font-size: 0.75rem; color: rgba(247, 240, 219, 0.78); margin-top: 0.3rem; line-height: 1.45; }

        /* Jam peak */
        .pk-card { overflow: visible; }
        .pk-body { display: flex; flex-direction: column; gap: 1rem; }
        .pk-legend { display: flex; gap: 0.5rem 1.25rem; flex-wrap: wrap; align-items: center; font-size: 0.75rem; color: var(--c-brown); }
        .pk-legend-item { display: inline-flex; align-items: center; gap: 0.45rem; }
        .pk-legend-rate { color: var(--c-muted); }
        .pk-swatch { width: 22px; height: 16px; border-radius: 5px; display: inline-block; }
        .pk-reg { background: #F1EBDD; border: 1px solid var(--c-line); }
        .pk-peak { background: var(--c-terra); border: 1px solid var(--c-terra-dark); }
        .pk-quick { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; padding: 0.75rem; background: var(--c-paper); border: 1px solid var(--c-line-soft); border-radius: 12px; }
        .pk-quick-label { font-size: 0.6875rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--c-muted); margin-right: 0.25rem; }
        .pk-grid-wrap { overflow-x: auto; border: 1px solid var(--c-line); border-radius: 14px; }
        .pk-grid { width: 100%; border-collapse: separate; border-spacing: 0; user-select: none; }
        .pk-grid th, .pk-grid td { padding: 0; }
        .pk-grid thead th { position: sticky; top: 0; background: var(--c-paper); font-size: 0.65625rem; font-weight: 800; color: var(--c-muted); padding: 0.5rem 0; text-align: center; border-bottom: 1px solid var(--c-line); font-variant-numeric: tabular-nums; }
        .pk-grid thead th.pk-th-left { text-align: left; padding-left: 0.75rem; text-transform: uppercase; letter-spacing: 0.06em; }
        .pk-grid .pk-day { text-align: left; padding: 0.5rem 0.75rem; font-weight: 800; font-size: 0.8125rem; color: var(--c-brown); white-space: nowrap; background: #FFFFFF; border-right: 1px solid var(--c-line); min-width: 92px; }
        .pk-grid .pk-sum { padding: 0.4rem 0.75rem; font-size: 0.71875rem; color: var(--c-brown); white-space: nowrap; background: #FFFFFF; border-left: 1px solid var(--c-line); min-width: 170px; }
        .pk-grid tbody tr + tr td { border-top: 1px solid var(--c-line-soft); }
        .pk-cell { display: block; width: 100%; min-width: 34px; height: 38px; border: none; border-right: 1px solid #FFFFFF; cursor: pointer; transition: filter 0.1s; }
        .pk-cell:hover { filter: brightness(0.94); }
        .pk-cell[disabled] { cursor: default; }
        .pk-cell.is-reg { background: #F1EBDD; }
        .pk-cell.is-peak { background: var(--c-terra); }
        .pk-row-btn { background: none; border: none; padding: 0 0.15rem; font-size: 0.6875rem; font-weight: 700; color: var(--c-terra); cursor: pointer; text-decoration: underline; text-underline-offset: 2px; }
        .pk-foot-note { font-size: 0.71875rem; color: var(--c-muted); }
        .pk-savebar { position: sticky; bottom: 12px; z-index: 20; display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; background: var(--c-brown); color: var(--c-cream); border-radius: 14px; padding: 0.75rem 1rem; box-shadow: 0 16px 34px -12px rgba(42, 20, 16, 0.55); }
        .pk-savebar-text { font-size: 0.8125rem; font-weight: 700; }

        /* Tanggal merah */
        .md-holiday-form { display: grid; grid-template-columns: minmax(160px, 200px) minmax(0, 1fr) auto; gap: 0.75rem; align-items: start; padding-bottom: 1.25rem; margin-bottom: 1.25rem; border-bottom: 1px dashed var(--c-line); }
        .md-label-ghost { visibility: hidden; }
        .md-chips { display: flex; flex-wrap: wrap; gap: 0.6rem; }
        .pk-chip { display: inline-flex; align-items: center; gap: 0.65rem; background: var(--c-paper); border: 1px solid var(--c-line); border-radius: 12px; padding: 0.55rem 0.7rem 0.55rem 0.8rem; }
        .pk-chip-date { text-align: center; min-width: 42px; border-right: 1px solid var(--c-line); padding-right: 0.6rem; }
        .pk-chip-day { font-family: var(--font-serif); font-size: 1.25rem; font-weight: 600; color: #B42318; line-height: 1; }
        .pk-chip-month { font-size: 0.59375rem; font-weight: 800; letter-spacing: 0.04em; color: var(--c-muted); text-transform: uppercase; margin-top: 0.15rem; }
        .pk-chip-name { font-size: 0.8125rem; font-weight: 700; color: var(--c-brown); }
        .pk-chip-meta { font-size: 0.6875rem; color: var(--c-muted); }
        .pk-chip-del { margin-left: 0.15rem; width: 26px; height: 26px; border-radius: 7px; border: 1px solid transparent; background: transparent; color: #B42318; font-size: 1.05rem; font-weight: 800; line-height: 1; cursor: pointer; }
        .pk-chip-del:hover { background: #FEF2F2; border-color: #FECACA; }
        .md-chips-empty { width: 100%; text-align: center; color: var(--c-muted); font-size: 0.8125rem; padding: 1.25rem 1rem; border: 1px dashed var(--c-line); border-radius: 12px; background: var(--c-paper); }

        @media (max-width: 640px) {
            .md-grid-2 { grid-template-columns: 1fr; }
            .md-holiday-form { grid-template-columns: 1fr; }
            .md-label-ghost { display: none; }
        }
    </style>

    {{-- HEADER BANNER --}}
    <div class="c61-hero">
        <div>
            <div class="c61-eyebrow">
                <span class="dot"></span>
                <span>Konfigurasi Arena &bull; Master Data &amp; Tarif</span>
            </div>
            <div class="c61-hero-title">
                Master Data, Tarif Lapangan &amp; Add-ons
            </div>
            <div class="c61-hero-sub">
                Kelola tarif sewa per jam untuk seluruh lapangan padel (Jam Reguler vs Jam Ramai/Prime Time) serta manajemen persediaan dan tarif rental Add-ons (raket, bola, handuk, pelatih).
            </div>
        </div>

        <div class="c61-hero-actions">
            @if($activeTab === 'courts')
                @if($this->canManageCourts)
                    <button type="button" wire:click="openOperatingHoursModal" class="c61-btn c61-btn-outline-cream">
                        <span>Atur Jam Buka-Tutup Massal</span>
                    </button>
                    <button type="button" wire:click="openCreateCourtModal" class="c61-btn c61-btn-cream">
                        <span>+ Tambah Lapangan Baru</span>
                    </button>
                @endif
            @elseif($activeTab === 'equipments' && $this->canManageEquipment)
                <button type="button" wire:click="openCreateEquipmentModal" class="c61-btn c61-btn-cream">
                    <span>+ Tambah Add-on Baru</span>
                </button>
            @endif
        </div>
    </div>

    {{-- NAVIGASI TAB --}}
    <div class="md-tabs-row">
        <div class="c61-seg">
            <button type="button" wire:click="setActiveTab('courts')" class="c61-seg-btn {{ $activeTab === 'courts' ? 'is-active' : '' }}">
                <span>Tarif Lapangan &amp; Jam Ramai</span>
            </button>
            <button type="button" wire:click="setActiveTab('peak_hours')" class="c61-seg-btn {{ $activeTab === 'peak_hours' ? 'is-active' : '' }}">
                <span>Jam Peak &amp; Tanggal Merah</span>
            </button>
            <button type="button" wire:click="setActiveTab('equipments')" class="c61-seg-btn {{ $activeTab === 'equipments' ? 'is-active' : '' }}">
                <span>Add-ons &amp; Peralatan Sewa</span>
            </button>
        </div>
    </div>

    {{-- ================= TAB 1: LAPANGAN & TARIF ================= --}}
    @if($activeTab === 'courts')
        <div class="c61-card">
            <div class="c61-card-head">
                <div class="md-card-head-text">
                    <div class="c61-card-title">
                        Daftar Lapangan Padel &amp; Matriks Tarif
                    </div>
                    <div class="md-peak-line">
                        Jam Peak (Prime Time):
                        @foreach ($this->peakSummary as $dayLabel => $ranges)
                            <span class="md-range">{{ $dayLabel }} {{ $ranges }}</span>@if (! $loop->last) &bull; @endif
                        @endforeach
                        &mdash; di luar itu tarif reguler. <a href="#" wire:click.prevent="setActiveTab('peak_hours')" class="c61-link">Ubah jam peak</a>
                    </div>
                </div>
                <div class="c61-row">
                    <span class="c61-pill c61-pill-cream">Total: {{ $this->courts->count() }} Lapangan</span>
                </div>
            </div>

            <div class="c61-table-wrap">
                <table class="c61-table md-table">
                    <thead>
                        <tr>
                            <th>Nama Lapangan</th>
                            <th>Tipe Venue</th>
                            <th>Tarif Reguler</th>
                            <th>Tarif Prime Time (Jam Peak)</th>
                            <th class="md-center">Status Aktif</th>
                            <th class="md-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->courts as $court)
                            <tr>
                                <td>
                                    <div class="md-name">{{ $court->name }}</div>
                                    <div class="md-desc">
                                        {{ $court->description ?: ($court->type === 'INDOOR' ? 'Indoor • Central AC' : 'Outdoor • Open Air Court') }}
                                    </div>
                                    <span class="md-hours">
                                        Jam Operasional: {{ $court->open_time ?: '06:00' }} - {{ $court->close_time ?: '23:00' }} WIB
                                    </span>
                                    <div class="md-id">ID: {{ $court->id }}</div>
                                </td>
                                <td>
                                    @if(strtoupper($court->type) === 'INDOOR')
                                        <span class="c61-pill c61-pill-terra">Indoor Panoramic</span>
                                    @else
                                        <span class="c61-pill c61-pill-ok">Outdoor Court</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="md-price">
                                        Rp {{ number_format($court->hourly_rate_regular, 0, ',', '.') }}
                                    </div>
                                    <div class="md-price-sub">per jam sesi</div>
                                </td>
                                <td>
                                    <div class="md-price is-prime">
                                        Rp {{ number_format($court->hourly_rate_prime, 0, ',', '.') }}
                                    </div>
                                    <div class="md-price-sub">per jam prime time</div>
                                </td>
                                <td class="md-center">
                                    <div class="md-toggle {{ $this->canManageCourts ? 'is-clickable' : '' }}"
                                        @if($this->canManageCourts) wire:click="toggleCourtStatus('{{ $court->id }}')" @endif>
                                        <div class="md-switch {{ $court->is_active ? 'on' : '' }}">
                                            <div class="md-switch-knob"></div>
                                        </div>
                                        <span class="md-toggle-text {{ $court->is_active ? 'is-on' : '' }}">
                                            {{ $court->is_active ? 'AKTIF' : 'OFF' }}
                                        </span>
                                    </div>
                                </td>
                                <td class="md-right">
                                    @if($this->canManageCourts)
                                        <button type="button" wire:click="openEditCourtModal('{{ $court->id }}')" class="c61-btn c61-btn-ghost c61-btn-sm">
                                            <span>Edit Tarif</span>
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="c61-empty">
                                    Belum ada data lapangan.{{ $this->canManageCourts ? ' Silakan klik tombol "+ Tambah Lapangan Baru".' : '' }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- ================= TAB 3: JAM PEAK & TANGGAL MERAH ================= --}}
    @if($activeTab === 'peak_hours')
        @php
            $canEdit = $this->canManageCourts;
            $editorHours = $this->peakEditorHours;
            $rateExample = $this->peakRateExample;
            $dayOrder = array_keys(\App\Filament\Pages\MasterData::DAY_LABELS);
        @endphp

        <div class="c61-card pk-card"
            wire:ignore
            x-data="{
                grid: @js($this->peakGrid),
                saved: null,
                painting: false,
                paintValue: true,
                saving: false,
                canEdit: @js($canEdit),
                init() {
                    this.saved = JSON.stringify(this.grid);
                    window.addEventListener('mouseup', () => this.painting = false);
                },
                get dirty() { return JSON.stringify(this.grid) !== this.saved; },
                start(day, hour) {
                    if (! this.canEdit) return;
                    this.painting = true;
                    this.paintValue = ! this.grid[day][hour];
                    this.grid[day][hour] = this.paintValue;
                },
                over(day, hour) {
                    if (this.painting) this.grid[day][hour] = this.paintValue;
                },
                fillDay(day, value) { for (let h = 0; h < 24; h++) this.grid[day][h] = value; },
                copyDay(from, targets) { targets.forEach(d => this.grid[d] = [...this.grid[from]]); },
                preset() {
                    [1, 2, 3, 4, 5].forEach(d => { for (let h = 0; h < 24; h++) this.grid[d][h] = h >= 17; });
                    [6, 0].forEach(d => this.fillDay(d, true));
                },
                summary(day) {
                    const out = []; let s = null;
                    for (let h = 0; h <= 24; h++) {
                        const on = h < 24 && this.grid[day][h];
                        if (on && s === null) s = h;
                        if (! on && s !== null) { out.push(String(s).padStart(2, '0') + ':00–' + String(h).padStart(2, '0') + ':00'); s = null; }
                    }
                    return out.length ? out.join(', ') : 'Reguler seharian';
                },
                reset() { this.grid = JSON.parse(this.saved); },
                async save() {
                    this.saving = true;
                    const ok = await $wire.savePeakGrid(this.grid);
                    this.saving = false;
                    if (ok) this.saved = JSON.stringify(this.grid);
                },
            }">

            <div class="c61-card-head" style="align-items:flex-start;">
                <div class="md-card-head-text" style="max-width:760px;">
                    <div class="c61-card-title">Atur Jam Ramai (Peak)</div>
                    <div class="c61-card-sub">
                        Klik atau geser kotak jam untuk menandai jam <b>peak</b>. Berlaku untuk semua lapangan; booking yang sudah dibayar tidak ikut berubah.
                    </div>
                </div>
                <div class="pk-legend">
                    <span class="pk-legend-item"><span class="pk-swatch pk-reg"></span> <span><b>Reguler</b>@if($rateExample['regular']) <span class="pk-legend-rate">({{ $rateExample['regular'] }}/jam)</span>@endif</span></span>
                    <span class="pk-legend-item"><span class="pk-swatch pk-peak"></span> <span><b>Peak / Prime</b>@if($rateExample['prime']) <span class="pk-legend-rate">({{ $rateExample['prime'] }}/jam)</span>@endif</span></span>
                </div>
            </div>

            <div class="c61-card-body pk-body">
                @if($canEdit)
                    <div class="pk-quick">
                        <span class="pk-quick-label">Tombol cepat:</span>
                        <button type="button" class="c61-btn c61-btn-ghost c61-btn-sm md-btn-wrap" x-on:click="copyDay(1, [2, 3, 4, 5])">Samakan Selasa&ndash;Jumat dengan Senin</button>
                        <button type="button" class="c61-btn c61-btn-ghost c61-btn-sm md-btn-wrap" x-on:click="copyDay(6, [0])">Samakan Minggu dengan Sabtu</button>
                        <button type="button" class="c61-btn c61-btn-ghost c61-btn-sm md-btn-wrap" x-on:click="preset()">Pakai default (hari kerja 17:00&ndash;24:00, weekend seharian)</button>
                    </div>
                @endif

                <div class="pk-grid-wrap">
                    <table class="pk-grid">
                        <thead>
                            <tr>
                                <th class="pk-th-left">Hari</th>
                                @foreach ($editorHours as $h)
                                    <th title="{{ sprintf('%02d:00–%02d:00', $h, $h + 1) }}">{{ sprintf('%02d', $h) }}</th>
                                @endforeach
                                <th class="pk-th-left">Jam peak</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($dayOrder as $day)
                                <tr>
                                    <td class="pk-day">{{ \App\Filament\Pages\MasterData::DAY_LABELS[$day] }}</td>
                                    @foreach ($editorHours as $h)
                                        <td>
                                            <button type="button" class="pk-cell"
                                                :class="grid[{{ $day }}][{{ $h }}] ? 'is-peak' : 'is-reg'"
                                                :title="'{{ \App\Filament\Pages\MasterData::DAY_LABELS[$day] }} {{ sprintf('%02d:00–%02d:00', $h, $h + 1) }}: ' + (grid[{{ $day }}][{{ $h }}] ? 'Peak' : 'Reguler')"
                                                x-on:mousedown.prevent="start({{ $day }}, {{ $h }})"
                                                x-on:mouseenter="over({{ $day }}, {{ $h }})"
                                                @disabled(! $canEdit)></button>
                                        </td>
                                    @endforeach
                                    <td class="pk-sum">
                                        <div style="font-weight:800;" :style="summary({{ $day }}) === 'Reguler seharian' ? 'color:#A08F86' : 'color:#662721'" x-text="summary({{ $day }})"></div>
                                        @if($canEdit)
                                            <div style="margin-top:0.15rem;">
                                                <button type="button" class="pk-row-btn" x-on:click="fillDay({{ $day }}, true)">Semua peak</button>
                                                &middot;
                                                <button type="button" class="pk-row-btn" x-on:click="fillDay({{ $day }}, false)">Kosongkan</button>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="pk-foot-note">
                    Kolom jam = jam mulai main (contoh kolom <b>23</b> = slot 23:00&ndash;24:00). Hanya jam operasional lapangan yang ditampilkan.
                </div>

                @if($canEdit)
                    <div class="pk-savebar" x-show="dirty" x-transition x-cloak>
                        <span class="pk-savebar-text">Ada perubahan jam peak yang belum disimpan.</span>
                        <div class="c61-row">
                            <button type="button" class="c61-btn c61-btn-outline-cream c61-btn-sm" x-on:click="reset()">Batalkan</button>
                            <button type="button" class="c61-btn c61-btn-cream c61-btn-sm" x-on:click="save()" :disabled="saving">
                                <span x-text="saving ? 'Menyimpan…' : 'Simpan Jam Peak'"></span>
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- Tanggal merah --}}
        <div class="c61-card">
            <div class="c61-card-head">
                <div class="md-card-head-text">
                    <div class="c61-card-title">Tanggal Merah / Libur Nasional</div>
                    <div class="c61-card-sub">Di tanggal ini harga lapangan mengikuti jam peak <b>hari Minggu</b> &mdash; walaupun jatuh di hari kerja.</div>
                </div>
            </div>

            <div class="c61-card-body">
                @if($canEdit)
                    {{-- align-items:start: pesan error di bawah satu input tidak boleh menggeser input & tombol lain --}}
                    <div class="md-holiday-form">
                        <div class="md-field">
                            <label class="c61-label">Tanggal</label>
                            <input type="date" wire:model="holidayDate" class="c61-input" min="{{ now('Asia/Jakarta')->toDateString() }}">
                            @error('holidayDate') <span class="md-err">{{ $message }}</span> @enderror
                        </div>
                        <div class="md-field">
                            <label class="c61-label">Nama libur</label>
                            <input type="text" wire:model="holidayName" class="c61-input" maxlength="100" placeholder="Contoh: Hari Raya Natal">
                            @error('holidayName') <span class="md-err">{{ $message }}</span> @enderror
                        </div>
                        <div class="md-field">
                            <label class="c61-label md-label-ghost" aria-hidden="true">Aksi</label>
                            <button type="button" wire:click="addHoliday" wire:loading.attr="disabled" class="c61-btn c61-btn-primary"><span>+ Tambah Tanggal</span></button>
                        </div>
                    </div>
                @endif

                <div class="md-chips">
                    @forelse ($this->holidays as $holiday)
                        <div class="pk-chip" wire:key="holiday-{{ $holiday->id }}">
                            <div class="pk-chip-date">
                                <div class="pk-chip-day">{{ $holiday->date->format('d') }}</div>
                                <div class="pk-chip-month">{{ $holiday->date->translatedFormat('M Y') }}</div>
                            </div>
                            <div>
                                <div class="pk-chip-name">{{ $holiday->name }}</div>
                                <div class="pk-chip-meta">{{ $holiday->date->translatedFormat('l') }} &middot; ikut jam peak Minggu</div>
                            </div>
                            @if($canEdit)
                                <button type="button"
                                    x-on:click="$dispatch('club61-confirm', {
                                        title: 'Hapus Tanggal Merah?',
                                        message: @js($holiday->name.' ('.$holiday->date->translatedFormat('d M Y').') akan dihapus. Tarif di tanggal itu kembali mengikuti jam peak hari biasa.'),
                                        confirmLabel: 'Ya, Hapus',
                                        tone: 'danger',
                                        onConfirm: () => $wire.deleteHoliday(@js($holiday->id)),
                                    })"
                                    title="Hapus" class="pk-chip-del">&times;</button>
                            @endif
                        </div>
                    @empty
                        <div class="md-chips-empty">
                            Belum ada tanggal merah yang akan datang.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    @endif

    {{-- ================= TAB 2: ADD-ONS & PERALATAN ================= --}}
    @if($activeTab === 'equipments')
        <div class="c61-card">
            <div class="c61-card-head">
                <div class="md-card-head-text">
                    <div class="c61-card-title">
                        Katalog Add-ons &amp; Peralatan Sewa Padel
                    </div>
                    <div class="c61-card-sub">
                        Peralatan yang dapat disewa pelanggan saat checkout online maupun walk-in di kasir POS.
                    </div>
                </div>

                {{-- Filter Status --}}
                <div class="c61-row">
                    <span class="pk-quick-label">Filter:</span>
                    <div class="c61-seg">
                        <button type="button" wire:click="setEquipmentFilter('ALL')"
                            class="c61-seg-btn {{ $equipmentFilter === 'ALL' ? 'is-active' : '' }}">
                            Semua ({{ $this->equipments->count() }})
                        </button>
                        <button type="button" wire:click="setEquipmentFilter('ACTIVE')"
                            class="c61-seg-btn {{ $equipmentFilter === 'ACTIVE' ? 'is-active' : '' }}">
                            Aktif Saja
                        </button>
                        <button type="button" wire:click="setEquipmentFilter('INACTIVE')"
                            class="c61-seg-btn {{ $equipmentFilter === 'INACTIVE' ? 'is-active' : '' }}">
                            Nonaktif
                        </button>
                    </div>
                </div>
            </div>

            <div class="c61-table-wrap">
                <table class="c61-table md-table" style="min-width:960px;">
                    <thead>
                        <tr>
                            <th>Nama Add-on / Alat</th>
                            <th>Kategori</th>
                            <th>Tarif Sewa per Sesi</th>
                            <th>Stok Unit</th>
                            <th>Riwayat Rental</th>
                            <th class="md-center">Status Katalog</th>
                            <th class="md-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->equipments as $eq)
                            <tr>
                                <td>
                                    <div class="md-name">{{ $eq->name }}</div>
                                    <div class="md-id">ID: {{ $eq->id }}</div>
                                </td>
                                <td>
                                    @switch(strtoupper($eq->type))
                                        @case('RACKET')
                                            <span class="c61-pill c61-pill-terra">Raket Padel</span>
                                            @break
                                        @case('BALL')
                                            <span class="c61-pill c61-pill-ok">Bola Padel</span>
                                            @break
                                        @case('TOWEL')
                                            <span class="c61-pill c61-pill-info">Handuk</span>
                                            @break
                                        @case('COACH')
                                            <span class="c61-pill c61-pill-orange">Pelatih</span>
                                            @break
                                        @default
                                            <span class="c61-pill c61-pill-gray">{{ $eq->type }}</span>
                                    @endswitch
                                </td>
                                <td>
                                    <div class="md-price">
                                        Rp {{ number_format($eq->rental_price, 0, ',', '.') }}
                                    </div>
                                    <div class="md-price-sub">per sesi booking</div>
                                </td>
                                <td>
                                    <div class="md-stock {{ $eq->stock_quantity > 0 ? '' : 'is-empty' }}">
                                        {{ $eq->stock_quantity }} Unit
                                    </div>
                                </td>
                                <td>
                                    @if($eq->historical_rentals_count > 0)
                                        <span class="c61-pill c61-pill-cream" title="Item ini pernah disewa dan tercatat di invoice pelanggan.">
                                            {{ $eq->historical_rentals_count }}x Disewa (Terkunci Historis)
                                        </span>
                                    @else
                                        <span class="md-muted">Belum ada sewa</span>
                                    @endif
                                </td>
                                <td class="md-center">
                                    <div class="md-toggle {{ $this->canManageEquipment ? 'is-clickable' : '' }}"
                                        @if($this->canManageEquipment) wire:click="toggleEquipmentStatus('{{ $eq->id }}')" @endif>
                                        <div class="md-switch {{ $eq->is_active ? 'on' : '' }}">
                                            <div class="md-switch-knob"></div>
                                        </div>
                                        <span class="md-toggle-text {{ $eq->is_active ? 'is-on' : '' }}">
                                            {{ $eq->is_active ? 'AKTIF' : 'OFF' }}
                                        </span>
                                    </div>
                                </td>
                                <td class="md-right">
                                    @if($this->canManageEquipment)
                                    <div class="c61-actions">
                                        <button type="button" wire:click="openEditEquipmentModal('{{ $eq->id }}')" class="c61-btn c61-btn-ghost c61-btn-sm">
                                            <span>Edit</span>
                                        </button>

                                        @if($eq->historical_rentals_count > 0)
                                            <button type="button"
                                                x-on:click="$dispatch('club61-confirm', {
                                                    title: @js('Nonaktifkan '.$eq->name.'?'),
                                                    message: 'Item ini sudah pernah disewa, jadi tidak dihapus — hanya dinonaktifkan supaya tidak bisa disewa lagi. Struk & invoice lama tetap utuh.',
                                                    confirmLabel: 'Ya, Nonaktifkan',
                                                    onConfirm: () => $wire.deleteEquipment(@js($eq->id)),
                                                })"
                                                class="c61-btn c61-btn-sm md-btn-warn"
                                                title="Nonaktifkan item dengan menjaga invoice historis tetap utuh">
                                                <span>Nonaktifkan</span>
                                            </button>
                                        @else
                                            <button type="button"
                                                x-on:click="$dispatch('club61-confirm', {
                                                    title: @js('Hapus '.$eq->name.'?'),
                                                    message: 'Item ini belum pernah disewa, jadi akan dihapus permanen dari sistem. Tindakan ini tidak bisa dibatalkan.',
                                                    confirmLabel: 'Ya, Hapus Permanen',
                                                    tone: 'danger',
                                                    onConfirm: () => $wire.deleteEquipment(@js($eq->id)),
                                                })"
                                                class="c61-btn c61-btn-sm md-btn-del"
                                                title="Hapus permanen dari database">
                                                <span>Hapus</span>
                                            </button>
                                        @endif
                                    </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="c61-empty">
                                    Belum ada data Add-on.{{ $this->canManageEquipment ? ' Silakan klik tombol "+ Tambah Add-on Baru".' : '' }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- ================= MODAL EDIT/TAMBAH LAPANGAN ================= --}}
    @if($showCourtModal)
        <div class="c61-modal-backdrop">
            <div class="c61-modal" style="max-width:580px;">
                <div class="c61-modal-head">
                    <div class="c61-modal-title" style="margin-top:0;">
                        {{ $editingCourtId ? 'Edit Tarif & Detail Lapangan' : 'Tambah Lapangan Padel Baru' }}
                    </div>
                    <button type="button" wire:click="closeCourtModal" class="c61-modal-close">
                        &times;
                    </button>
                </div>

                <div class="c61-modal-body">
                    <div class="md-field">
                        <label class="c61-label">Nama Lapangan</label>
                        <input type="text" wire:model="courtName" class="c61-input" placeholder="Contoh: Court 1 - Panoramic Indoor">
                        @error('courtName') <span class="md-err">{{ $message }}</span> @enderror
                    </div>

                    <div class="md-field">
                        <label class="c61-label">Jenis &amp; Keterangan Fasilitas</label>
                        <input type="text" wire:model="courtDescription" class="c61-input" placeholder="Contoh: Indoor • Central AC atau Outdoor • Open Air Court">
                        <span class="c61-hint">Teks ini ditampilkan pada sub-judul kartu lapangan di halaman booking pelanggan.</span>
                        @error('courtDescription') <span class="md-err">{{ $message }}</span> @enderror
                    </div>

                    <div class="md-field">
                        <label class="c61-label">Tipe Lapangan</label>
                        <select wire:model="courtType" class="c61-select">
                            <option value="INDOOR">INDOOR (Full AC / Panoramic Glass)</option>
                            <option value="OUTDOOR">OUTDOOR (Open Air Stadium)</option>
                        </select>
                        @error('courtType') <span class="md-err">{{ $message }}</span> @enderror
                    </div>

                    <div class="md-grid-2">
                        <div class="md-field">
                            <label class="c61-label">Tarif Reguler (Rp/Jam)</label>
                            <input type="number" step="10000" wire:model="hourlyRateRegular" class="c61-input" placeholder="300000">
                            <span class="c61-hint">Di luar jam peak (atur di tab Jam Peak)</span>
                            @error('hourlyRateRegular') <span class="md-err">{{ $message }}</span> @enderror
                        </div>

                        <div class="md-field">
                            <label class="c61-label">Tarif Prime Time (Rp/Jam)</label>
                            <input type="number" step="10000" wire:model="hourlyRatePrime" class="c61-input" placeholder="450000">
                            <span class="c61-hint">Berlaku di jam peak (atur di tab Jam Peak)</span>
                            @error('hourlyRatePrime') <span class="md-err">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="md-grid-2">
                        <div class="md-field">
                            <label class="c61-label">Jam Buka Operasional</label>
                            <select wire:model="courtOpenTime" class="c61-select">
                                @for($i = 5; $i <= 18; $i++)
                                    @php $val = sprintf('%02d:00', $i); @endphp
                                    <option value="{{ $val }}">{{ $val }} WIB</option>
                                @endfor
                            </select>
                            <span class="c61-hint">Jadwal booking lapangan dimulai dari jam ini.</span>
                            @error('courtOpenTime') <span class="md-err">{{ $message }}</span> @enderror
                        </div>

                        <div class="md-field">
                            <label class="c61-label">Jam Tutup Operasional</label>
                            <select wire:model="courtCloseTime" class="c61-select">
                                @for($i = 12; $i <= 24; $i++)
                                    @php $val = sprintf('%02d:00', $i); @endphp
                                    <option value="{{ $val }}">{{ $val }} WIB</option>
                                @endfor
                            </select>
                            <span class="c61-hint">Batas slot jam terakhir selesai.</span>
                            @error('courtCloseTime') <span class="md-err">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="md-field">
                        <label class="c61-label">Status Lapangan di Jadwal Publik</label>
                        <div class="md-radio-row">
                            <label class="md-radio">
                                <input type="radio" wire:model="courtIsActive" value="1">
                                <span class="is-on">Aktif (Bisa Dipesan Publik)</span>
                            </label>
                            <label class="md-radio">
                                <input type="radio" wire:model="courtIsActive" value="0">
                                <span class="is-off">Nonaktif (Maintenance / Tutup)</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="c61-modal-foot">
                    <button type="button" wire:click="closeCourtModal" class="c61-btn c61-btn-ghost">
                        Batal
                    </button>
                    <button type="button" wire:click="saveCourt" class="c61-btn c61-btn-primary">
                        Simpan Lapangan
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================= MODAL EDIT/TAMBAH ADD-ON ================= --}}
    @if($showEquipmentModal)
        <div class="c61-modal-backdrop">
            <div class="c61-modal" style="max-width:580px;">
                <div class="c61-modal-head">
                    <div class="c61-modal-title" style="margin-top:0;">
                        {{ $editingEquipmentId ? 'Edit Add-on / Peralatan' : 'Tambah Add-on Peralatan Baru' }}
                    </div>
                    <button type="button" wire:click="closeEquipmentModal" class="c61-modal-close">
                        &times;
                    </button>
                </div>

                <div class="c61-modal-body">
                    <div class="md-field">
                        <label class="c61-label">Nama Add-on / Alat Sewa</label>
                        <input type="text" wire:model="equipmentName" class="c61-input" placeholder="Contoh: Raket Babolat Counter Viper">
                        @error('equipmentName') <span class="md-err">{{ $message }}</span> @enderror
                    </div>

                    <div class="md-field">
                        <label class="c61-label">Kategori Tipe</label>
                        <select wire:model="equipmentType" class="c61-select">
                            <option value="RACKET">RACKET (Raket Padel)</option>
                            <option value="BALL">BALL (Bola Padel / Can)</option>
                            <option value="TOWEL">TOWEL (Handuk Olahraga)</option>
                            <option value="COACH">COACH (Pelatih / Private Trainer)</option>
                            <option value="OTHER">OTHER (Perlengkapan Lainnya)</option>
                        </select>
                        @error('equipmentType') <span class="md-err">{{ $message }}</span> @enderror
                    </div>

                    <div class="md-grid-2">
                        <div class="md-field">
                            <label class="c61-label">Tarif Sewa per Sesi (Rp)</label>
                            <input type="number" step="5000" wire:model="equipmentRentalPrice" class="c61-input" placeholder="50000">
                            @error('equipmentRentalPrice') <span class="md-err">{{ $message }}</span> @enderror
                        </div>

                        <div class="md-field">
                            <label class="c61-label">Jumlah Stok Unit</label>
                            <input type="number" step="1" wire:model="equipmentStock" class="c61-input" placeholder="20">
                            @error('equipmentStock') <span class="md-err">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="md-field">
                        <label class="c61-label">Status di Katalog Sewa</label>
                        <div class="md-radio-row">
                            <label class="md-radio">
                                <input type="radio" wire:model="equipmentIsActive" value="1">
                                <span class="is-on">Aktif (Muncul di Checkout &amp; Kasir)</span>
                            </label>
                            <label class="md-radio">
                                <input type="radio" wire:model="equipmentIsActive" value="0">
                                <span class="is-off">Nonaktif (Disembunyikan)</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="c61-modal-foot">
                    <button type="button" wire:click="closeEquipmentModal" class="c61-btn c61-btn-ghost">
                        Batal
                    </button>
                    <button type="button" wire:click="saveEquipment" class="c61-btn c61-btn-primary">
                        Simpan Add-on
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ================= MODAL ATUR JAM OPERASIONAL MASSAL ================= --}}
    @if($showOperatingHoursModal)
        <div class="c61-modal-backdrop">
            <div class="c61-modal" style="max-width:580px;">
                <div class="c61-modal-head">
                    <div>
                        <div class="c61-modal-title" style="margin-top:0;">
                            Atur Jam Operasional Seluruh Lapangan
                        </div>
                        <div class="md-modal-sub">
                            Terapkan jam buka dan tutup ke seluruh {{ $this->courts->count() }} lapangan sekaligus secara serempak.
                        </div>
                    </div>
                    <button type="button" wire:click="closeOperatingHoursModal" class="c61-modal-close">
                        &times;
                    </button>
                </div>

                <div class="c61-modal-body">
                    <div class="c61-note">
                        Perubahan ini langsung memperbarui awal dan akhir slot booking pada jadwal publik (/booking), monitor command board, dan kasir POS walk-in. Misalnya jika diset jam 11:00 WIB, maka booking lapangan langsung dimulai dari jam 11:00 WIB.
                    </div>

                    <div class="md-grid-2">
                        <div class="md-field">
                            <label class="c61-label">Jam Buka Serentak</label>
                            <select wire:model="bulkOpenTime" class="c61-select">
                                @for($i = 5; $i <= 18; $i++)
                                    @php $val = sprintf('%02d:00', $i); @endphp
                                    <option value="{{ $val }}">{{ $val }} WIB</option>
                                @endfor
                            </select>
                            <span class="c61-hint">Misal: buka jam 11:00 WIB, maka jadwal mulai dari jam 11:00.</span>
                            @error('bulkOpenTime') <span class="md-err">{{ $message }}</span> @enderror
                        </div>

                        <div class="md-field">
                            <label class="c61-label">Jam Tutup Serentak</label>
                            <select wire:model="bulkCloseTime" class="c61-select">
                                @for($i = 12; $i <= 24; $i++)
                                    @php $val = sprintf('%02d:00', $i); @endphp
                                    <option value="{{ $val }}">{{ $val }} WIB</option>
                                @endfor
                            </select>
                            <span class="c61-hint">Batas slot jam terakhir malam hari.</span>
                            @error('bulkCloseTime') <span class="md-err">{{ $message }}</span> @enderror
                        </div>
                    </div>
                </div>

                <div class="c61-modal-foot">
                    <button type="button" wire:click="closeOperatingHoursModal" class="c61-btn c61-btn-ghost">
                        Batal
                    </button>
                    <button type="button" wire:click="saveOperatingHoursAllCourts" class="c61-btn c61-btn-primary">
                        Terapkan ke Seluruh Lapangan
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
