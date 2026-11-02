{{-- Walk-In Offline Booking – POS Frontdesk (tablet first). Brand Club 61: Terakota #662721 (dominan), Cream #F7F0DB. --}}
<div class="walkin-pos-root"
    x-data="{
        step: 1,
        busy: false,
        sx: null, sy: null,
        go(n) { this.step = n },
        prev() { if (this.step > 1) this.step-- },
        customerMissing() {
            if (this.$wire.settleBill || this.$wire.bookingAddon) return false;
            return this.$wire.customerMode === 'search'
                ? ! this.$wire.selectedCustomerId
                : (! String(this.$wire.walkInName || '').trim() || ! String(this.$wire.walkInPhone || '').trim());
        },
        async toPayment() {
            if (this.busy) return;
            this.busy = true;
            const slotsBefore = Object.keys(this.$wire.selectedSlots || {}).length;
            try { await this.$wire.proceedToPayment(); } finally { this.busy = false; }
            // Validasi server gagal → kembali ke layar yang perlu dilengkapi.
            if (this.$wire.posStep === 'selection') {
                const slotsAfter = Object.keys(this.$wire.selectedSlots || {}).length;
                // Slot keburu diambil customer lain (dihapus dari pilihan) → tampilkan jadwal untuk pilih jam lain.
                if (this.$wire.bookingAddon) this.step = 3;
                else if ((! slotsAfter || slotsAfter < slotsBefore) && ! this.$wire.settleBill) this.step = 1;
                else if (this.customerMissing()) this.step = 2;
            }
        },
        touchStart(e) {
            const t = e.target;
            const scroller = t.closest('.pos-timeline-scroll');
            if (t.closest('input, textarea, select') || (scroller && scroller.scrollWidth > scroller.clientWidth + 2)) { this.sx = null; return; }
            this.sx = e.changedTouches[0].clientX; this.sy = e.changedTouches[0].clientY;
        },
        touchEnd(e) {
            if (this.sx === null) return;
            const dx = e.changedTouches[0].clientX - this.sx, dy = e.changedTouches[0].clientY - this.sy;
            this.sx = null;
            if (Math.abs(dx) < 70 || Math.abs(dy) > 50) return;
            const stage = this.$refs.stage;
            if (dx > 0) return this.prev();
            if (this.step === 1 && stage?.dataset.addon === '1') this.step = 3;
            else if (this.step === 1 && Number(stage?.dataset.slots || 0) > 0) this.step = 2;
            else if (this.step === 2 && stage?.dataset.settle !== '1') this.step = 3;
        },
    }"
    {{-- Tambah Alat ke Booking: server memindahkan langkah (pilih booking → Tambahan). --}}
    x-on:pos-go-step.window="go($event.detail.step)">
    @include('filament.partials.pos-theme-style')

    {{-- ============================
     BARIS ATAS: tab POS + Kasir/Riwayat + status shift
     ============================ --}}
    <div class="pos-headbar">
        <div class="pos-headbar-left">
            @include('filament.partials.pos-subnav', ['activePos' => 'walkin', 'inline' => true])
            @include('filament.partials.pos-history-tabs', ['isHistory' => $posStep === 'history', 'canShowHistory' => $this->canShowHistoryTab, 'inline' => true])
        </div>

        {{-- Indikator Shift Kasir Frontdesk --}}
        <div class="pos-headbar-right">
            @if ($activeShift)
                {{-- Layar tablet: nomor shift diringkas (urutan terakhir) supaya sebaris dengan tab Riwayat;
                     nomor lengkap, kasir & jam buka tetap ada di tooltip. --}}
                <span class="pos-shift-chip is-open"
                    title="Shift aktif {{ $activeShift->shift_number }} &bull; {{ $activeShift->openedBy?->name ?? 'Kasir' }} &bull; dibuka {{ $activeShift->opened_at->setTimezone('Asia/Jakarta')->format('H:i') }} WIB">
                    <span class="dot"></span>
                    <span class="pos-only-wide">SHIFT AKTIF: {{ $activeShift->shift_number }}</span>
                    <span class="pos-only-narrow">SHIFT {{ \Illuminate\Support\Str::afterLast($activeShift->shift_number, '-') }} AKTIF</span>
                    <small class="pos-hide-md">{{ $activeShift->openedBy?->name ?? 'Kasir' }} &bull; {{ $activeShift->opened_at->setTimezone('Asia/Jakarta')->format('H:i') }} WIB</small>
                </span>
                @if ($this->canSettleCoachingBill)
                    <button type="button" wire:click="openCoachingSettlement" class="pos-btn pos-btn-ghost">
                        Pelunasan Tagihan Coaching
                    </button>
                @endif
                <button type="button" wire:click="prepareCloseShift" class="pos-btn pos-btn-danger">
                    Tutup Shift<span class="pos-hide-md">&nbsp;(Closing)</span>
                </button>
            @else
                <span class="pos-shift-chip is-closed">
                    <span class="dot"></span>
                    LOKET TUTUP <span class="pos-hide-md">(BELUM BUKA SHIFT)</span>
                </span>
                <button type="button" wire:click="openShiftModal" class="pos-btn pos-btn-primary">
                    Buka Shift Pagi
                </button>
            @endif
        </div>
    </div>

    {{-- Pengingat: shift lupa ditutup / EDC belum di-settle menjelang 22.00. --}}
    @include('pos.partials.shift-warnings')

    {{-- Realtime: slot yang baru dibooking / di-hold kasir lain / online langsung tampil terisi (hanya di langkah pilih
         jadwal); tanpa Reverb → tiap 30 dtk. --}}
    <div hidden x-data="club61LiveRefresh({ dateProp: 'bookingDate', fallback: 30, when: () => $wire.posStep === 'selection' })"></div>

    {{-- ============================
     TOOLBAR: Tanggal + ringkasan hari ini
     ============================ --}}
    @if ($posStep !== 'history')
    <div class="pos-toolbar">
        <div class="pos-datenav">
            <button type="button" wire:click="prevDay" class="pos-icon-btn" title="H-1" aria-label="Hari sebelumnya">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" /></svg>
            </button>
            <button type="button" wire:click="today" class="pos-today-btn {{ $isToday ? 'is-active' : '' }}">Hari Ini</button>
            <button type="button" wire:click="nextDay" class="pos-icon-btn" title="H+1" aria-label="Hari berikutnya">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" /></svg>
            </button>
            <input type="date" wire:model.live="bookingDate" class="pos-date-input" aria-label="Pilih tanggal">
            <span class="pos-date-label"><span class="pos-only-wide">{{ \Carbon\Carbon::parse($bookingDate)->translatedFormat('l, d F Y') }}</span><span class="pos-only-narrow">{{ \Carbon\Carbon::parse($bookingDate)->translatedFormat('D, d M Y') }}</span></span>
        </div>

        {{-- Tambah Alat ke Booking (izin add_booking_equipment): alur kasir yang sama, tanpa memilih lapangan. --}}
        @if ($posStep === 'selection' && $this->canAddBookingEquipment)
            <div class="pos-toolbar-mid">
                @if ($this->isBookingAddonMode)
                    <span class="pos-addon-chip">
                        Mode Tambah Alat{{ $bookingAddon ? ' · #'.$bookingAddon['code'] : '' }}
                        <button type="button" wire:click="cancelBookingAddon" class="pos-addon-chip-close">Batal</button>
                    </span>
                @else
                    <button type="button" wire:click="startBookingAddon" class="pos-btn pos-btn-ghost" data-addon-start>
                        + Tambah Alat ke Booking
                    </button>
                @endif
            </div>
        @endif

        <div class="pos-kpis">
            <div class="pos-kpi"><span class="pos-kpi-value">{{ $bookedSlotsAll }}/{{ $totalSlotsAll }}</span><span class="pos-kpi-label">Slot Terisi</span></div>
            <div class="pos-kpi"><span class="pos-kpi-value">{{ $walkInStatsToday['count'] }}</span><span class="pos-kpi-label">Transaksi</span></div>
            <div class="pos-kpi"><span class="pos-kpi-value">Rp {{ number_format($walkInStatsToday['revenue'], 0, ',', '.') }}</span><span class="pos-kpi-label">Omzet Walk-In</span></div>
            @if ($posStep === 'selection')
                <div class="pos-recent-pop" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                    <button type="button" class="pos-btn pos-btn-ghost" @click="open = ! open" :aria-expanded="open">
                        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        Terakhir
                        <span class="pos-count-badge">{{ $recentWalkInOrders->count() }}</span>
                    </button>
                    <div x-show="open" x-transition.opacity.duration.150ms class="pos-recent-panel" style="display:none;">
                        <div class="pos-recent-header">Transaksi Walk-In Terakhir</div>
                        <div class="pos-recent-list">
                            @forelse($recentWalkInOrders as $ro)
                                <div wire:key="recent-order-{{ $ro->id }}" class="pos-recent-row">
                                    <div style="min-width:0;">
                                        <div class="pos-recent-name">{{ $ro->user?->name ?? 'Walk-In' }}</div>
                                        <div class="pos-recent-sub">
                                            {{ $ro->padelBookings->pluck('court.name')->filter()->unique()->implode(', ') ?: 'Tambahan Alat' }}
                                            &bull; {{ $ro->created_at->format('H:i') }}
                                        </div>
                                    </div>
                                    <div style="text-align:right; flex-shrink:0;">
                                        <div class="pos-recent-amount">Rp {{ number_format($ro->grand_total, 0, ',', '.') }}</div>
                                        <div class="pos-recent-badge pos-badge-{{ strtolower($ro->payment_status) }}">{{ $ro->payment_status }}</div>
                                    </div>
                                </div>
                            @empty
                                <div class="pos-recent-empty">Belum ada transaksi walk-in yang diproses hari ini.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
    @endif

    {{-- ============================
     BANNER AUTO-RECOVERY DRAF TRANSAKSI POS
     ============================ --}}
    @if ($hasPendingDraft && $pendingDraftSummary && $posStep === 'selection')
        <div class="pos-draft-banner">
            <div>
                <div class="pos-draft-title">Draf Transaksi Kasir Tersimpan</div>
                <div class="pos-draft-desc">
                    Pelanggan: <strong>{{ $pendingDraftSummary['customerName'] }}</strong> &bull;
                    {{ $pendingDraftSummary['slotsCount'] }} slot lapangan &bull;
                    Total: <strong>Rp {{ number_format($pendingDraftSummary['grandTotal'], 0, ',', '.') }}</strong>
                    (tersimpan otomatis pukul {{ $pendingDraftSummary['savedAt'] }} WIB)
                </div>
            </div>
            <div style="display:flex; align-items:center; gap:0.5rem;">
                <button type="button" wire:click="discardDraft" class="pos-draft-discard-btn">
                    Buang Draf
                </button>
                <button type="button" wire:click="resumeDraft" class="pos-draft-resume-btn">
                    Lanjutkan Transaksi &rarr;
                </button>
            </div>
        </div>
    @endif

    @if ($posStep === 'history')
        @include('filament.partials.pos-history-table', [
            'rows' => $this->transactionHistory,
            'title' => 'Riwayat Transaksi Loket Padel',
            'receiptAction' => 'viewTransactionReceipt',
            'idKey' => 'payment_id',
        ])
    @else
    {{-- ============================
     ALUR KASIR: 1 Jadwal → 2 Customer → 3 Tambahan → 4 Pembayaran → konfirmasi → struk
     ============================ --}}
    @php
        $flowSteps = [1 => $this->isBookingAddonMode ? 'Booking' : 'Jadwal', 2 => 'Customer', 3 => 'Tambahan', 4 => 'Pembayaran'];
        $serverStep = ['payment' => 4, 'receipt' => 5][$posStep] ?? null;
    @endphp
    <nav class="pos-stepper" aria-label="Langkah transaksi">
        @foreach ($flowSteps as $n => $label)
            @if ($posStep === 'selection')
                <button type="button" class="pos-stepper-item" @click="{{ $n === 4 ? 'toPayment()' : 'go(' . $n . ')' }}"
                    :class="{ 'is-active': step === {{ $n }}, 'is-done': step > {{ $n }} }">
                    <span class="pos-stepper-num">{{ $n }}</span>{{ $label }}
                </button>
            @else
                <button type="button" class="pos-stepper-item {{ $serverStep === $n ? 'is-active' : ($serverStep > $n ? 'is-done' : '') }}"
                    @if ($posStep === 'payment' && $n < 4) @click="step = {{ $n }}; $wire.backToSelection()" @else disabled @endif>
                    <span class="pos-stepper-num">{{ $n }}</span>{{ $label }}
                </button>
            @endif
            @if ($n < 4)<span class="pos-stepper-line" aria-hidden="true"></span>@endif
        @endforeach
    </nav>

    @if ($posStep === 'selection')
    <div class="pos-stage" x-ref="stage" data-slots="{{ count($selectedSlots) }}" data-settle="{{ $settleBill ? 1 : 0 }}" data-addon="{{ $bookingAddon ? 1 : 0 }}"
        @touchstart.passive="touchStart($event)" @touchend="touchEnd($event)">
        {{-- Kembali dari pembayaran → layar Tambahan; setelah transaksi selesai → mulai dari Jadwal. --}}
        <span hidden x-init="if (step === 4) step = 3; else if (step > 4) step = 1;"></span>
    @endif
    <div class="pos-main {{ $posStep === 'selection' ? 'is-selection' : '' }}"
        @if ($posStep === 'selection') x-bind:style="`transform: translateX(-${(Math.min(step, 3) - 1) * 100}%)`" @endif>

        @if ($posStep === 'selection')
            {{-- ==================== JADWAL: timeline lapangan (baris) x jam (kolom) — satu hari terlihat utuh ==================== --}}
            @php
                $hoursCount = count($operationalHours);
                $nowHour = $isToday ? (int) now('Asia/Jakarta')->format('H') : null;
                $firstCourtSlots = collect($gridData)->first()['slots'] ?? [];
                $deadHour = collect($operationalHours)->keys()->mapWithKeys(fn ($i) => [$i => count($gridData) > 0 && collect($gridData)->every(
                    fn ($cr) => in_array($cr['slots'][$i]['status'] ?? 'CLOSED', ['PAST', 'CLOSED'], true))])->all();
                $timelineColumns = collect($operationalHours)->keys()->map(fn ($i) => $deadHour[$i] ? '30px' : 'minmax(44px, 1fr)')->implode(' ');
                // Kolom lapangan selebar nama/keterangan terpanjang (tidak terpotong, tidak turun baris); di HP dibatasi via CSS.
                $courtColWidth = (int) min(300, max(116, collect($gridData)->map(fn ($cr) => max(
                    mb_strlen($cr['court']->name) * 8.6,
                    mb_strlen($cr['court']->type . ' • Rp' . number_format($cr['court']->hourly_rate_regular / 1000, 0) . 'k') * 6.6,
                ) + 30)->max() ?? 116));
                $hoursMinWidth = collect($deadHour)->sum(fn ($dead) => $dead ? 30 : 44);
                // Booking lunas yang berurutan (kode sama) digabung jadi satu blok, seperti kalender klub padel.
                $courtSegments = collect($gridData)->map(function ($courtRow) {
                    $slots = $courtRow['slots'];
                    $n = count($slots);
                    $segments = [];
                    for ($i = 0; $i < $n; $i++) {
                        $span = 1;
                        $code = $slots[$i]['status'] === 'BOOKED' ? ($slots[$i]['booking']['code'] ?? null) : null;
                        while ($code && $i + $span < $n && $slots[$i + $span]['status'] === 'BOOKED' && ($slots[$i + $span]['booking']['code'] ?? null) === $code) {
                            $span++;
                        }
                        $segments[] = ['slot' => $slots[$i], 'span' => $span, 'end_label' => substr($slots[$i + $span - 1]['end_time'], 0, 5)];
                        $i += $span - 1;
                    }

                    return $segments;
                });
            @endphp
            <div class="pos-timeline-card" x-bind:inert="step !== 1">
                <div class="pos-grid-header">
                    <div>
                        <div class="pos-grid-title">Slot Lapangan &mdash;
                            {{ !empty($operationalHours) ? $operationalHours[0]['label'] . ' sampai ' . substr(end($operationalHours)['end_time'], 0, 5) . ' WIB' : 'Jam Operasional' }}
                        </div>
                        <div class="pos-grid-sub">Ketuk kotak jam untuk memilih, ketuk lagi untuk batal &bull; Kolom jam berwarna = prime time</div>
                    </div>
                    <div class="pos-legend">
                        <span class="legend-dot"><i style="background:#FFFFFF; border:1px solid #E6DAC0;"></i>Tersedia</span>
                        <span class="legend-dot"><i style="background:#662721;"></i>Dipilih</span>
                        <span class="legend-dot"><i style="background:#EEE5D3;"></i>Terisi</span>
                        <span class="legend-dot"><i style="background:#FFFBEB; border:1px dashed #D97706;"></i>Hold</span>
                        <span class="legend-dot"><i style="background:#FEF3C7; border:1.5px solid #D97706;"></i>Bayar Selisih</span>
                    </div>
                </div>

                @if ($bookingDate < now()->format('Y-m-d'))
                    <div class="pos-past-note">Tanggal sudah lewat &mdash; hanya untuk melihat riwayat booking, tidak bisa input booking baru.</div>
                @elseif ($bookingWindowNotice)
                    <div class="pos-past-note" style="color:#991B1B; background:#FEF2F2; font-weight:700;">{{ $bookingWindowNotice }}</div>
                @endif
                @php $addonDateOpen = $bookingDate >= now()->format('Y-m-d'); @endphp
                @if ($addonPicking)
                    {{-- Tambah Alat ke Booking: pilih booking hari ini / ke depan — ketuk bloknya di jadwal atau cari. --}}
                    <div class="pos-addon-pick">
                        <div class="pos-addon-pick-text">
                            @if ($addonDateOpen)
                                Ketuk booking di jadwal yang mau ditambah alat, atau cari:
                            @else
                                Tanggal sudah lewat — tambah alat hanya untuk booking hari ini dan ke depan.
                                <button type="button" wire:click="today" class="pos-mini-btn">Ke Hari Ini</button>
                            @endif
                        </div>
                        <div class="pos-addon-pick-search">
                            <input type="text" wire:model.live.debounce.300ms="addonSearch" class="pos-input" autocomplete="off"
                                placeholder="Kode booking, nama, atau nomor HP">
                            @if ($this->addonSearchResults !== [])
                                <div class="pos-search-results">
                                    @foreach ($this->addonSearchResults as $res)
                                        <button type="button" wire:key="addon-res-{{ $res['id'] }}" wire:click="pickAddonBooking('{{ $res['id'] }}')" class="pos-search-item">
                                            <div style="font-weight:800; color:#4F2F2A;">{{ $res['customer'] }} &middot; #{{ $res['code'] }}</div>
                                            <div style="font-size:0.75rem; color:#662721;">{{ $res['court'] }} &bull; {{ $res['schedule'] }} WIB{{ $res['tab'] ? ' · Tagihan Berkala' : '' }}</div>
                                        </button>
                                    @endforeach
                                </div>
                            @elseif (mb_strlen(trim($addonSearch)) >= 2)
                                <div class="pos-addon-pick-empty">Tidak ada booking hari ini yang cocok.</div>
                            @endif
                        </div>
                    </div>
                @endif
                @if (empty($gridData))
                    <div class="pos-past-note">Belum ada lapangan aktif.</div>
                @else
                <div class="pos-timeline-scroll">
                    <div class="pos-timeline" style="--tl-court-w: {{ $courtColWidth }}px; grid-template-columns: minmax(var(--tl-court-w), var(--tl-court-max, max-content)) {{ $timelineColumns ?: 'minmax(44px, 1fr)' }}; min-width: calc(var(--tl-court-w) + {{ $hoursMinWidth }}px);">
                        <div class="tl-th tl-corner">Lapangan</div>
                        @foreach ($operationalHours as $i => $oh)
                            <div class="tl-th {{ ($firstCourtSlots[$i]['is_prime'] ?? false) ? 'is-prime' : '' }} {{ $nowHour === $oh['hour'] ? 'is-now' : '' }}"
                                wire:key="oh-head-{{ $oh['hour'] }}" title="{{ $oh['full_label'] }}">{{ ($deadHour[$i] ?? false) ? substr($oh['label'], 0, 2) : $oh['label'] }}</div>
                        @endforeach

                        @foreach ($gridData as $ci => $courtRow)
                            @php $court = $courtRow['court']; @endphp
                            <div class="tl-court" wire:key="court-row-{{ $court->id }}">
                                <div class="pos-court-name">{{ $court->name }}</div>
                                <div class="pos-court-meta">{{ $court->type }} &bull; Rp{{ number_format($court->hourly_rate_regular / 1000, 0) }}k</div>
                                <div class="pos-court-free">{{ collect($courtRow['slots'])->where('status', 'AVAILABLE')->count() }} jam kosong</div>
                            </div>
                            @foreach ($courtSegments[$ci] as $seg)
                                @php
                                    $slot = $seg['slot'];
                                    $st = $slot['status'];
                                    $rate = number_format($slot['rate'] / 1000, 0) . 'k';
                                @endphp
                                <div class="tl-cell {{ $nowHour === $slot['hour'] ? 'is-now' : '' }}" style="grid-column: span {{ $seg['span'] }};"
                                    wire:key="slot-cell-{{ $slot['slot_key'] }}">
                                    @if ($st === 'SELECTED')
                                        <button type="button"
                                            wire:click="toggleSlot('{{ $court->id }}', '{{ addslashes($court->name) }}', '{{ $slot['start_time'] }}', '{{ $slot['end_time'] }}', {{ $slot['rate'] }})"
                                            class="slot-btn slot-selected"
                                            title="Batal pilih: {{ $slot['full_label'] }} ({{ $court->name }})">
                                            <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" /></svg>
                                            <span class="prc">{{ $rate }}</span>
                                        </button>
                                    @elseif($st === 'TAKEN')
                                        <button type="button"
                                            wire:click="toggleSlot('{{ $court->id }}', '{{ addslashes($court->name) }}', '{{ $slot['start_time'] }}', '{{ $slot['end_time'] }}', {{ $slot['rate'] }})"
                                            class="slot-btn slot-taken"
                                            title="Baru saja diambil customer lain — klik untuk melepas dari pilihan">
                                            <span class="lbl">Diambil</span>
                                        </button>
                                    @elseif($st === 'AVAILABLE')
                                        <button type="button"
                                            wire:click="toggleSlot('{{ $court->id }}', '{{ addslashes($court->name) }}', '{{ $slot['start_time'] }}', '{{ $slot['end_time'] }}', {{ $slot['rate'] }})"
                                            class="slot-btn slot-available"
                                            title="Pilih: {{ $slot['full_label'] }} – Rp{{ number_format($slot['rate'], 0, ',', '.') }}">
                                            <span class="prc">{{ $rate }}</span>
                                        </button>
                                    @elseif($st === 'BOOKED' && $addonPicking && $addonDateOpen && ! empty($slot['booking']['addon_ok']))
                                        <button type="button" wire:click="pickAddonBooking('{{ $slot['booking']['id'] }}')"
                                            class="slot-btn slot-booked is-pickable"
                                            title="Tambah alat untuk {{ $slot['booking']['player'] ?? 'Pemain' }} (#{{ $slot['booking']['code'] }})">
                                            <span class="lbl">{{ $slot['booking']['player'] ?? 'Main' }}</span>
                                            <span class="sub">+ Alat</span>
                                        </button>
                                    @elseif($st === 'BOOKED')
                                        <div class="slot-btn slot-booked {{ ($bookingAddon['booking_id'] ?? null) && ($slot['booking']['id'] ?? null) === $bookingAddon['booking_id'] ? 'is-addon-target' : '' }}"
                                            title="Terisi: {{ $slot['booking']['player'] ?? 'Pemain' }} ({{ $slot['label'] }}–{{ $seg['end_label'] }}){{ ! empty($slot['booking']['tab']) ? ' — Tagihan Berkala, belum lunas' : '' }}">
                                            <span class="lbl">{{ $slot['booking']['player'] ?? 'Main' }}</span>
                                            @if (! empty($slot['booking']['tab']))<span class="sub">Tagihan Berkala</span>
                                            @elseif ($seg['span'] > 1)<span class="sub">{{ $slot['label'] }}–{{ $seg['end_label'] }}</span>@endif
                                        </div>
                                    @elseif($st === 'UNPAID_DELTA')
                                        <button type="button"
                                            wire:click="startSettlement('{{ $slot['booking']['id'] }}')"
                                            class="slot-btn slot-delta {{ $slot['booking']['is_active_bill'] ? 'is-active' : '' }}"
                                            title="Selisih reschedule belum dibayar: {{ $slot['booking']['player'] }} (#{{ $slot['booking']['code'] }}) — klik untuk melunasi">
                                            <span class="lbl">Bayar</span>
                                            <span class="sub">{{ $slot['booking']['player'] }}</span>
                                        </button>
                                    @elseif($st === 'LOCKED')
                                        <div class="slot-btn slot-locked" title="Hold di keranjang">
                                            <span class="lbl">Hold</span>
                                        </div>
                                    @elseif($st === 'CLOSED')
                                        <div class="slot-btn slot-past" title="{{ $bookingWindowNotice ?: 'Di luar jam operasional lapangan' }}"></div>
                                    @else
                                        <div class="slot-btn slot-past" title="Jam sudah lewat"></div>
                                    @endif
                                </div>
                            @endforeach
                        @endforeach
                    </div>
                </div>
                @endif
            </div>
        @elseif($posStep === 'payment')
            {{-- ==================== KIRI: TERMINAL PEMBAYARAN KASIR IN-PAGE ==================== --}}
            <div class="pos-terminal-card">
                <span hidden x-init="step = 4"></span>
                <div class="pos-terminal-header">
                    <div>
                        <span class="pos-terminal-eyebrow">TERMINAL KASIR LOKET</span>
                        <div class="pos-terminal-title">Layar Pembayaran &amp; Penyelesaian Transaksi</div>
                    </div>
                    <button type="button" wire:click="backToSelection" class="pos-btn pos-btn-ghost">
                        &larr; {{ $bookingAddon ? 'Ubah Pilihan Alat' : 'Ubah Pilihan Slot' }}
                    </button>
                </div>

                <div class="pos-terminal-body">
                    {{-- Slot sudah ditahan untuk transaksi ini (customer online melihatnya "In checkout"). --}}
                    @if (! $settleBill && $heldUntil)
                        <div wire:key="hold-banner-{{ $heldUntil }}" class="pos-hold-banner"
                            x-data="{ left: {{ max(0, (int) now()->diffInSeconds(\Illuminate\Support\Carbon::parse($heldUntil), false)) }}, init() { this.t = setInterval(() => { if (this.left > 0) this.left--; }, 1000); }, destroy() { clearInterval(this.t); } }"
                            x-bind:class="{ 'is-expired': left <= 0 }">
                            <span x-show="left > 0">Slot sudah ditahan untuk customer ini. Selesaikan pembayaran sebelum waktu habis.</span>
                            <span x-show="left <= 0" x-cloak>Waktu tahan habis. Saat Bayar, slot ditahan ulang kalau masih kosong.</span>
                            <span class="t" x-show="left > 0" x-text="String(Math.floor(left / 60)).padStart(2, '0') + ':' + String(left % 60).padStart(2, '0')"></span>
                        </div>
                    @endif

                    {{-- Form metode pembayaran bersama (dipakai juga oleh Kasir F&B) --}}
                    @if ($bookingAddon && $this->isAddonTab)
                        <div style="background:#EFF6FF; border:1px solid #BFDBFE; border-radius:12px; padding:0.9rem 1rem; color:#1E3A8A; font-size:0.875rem; font-weight:700; line-height:1.5;">
                            Alat untuk sesi coaching #{{ $bookingAddon['code'] }} dicatat pada tagihan berkala {{ $bookingAddon['academy'] }}. Tidak ada pembayaran EDC / QRIS saat ini; dilunasi bersama tagihan melalui Pelunasan Tagihan Coaching.
                        </div>
                    @elseif (! $settleBill && ! $bookingAddon && $this->grandTotal > 0 && $this->coachingTabAcademy)
                        <div style="background:#EFF6FF; border:1px solid #BFDBFE; border-radius:12px; padding:0.9rem 1rem; color:#1E3A8A; font-size:0.875rem; font-weight:700; line-height:1.5;">
                            Sesi ini dicatat pada tagihan berkala {{ $this->coachingTabAcademy->name }} ({{ $this->coachingTabAcademy->billingLabel() }}). Tidak ada pembayaran EDC / QRIS saat ini; tagihan dilunasi melalui Pelunasan Tagihan Coaching.
                        </div>
                    @elseif (! $settleBill && ! $bookingAddon && $this->grandTotal <= 0)
                        @php
                            $coveredBy = collect([
                                $this->membershipDiscountAmount > 0 ? 'kuota member' : null,
                                $this->sponsorDiscountAmount > 0 ? 'jam corporate' : null,
                                $appliedVoucherCode && $this->voucherDiscount > 0 ? 'voucher '.$appliedVoucherCode : null,
                            ])->filter()->implode(' + ');
                        @endphp
                        <div style="background:#ECFDF5; border:1px solid #A7F3D0; border-radius:12px; padding:0.9rem 1rem; color:#065F46; font-size:0.875rem; font-weight:700;">
                            Tagihan Rp0 &mdash; lunas penuh ditanggung {{ $coveredBy ?: 'benefit' }}. Tidak perlu EDC / QRIS, langsung selesaikan transaksi.
                        </div>
                    @else
                        @include("pos.partials.payment-method-form", ["grandTotal" => $this->grandTotal, "qrisMidtrans" => true])
                    @endif

                    {{-- Action Buttons --}}
                    <div class="pos-terminal-actions">
                        <button type="button" wire:click="backToSelection" class="pos-btn pos-btn-ghost" style="height:50px; padding:0 1.2rem;">
                            &larr; {{ $bookingAddon ? 'Ubah Pilihan Alat' : 'Kembali ke Pilih Jadwal' }}
                        </button>
                        {{-- Konfirmasi dulu (atas nama, jadwal, tambahan, total, metode) sebelum diproses. --}}
                        <button type="button" x-on:click="$dispatch('open-modal', { id: 'walkin-confirm-payment' })" wire:loading.attr="disabled" wire:target="submitWalkInBooking" class="pos-submit-btn" style="flex:1;">
                            <span wire:loading.remove wire:target="submitWalkInBooking">{{ ($bookingAddon && $this->isAddonTab) || (! $settleBill && ! $bookingAddon && $this->grandTotal > 0 && $this->coachingTabAcademy) ? 'Catat ke Tagihan Berkala' : 'Bayar Lunas & Cetak Struk' }}</span>
                            <span wire:loading wire:target="submitWalkInBooking">Memproses Transaksi...</span>
                        </button>
                    </div>
                </div>
            </div>
        @elseif($posStep === 'receipt')
            {{-- ==================== KIRI: STRUK POS RESMI IN-PAGE ==================== --}}
            <div class="pos-receipt-inpage">
                <span hidden x-init="step = 5"></span>
                <div class="pos-terminal-header">
                    <div>
                        <span class="pos-terminal-eyebrow is-done">TRANSAKSI SELESAI</span>
                        <div class="pos-terminal-title">Struk Pembayaran POS &amp; E-Tiket Walk-In</div>
                    </div>
                    <div style="display:flex; gap:0.5rem;">
                        <button type="button" onclick="club61PrintReceipt('#printable-pos-receipt')" class="pos-btn pos-btn-ghost">
                            Cetak Struk
                        </button>
                        <button type="button" wire:click="startNewTransaction" class="pos-btn pos-btn-primary">
                            Transaksi Baru
                        </button>
                    </div>
                </div>

                <div class="pos-receipt-stage">
                    @if ($completedOrderData)
                        @include('filament.partials.walkin-receipt', ['receipt' => $completedOrderData])
                    @endif
                </div>
            </div>
        @endif

        {{-- ==================== KANAN: CHECKOUT PANEL ==================== --}}
        <div class="pos-panel-card">
            {{-- Header --}}
            <div class="pos-panel-header">
                <div>
                    @if ($posStep === 'payment')
                        <div class="pos-panel-eyebrow">LANGKAH 4 DARI 4</div>
                        <div class="pos-panel-title">Ringkasan Tagihan</div>
                    @elseif($posStep === 'receipt')
                        <div class="pos-panel-eyebrow">TRANSAKSI SELESAI</div>
                        <div class="pos-panel-title">Detail Reservasi</div>
                    @else
                        <div class="pos-panel-eyebrow">POS Kasir Loket</div>
                        <div class="pos-panel-title">Walk-In Checkout</div>
                    @endif
                </div>
                <div>
                    <div class="pos-panel-total">Rp {{ number_format($this->grandTotal, 0, ',', '.') }}</div>
                    <div class="pos-panel-count">{{ $bookingAddon ? array_sum(array_map('intval', $rentalQuantities)).' alat' : count($selectedSlots).' slot dipilih' }}</div>
                </div>
            </div>

            {{-- Scrollable Body --}}
            <div class="pos-panel-body">

                @if ($settleBill)
                <div class="pos-col pos-col-cust is-settle" @if ($posStep === 'selection') x-bind:inert="step !== 2" @endif>
                    @if ($posStep === 'selection')<span hidden x-init="step = 2"></span>@endif
                    {{-- TAGIHAN SELISIH RESCHEDULE — customer & nominal terisi otomatis dari booking --}}
                    <div style="background:#FFFBEB; border:1.5px solid #FCD34D; border-radius:12px; padding:0.85rem; margin-bottom:0.75rem;">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:0.5rem;">
                            <div style="font-size:0.625rem; font-weight:900; color:#92400E; text-transform:uppercase; letter-spacing:0.05em;">{{ $settleBill['type'] }}</div>
                            @if ($posStep === 'selection')
                                <button type="button" wire:click="cancelSettlement"
                                    style="font-size:0.625rem; font-weight:800; color:#B91C1C; background:none; border:none; cursor:pointer; padding:0;">Batal</button>
                            @endif
                        </div>
                        <div style="font-size:0.875rem; font-weight:900; color:#4F2F2A; margin-top:0.2rem;">{{ $settleBill['customer'] }}</div>
                        @if (! empty($settleBill['phone']))
                            <div style="font-size:0.6875rem; color:#78350F;">{{ $settleBill['phone'] }}</div>
                        @endif
                        <div style="font-size:0.6875rem; color:#78350F; margin-top:0.35rem; line-height:1.45;">
                            <span style="font-family:var(--font-mono, monospace); font-weight:800;">#{{ $settleBill['code'] }}</span><br>
                            {{ $settleBill['court'] }} &bull; {{ $settleBill['schedule'] }}
                            @if ($settleBill['schedule_before'])
                                <br><span style="color:#A16207;">Dipindah dari: {{ $settleBill['schedule_before'] }}</span>
                            @endif
                        </div>
                        <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px dashed #FCD34D; margin-top:0.6rem; padding-top:0.5rem;">
                            <span style="font-size:0.75rem; font-weight:900; color:#92400E;">Selisih yang harus dibayar</span>
                            <span style="font-family:var(--font-mono, monospace); font-size:1rem; font-weight:900; color:#991B1B;">Rp {{ number_format($settleBill['amount'], 0, ',', '.') }}</span>
                        </div>
                        <div style="font-size:0.625rem; color:#B45309; margin-top:0.35rem;">QR tiket aktif &amp; customer bisa check-in setelah lunas.</div>
                    </div>
                </div>
                @else
                @if ($bookingAddon)
                <div class="pos-col pos-col-cust" @if ($posStep === 'selection') x-bind:inert="step !== 2" @endif>
                    {{-- TAMBAH ALAT KE BOOKING — customer otomatis dari booking --}}
                    @include('filament.partials.pos-addon-booking-card', ['addon' => $bookingAddon, 'canCancel' => $posStep === 'selection'])
                </div>
                @else
                <div class="pos-col pos-col-cust" @if ($posStep === 'selection') x-bind:inert="step !== 2" @endif>
                {{-- 1. DATA CUSTOMER --}}
                <div class="pos-customer-box">
                    <div
                        style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.4rem; margin-bottom:0.6rem;">
                        <span class="pos-section-label" style="margin-bottom:0;">{{ $this->isCoaching ? 'Booking Coaching' : 'Data Customer' }}</span>
                        @if ($posStep === 'selection')
                            {{-- Satu deret tab: Walk-In Baru | Cari Member | Coaching (Coaching hanya untuk izin book_coaching). --}}
                            <div class="pos-tab-group">
                                <button type="button" wire:click="setCustomerMode('quick_create')"
                                    class="pos-tab {{ $customerMode === 'quick_create' && ! $this->isCoaching ? 'active' : '' }}">Walk-In
                                    Baru</button>
                                <button type="button" wire:click="setCustomerMode('search')"
                                    class="pos-tab {{ $customerMode === 'search' && ! $this->isCoaching ? 'active' : '' }}">Cari
                                    Member</button>
                                @if ($this->canBookCoaching)
                                    <button type="button" wire:click="setCustomerMode('coaching')" data-coaching-tab
                                        class="pos-tab {{ $this->isCoaching ? 'active' : '' }}">Coaching</button>
                                @endif
                            </div>
                        @elseif ($this->isCoaching)
                            <span style="font-size:0.75rem; font-weight:900; color:#662721;">Coaching</span>
                        @endif
                    </div>

                    @if ($this->isCoaching)
                        {{-- Coaching (Modul 24 Opsi B): pemesan + coach dalam satu kotak; harga = tarif setoran coaching. --}}
                        <div data-coaching-box style="display:flex; flex-direction:column; gap:0.4rem;">
                            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:0.4rem;">
                                <input type="text" wire:model="walkInName" placeholder="Atas nama *" class="pos-input" autocomplete="off" {{ $posStep !== 'selection' ? 'disabled' : '' }}>
                                <input type="tel" wire:model="walkInPhone" placeholder="No. WhatsApp / Telp *" class="pos-input" autocomplete="off" {{ $posStep !== 'selection' ? 'disabled' : '' }}>
                            </div>
                            <select wire:model.live="coachingCoachId" class="pos-input" style="background:#FFFFFF;" {{ $posStep !== 'selection' ? 'disabled' : '' }}>
                                <option value="">Pilih coach utama *</option>
                                @foreach ($this->coachOptions as $coachId => $coachLabel)
                                    <option value="{{ $coachId }}">{{ $coachLabel }}</option>
                                @endforeach
                            </select>
                            @if (count($this->coachOptions) > 1)
                                <details {{ count($coachingExtraCoachIds) ? 'open' : '' }} style="background:#FFFFFF; border:1.5px solid #E6DAC0; border-radius:8px; padding:0.45rem 0.65rem;">
                                    <summary style="font-size:0.75rem; font-weight:800; color:#662721; cursor:pointer;">
                                        Coach tambahan{{ count($coachingExtraCoachIds) ? ' ('.count($coachingExtraCoachIds).')' : ' (opsional)' }}
                                    </summary>
                                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:0.25rem 0.75rem; margin-top:0.4rem;">
                                        @foreach ($this->coachOptions as $coachId => $coachLabel)
                                            @continue($coachId === $coachingCoachId)
                                            <label wire:key="extra-coach-{{ $coachId }}" style="display:flex; align-items:center; gap:0.4rem; font-size:0.75rem; color:#4F2F2A;">
                                                <input type="checkbox" value="{{ $coachId }}" wire:model.live="coachingExtraCoachIds" style="accent-color:#662721;" {{ $posStep !== 'selection' ? 'disabled' : '' }}>
                                                {{ $coachLabel }}
                                            </label>
                                        @endforeach
                                    </div>
                                </details>
                            @endif
                            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:0.4rem;">
                                <input type="text" wire:model="coachingStudents.0" maxlength="100" placeholder="Murid 1 (opsional)" class="pos-input" autocomplete="off" {{ $posStep !== 'selection' ? 'disabled' : '' }}>
                                <input type="text" wire:model="coachingStudents.1" maxlength="100" placeholder="Murid 2 (opsional)" class="pos-input" autocomplete="off" {{ $posStep !== 'selection' ? 'disabled' : '' }}>
                            </div>
                            <textarea wire:model="coachingNotes" maxlength="1000" rows="2" placeholder="Catatan (mis. ballboy, kebutuhan lapangan)" class="pos-input" style="resize:vertical;" {{ $posStep !== 'selection' ? 'disabled' : '' }}></textarea>
                            @if ($this->coachingQuote['error'])
                                <div style="font-size:0.6875rem; color:#B91C1C; font-weight:700; line-height:1.4;">{{ $this->coachingQuote['error'] }}</div>
                            @endif
                            @if ($tabAcademy = $this->coachingTabAcademy)
                                @php $tabOverdue = $this->coachingTabOverdue; @endphp
                                <div style="background:#EFF6FF; border:1px solid #BFDBFE; border-radius:8px; padding:0.45rem 0.65rem; font-size:0.6875rem; color:#1E3A8A; line-height:1.45;">
                                    <b>{{ $tabAcademy->name }} menggunakan {{ $tabAcademy->billingLabel() }}.</b> Sewa lapangan tidak dibayar saat booking; sesi dicatat pada tagihan academy dan dilunasi sesuai periode tagihan.
                                </div>
                                @if ($tabOverdue['sessions'] > 0)
                                    <div style="background:#FEF2F2; border:1px solid #FECACA; border-radius:8px; padding:0.45rem 0.65rem; font-size:0.6875rem; color:#991B1B; font-weight:700; line-height:1.45;">
                                        Tagihan melewati jatuh tempo: {{ $tabOverdue['sessions'] }} sesi, Rp {{ number_format($tabOverdue['amount'], 0, ',', '.') }}.
                                        {{ $tabAcademy->block_when_overdue ? 'Booking baru tidak dapat dibuat sampai tagihan dilunasi.' : 'Mohon ingatkan academy untuk melakukan pelunasan.' }}
                                    </div>
                                @endif
                            @endif
                            <div style="font-size:0.5625rem; color:#9CA3AF; line-height:1.4;">Harga lapangan memakai tarif setoran coaching. Membership, voucher jam corporate &amp; kode promo tidak dipakai. Nomor HP lama otomatis dikenali.</div>
                        </div>
                    @elseif ($customerMode === 'quick_create')
                        <div style="display:flex; flex-direction:column; gap:0.4rem;">
                            <input type="text" wire:model="walkInName" placeholder="Nama Lengkap *"
                                class="pos-input" autocomplete="off"
                                {{ $posStep !== 'selection' ? 'disabled' : '' }}>
                            <input type="tel" wire:model="walkInPhone" placeholder="No. WhatsApp / Telp *"
                                class="pos-input" autocomplete="off"
                                {{ $posStep !== 'selection' ? 'disabled' : '' }}>
                            <input type="email" wire:model="walkInEmail" placeholder="Email (opsional)"
                                class="pos-input" autocomplete="off"
                                {{ $posStep !== 'selection' ? 'disabled' : '' }}>
                            <div style="font-size:0.5625rem; color:#9CA3AF;">Nomor HP lama otomatis dikenali — tidak
                                buat akun ganda.</div>
                            {{-- Nomor HP akun lama (member / karyawan sponsor): benefitnya langsung dipakai. --}}
                            @include('filament.partials.walkin-benefit-badges', ['benefitQuote' => $this->benefitQuote])
                        </div>
                    @else
                        @if ($selectedCustomerId)
                            <div
                                style="background:#FFFFFF; border:1.5px solid #662721; border-radius:8px; padding:0.5rem 0.65rem; display:flex; justify-content:space-between; align-items:center;">
                                <div>
                                    <div style="font-weight:900; color:#4F2F2A; font-size:0.8125rem;">
                                        {{ $selectedCustomerName }}</div>
                                    <div style="font-size:0.6875rem; color:#662721;">
                                        {{ $selectedCustomerPhone ?? '-' }}</div>
                                    @include('filament.partials.walkin-benefit-badges', ['benefitQuote' => $this->benefitQuote])
                                </div>
                                @if ($posStep === 'selection')
                                    <button type="button" wire:click="clearSelectedCustomer" class="pos-touch"
                                        style="background:#FEF2F2; border:1px solid #FECACA; color:#DC2626; font-size:0.625rem; font-weight:800; padding:0.2rem 0.45rem; border-radius:5px; cursor:pointer;">
                                        Ganti
                                    </button>
                                @endif
                            </div>
                        @else
                            <div style="position:relative;">
                                <input type="text" wire:model.live.debounce.300ms="customerSearch"
                                    placeholder="Cari nama atau nomor telp..." class="pos-input" autocomplete="off"
                                    {{ $posStep !== 'selection' ? 'disabled' : '' }}>
                                @if (count($searchResults) > 0)
                                    <div
                                        style="position:absolute; top:100%; left:0; right:0; z-index:50; background:#FFFFFF; border:1.5px solid #E6DAC0; border-radius:8px; box-shadow:0 8px 20px rgba(0,0,0,0.1); margin-top:0.2rem; max-height:140px; overflow-y:auto;">
                                        @foreach ($searchResults as $res)
                                            <button type="button" wire:key="search-result-{{ $res->id }}"
                                                wire:click="selectCustomer('{{ $res->id }}')" class="pos-touch"
                                                style="width:100%; text-align:left; padding:0.4rem 0.65rem; border:none; border-bottom:1px solid #EFE6D2; background:#FFFFFF; cursor:pointer; font-size:0.75rem;"
                                                onmouseover="this.style.background='#FCF8EE'"
                                                onmouseout="this.style.background='#FFFFFF'">
                                                <div style="font-weight:800; color:#4F2F2A;">{{ $res->name }}
                                                </div>
                                                <div style="font-size:0.625rem; color:#662721;">
                                                    {{ $res->phone ?? $res->email }}</div>
                                            </button>
                                        @endforeach
                                    </div>
                                @elseif(strlen(trim($customerSearch)) >= 2)
                                    <div
                                        style="font-size:0.625rem; color:#9CA3AF; margin-top:0.25rem; font-style:italic;">
                                        Tidak ditemukan — pakai tab Walk-In Baru.</div>
                                @endif
                            </div>
                        @endif
                    @endif
                </div>
                </div>{{-- end pos-col-cust --}}
                @endif

                <div class="pos-col pos-col-items" @if ($posStep === 'selection') x-bind:inert="step !== 3" @endif>
                @if ($bookingAddon)
                    {{-- Di langkah Tambahan saja; di layar bayar kartunya sudah tampil di kolom customer (panel kanan). --}}
                    @if ($posStep === 'selection')
                        @include('filament.partials.pos-addon-booking-card', ['addon' => $bookingAddon, 'canCancel' => false])
                    @endif
                @else
                {{-- 2. SLOT TERPILIH --}}
                <div>
                    <div
                        style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.4rem;">
                        <span class="pos-section-label">Slot Dipilih ({{ count($selectedSlots) }})</span>
                        <span style="display:flex; align-items:center; gap:0.5rem;">
                            @if ($posStep === 'selection' && count($selectedSlots) > 0)
                                <button type="button" wire:click="clearSelectedSlots" class="pos-link-danger">Hapus {{ count($selectedSlots) }} Slot</button>
                            @endif
                            <span style="font-size:0.6875rem; font-weight:800; color:#662721;">Rp
                                {{ number_format($this->courtTotal, 0, ',', '.') }}</span>
                        </span>
                    </div>
                    @if (empty($selectedSlots))
                        <div
                            style="background:#F9FAFB; border:1px dashed #D1D5DB; border-radius:8px; padding:0.85rem; text-align:center; color:#9CA3AF; font-size:0.6875rem;">
                            Ketuk kotak jam di jadwal untuk memilih slot.
                        </div>
                    @else
                        <div style="display:flex; flex-direction:column; gap:0.3rem;">
                            @foreach ($selectedSlots as $sKey => $s)
                                <div wire:key="selected-slot-{{ $sKey }}" class="pos-slot-chip {{ in_array($sKey, $takenSlotKeys, true) ? 'is-taken' : '' }}">
                                    <div>
                                        <div style="font-weight:800; font-size:0.6875rem; color:#4F2F2A;">
                                            {{ $s['court_name'] }}</div>
                                        <div style="font-size:0.5625rem; color:#662721;">{{ $s['time_label'] }} WIB
                                        </div>
                                        @if (in_array($sKey, $takenSlotKeys, true))
                                            <div class="pos-slot-taken-note">Baru diambil customer lain — hapus &amp; pilih jam lain</div>
                                        @endif
                                    </div>
                                    <div style="display:flex; align-items:center; gap:0.4rem;">
                                        <span
                                            style="font-weight:900; font-size:0.75rem; color:#662721;">Rp{{ number_format($this->slotDisplayPrice($s), 0, ',', '.') }}</span>
                                        @if ($posStep === 'selection')
                                            <button type="button" wire:click="removeSlot('{{ $sKey }}')" class="pos-slot-remove" aria-label="Hapus slot">&times;</button>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
                @endif

                {{-- 3. SEWA ALAT --}}
                @if (count($equipments) > 0)
                    <div>
                        <div
                            style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.4rem;">
                            <span class="pos-section-label">{{ $bookingAddon ? 'Pilih Alat' : 'Sewa Alat (Opsional)' }}</span>
                            <span style="font-size:0.6875rem; font-weight:800; color:#662721;">Rp
                                {{ number_format($this->equipmentTotal, 0, ',', '.') }}</span>
                        </div>
                        <div class="pos-eq-list"
                            style="background:#FCF8EE; border:1px solid #E6DAC0; border-radius:8px; padding:0.35rem 0.65rem;">
                            @foreach ($equipments as $eq)
                                @php $qty = $rentalQuantities[$eq->id] ?? 0; @endphp
                                <div wire:key="equipment-row-{{ $eq->id }}" class="pos-eq-row">
                                    <div>
                                        <div style="font-weight:800; color:#4F2F2A; font-size:0.6875rem;">
                                            {{ $eq->name }}</div>
                                        <div style="font-size:0.5625rem; color:#662721;">
                                            Rp{{ number_format($eq->rental_price, 0, ',', '.') }} &bull; Stok:
                                            {{ $eq->stock_quantity }}</div>
                                    </div>
                                    <div class="pos-qty-ctrl">
                                        @if ($posStep === 'selection')
                                            <button type="button"
                                                wire:click="decrementEquipment('{{ $eq->id }}')"
                                                class="pos-qty-btn" aria-label="Kurangi {{ $eq->name }}">-</button>
                                        @endif
                                        <span class="pos-qty-val">{{ $qty }}</span>
                                        @if ($posStep === 'selection')
                                            <button type="button"
                                                wire:click="incrementEquipment('{{ $eq->id }}', {{ $eq->stock_quantity }})"
                                                class="pos-qty-btn" aria-label="Tambah {{ $eq->name }}">+</button>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- 3b. VOUCHER (promo / voucher saldo customer — Modul 21). Coaching: tarif khusus, tanpa voucher. --}}
                @if (! $settleBill && ! $this->isCoaching && ! $bookingAddon)
                    <div style="margin-bottom:0.6rem;">
                        <div class="pos-section-label" style="margin-bottom:0.35rem;">Voucher</div>
                        @if ($appliedVoucherCode)
                            <div style="display:flex; justify-content:space-between; align-items:center; gap:0.5rem; background:#ECFDF5; border:1px solid #A7F3D0; border-radius:8px; padding:0.45rem 0.6rem;">
                                <div style="min-width:0;">
                                    <div style="font-family:var(--font-mono, monospace); font-weight:900; font-size:0.75rem; color:#065F46;">{{ $appliedVoucherCode }}</div>
                                    @if ($this->voucherResult['error'])
                                        <div style="font-size:0.6875rem; color:#B91C1C; font-weight:700;">{{ $this->voucherResult['error'] }}</div>
                                    @elseif ($this->voucherResult['voucher'])
                                        @php
                                            $voucherTerms = app(\App\Services\Finance\PromoVoucherService::class)->terms($this->voucherResult['voucher']);
                                        @endphp
                                        <div data-voucher-terms style="font-size:0.6875rem; color:#047857; line-height:1.45;">
                                            @if ($voucherTerms['name'])
                                                <div style="font-weight:800; color:#065F46;">{{ $voucherTerms['name'] }}</div>
                                            @endif
                                            <div>{{ $voucherTerms['headline'] }} &rarr; <strong>potongan Rp {{ number_format($this->voucherDiscount, 0, ',', '.') }}</strong></div>
                                            @if ($voucherTerms['rules'])
                                                <div style="color:#5F7F6E;">{{ implode(' · ', $voucherTerms['rules']) }}</div>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                                <button type="button" wire:click="removeVoucher" class="pos-touch" style="font-size:0.6875rem; font-weight:800; color:#B91C1C; background:none; border:none; cursor:pointer;">Hapus</button>
                            </div>
                        @else
                            <div style="display:flex; gap:0.4rem;">
                                <input type="text" wire:model="voucherInput" wire:keydown.enter.prevent="applyVoucher" maxlength="30" placeholder="Kode voucher"
                                    style="flex:1; min-width:0; border:1.5px solid #E6DAC0; border-radius:8px; padding:0.4rem 0.55rem; font-size:0.75rem; font-family:var(--font-mono, monospace); text-transform:uppercase;">
                                <button type="button" wire:click="applyVoucher" wire:loading.attr="disabled" class="pos-touch"
                                    style="padding:0.4rem 0.75rem; border-radius:8px; border:1.5px solid #E6DAC0; background:#FCF8EE; color:#662721; font-weight:800; font-size:0.75rem; cursor:pointer;">Pakai</button>
                            </div>
                            @foreach ($this->customerClaimedPromos as $cp)
                                <div data-pos-claimed-promo style="display:flex; justify-content:space-between; align-items:center; gap:0.5rem; margin-top:0.35rem; background:#FEF2F2; border:1px dashed #FCA5A5; border-radius:8px; padding:0.35rem 0.55rem;">
                                    <div style="font-size:0.6875rem; color:#991B1B; min-width:0; overflow-wrap:anywhere;">
                                        <strong>{{ $cp['name'] ?: $cp['code'] }}</strong> &middot; {{ $cp['headline'] }} <span style="opacity:0.75;">(promo diklaim)</span>
                                    </div>
                                    <button type="button" wire:click="applyVoucher('{{ $cp['code'] }}')" class="pos-touch"
                                        style="font-size:0.6875rem; font-weight:800; color:#B91C1C; background:none; border:none; cursor:pointer; flex-shrink:0;">Pakai</button>
                                </div>
                            @endforeach
                            @foreach ($this->customerCreditVouchers as $cv)
                                <div style="display:flex; justify-content:space-between; align-items:center; gap:0.5rem; margin-top:0.35rem; background:#F0FDF4; border:1px dashed #86EFAC; border-radius:8px; padding:0.35rem 0.55rem;">
                                    <div style="font-size:0.6875rem; color:#065F46;">
                                        <strong style="font-family:var(--font-mono, monospace);">{{ $cv['code'] }}</strong>
                                        &middot; saldo Rp {{ number_format($cv['available'], 0, ',', '.') }}
                                    </div>
                                    <button type="button" wire:click="applyVoucher('{{ $cv['code'] }}')" class="pos-touch"
                                        style="font-size:0.6875rem; font-weight:800; color:#047857; background:none; border:none; cursor:pointer;">Pakai</button>
                                </div>
                            @endforeach
                        @endif
                    </div>
                @endif
                </div>{{-- end pos-col-items --}}
                @endif

                <div class="pos-col pos-col-sum" @if ($posStep === 'selection') x-bind:inert="step !== 3" @endif>
                @if ($bookingAddon)
                {{-- 4. TOTAL TAMBAH ALAT (dihitung server, sama dengan yang disimpan) --}}
                @php $addonQuote = $this->bookingAddonQuote; @endphp
                <div class="pos-total-box" data-addon-total>
                    <div style="display:flex; justify-content:space-between; font-size:0.75rem; color:#7A5A52; margin-bottom:0.2rem;">
                        <span>Alat:</span><span>Rp {{ number_format($addonQuote['subtotal'] ?? 0, 0, ',', '.') }}</span>
                    </div>
                    @if (($addonQuote['tax_amount'] ?? 0) > 0)
                        <div style="display:flex; justify-content:space-between; font-size:0.75rem; color:#7A5A52; margin-bottom:0.2rem;">
                            <span>{{ $this->taxName }}:</span><span>Rp {{ number_format($addonQuote['tax_amount'], 0, ',', '.') }}</span>
                        </div>
                    @endif
                    @if (($addonQuote['service_charge'] ?? 0) > 0)
                        <div style="display:flex; justify-content:space-between; font-size:0.75rem; color:#7A5A52; margin-bottom:0.2rem;">
                            <span>{{ $this->adminFeeName }}:</span><span>Rp {{ number_format($addonQuote['service_charge'], 0, ',', '.') }}</span>
                        </div>
                    @endif
                    <div style="border-top:1.5px dashed #662721; padding-top:0.4rem; display:flex; justify-content:space-between; align-items:baseline;">
                        <span style="font-size:0.8125rem; font-weight:900; color:#4F2F2A;">{{ $this->isAddonTab ? 'DITAMBAHKAN KE TAGIHAN:' : 'TOTAL:' }}</span>
                        <span style="font-size:1.1875rem; font-weight:900; color:#662721;">Rp {{ number_format($this->grandTotal, 0, ',', '.') }}</span>
                    </div>
                    @if ($this->isAddonTab && $addonQuote)
                        <div style="font-size:0.75rem; color:#1E3A8A; margin-top:0.4rem;">
                            Tagihan {{ $bookingAddon['academy'] }}: Rp {{ number_format($addonQuote['tab_before'], 0, ',', '.') }} &rarr; Rp {{ number_format($addonQuote['tab_after'], 0, ',', '.') }}
                        </div>
                    @endif
                </div>
                @elseif (! $settleBill)
                {{-- 4. TOTAL --}}
                <div class="pos-total-box">
                    <div
                        style="display:flex; justify-content:space-between; font-size:0.6875rem; color:#7A5A52; margin-bottom:0.2rem;">
                        <span>{{ $this->isCoaching ? 'Lapangan (tarif coaching):' : 'Lapangan:' }}</span><span>Rp {{ number_format($this->courtTotal, 0, ',', '.') }}</span>
                    </div>
                    <div
                        style="display:flex; justify-content:space-between; font-size:0.6875rem; color:#7A5A52; margin-bottom:0.2rem;">
                        <span>Sewa Alat:</span><span>Rp {{ number_format($this->equipmentTotal, 0, ',', '.') }}</span>
                    </div>
                    @if ($this->membershipDiscountAmount > 0)
                        <div
                            style="display:flex; justify-content:space-between; font-size:0.6875rem; color:#047857; font-weight:800; margin-bottom:0.2rem;">
                            <span>Diskon Membership ({{ $activeMembershipInfo['plan_name'] ?? 'Member' }}):</span>
                            <span>- Rp {{ number_format($this->membershipDiscountAmount, 0, ',', '.') }}</span>
                        </div>
                    @endif
                    @if ($this->sponsorDiscountAmount > 0)
                        <div
                            style="display:flex; justify-content:space-between; font-size:0.6875rem; color:#047857; font-weight:800; margin-bottom:0.2rem;">
                            <span>Jam Corporate ({{ $this->benefitQuote['sponsor']['organization_name'] ?? 'Sponsor' }}, {{ rtrim(rtrim(number_format($this->benefitQuote['sponsor']['hours'], 1, ',', '.'), '0'), ',') }} jam):</span>
                            <span>- Rp {{ number_format($this->sponsorDiscountAmount, 0, ',', '.') }}</span>
                        </div>
                    @endif
                    @if ($this->voucherDiscount > 0)
                        <div
                            style="display:flex; justify-content:space-between; font-size:0.6875rem; color:#047857; font-weight:800; margin-bottom:0.2rem;">
                            <span>Voucher {{ $appliedVoucherCode }}:</span>
                            <span>- Rp {{ number_format($this->voucherDiscount, 0, ',', '.') }}</span>
                        </div>
                    @endif
                    @if ($this->isTaxEnabled && $this->taxAmount > 0)
                        <div
                            style="display:flex; justify-content:space-between; font-size:0.6875rem; color:#7A5A52; margin-bottom:0.2rem;">
                            <span>{{ $this->taxName }}:</span><span>Rp
                                {{ number_format($this->taxAmount, 0, ',', '.') }}</span>
                        </div>
                    @endif
                    @if ($this->isAdminFeeEnabled && $this->adminFeeAmount > 0)
                        <div
                            style="display:flex; justify-content:space-between; font-size:0.6875rem; color:#7A5A52; margin-bottom:0.2rem;">
                            <span>{{ $this->adminFeeName }}:</span><span>Rp
                                {{ number_format($this->adminFeeAmount, 0, ',', '.') }}</span>
                        </div>
                    @endif
                    <div
                        style="border-top:1.5px dashed #662721; padding-top:0.4rem; display:flex; justify-content:space-between; align-items:baseline;">
                        <span style="font-size:0.8125rem; font-weight:900; color:#4F2F2A;">TOTAL:</span>
                        <span style="font-size:1.1875rem; font-weight:900; color:#662721;">Rp
                            {{ number_format($this->grandTotal, 0, ',', '.') }}</span>
                    </div>
                </div>
                @endif

                {{-- 5. METODE BAYAR (Ringkasan saat step payment / receipt) --}}
                @if ($posStep !== 'selection')
                    <div>
                        <div class="pos-section-label" style="margin-bottom:0.4rem;">Metode Pembayaran</div>
                        <div
                            style="background:#FCF8EE; border:1.5px solid #E6DAC0; border-radius:8px; padding:0.45rem 0.65rem; font-size:0.75rem; font-weight:800; color:#662721;">
                            {{ match ($paymentMethod) {
                                'DEBIT_CARD', 'DEBIT' => 'Kartu Debit (EDC)',
                                'CREDIT_CARD', 'CREDIT' => 'Kartu Kredit (EDC)',
                                'EDC_BCA' => 'Mesin EDC BCA',
                                'EDC_MANDIRI' => 'Mesin EDC Mandiri',
                                'QRIS', 'QRIS_STATIS' => 'QRIS Kasir Frontdesk',
                                default => $paymentMethod,
                            } }}
                        </div>
                    </div>
                @endif

                @if (! $settleBill && ! $bookingAddon)
                {{-- 6. AUTO CHECK-IN --}}
                <label
                    style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; background:#F0FDF4; border:1px solid #BBF7D0; border-radius:7px; padding:0.45rem 0.65rem;">
                    <input type="checkbox" wire:model="isAutoCheckIn"
                        style="accent-color:#10B981; width:15px; height:15px;">
                    <div>
                        <div style="font-size:0.6875rem; font-weight:900; color:#166534;">Langsung Check-In (Software)
                        </div>
                        <div style="font-size:0.5625rem; color:#15803D;">Status jadi CHECKED_IN saat order dibuat.
                        </div>
                    </div>
                </label>

                @endif
                </div>{{-- end pos-col-sum --}}
            </div>{{-- end pos-panel-body --}}

            {{-- Footer: Action Button --}}
            <div class="pos-panel-footer">
                @if ($posStep === 'selection')
                    <button type="button" wire:click="proceedToPayment" class="pos-submit-btn">
                        <span>Lanjut ke Pembayaran &rarr;</span>
                    </button>
                @elseif($posStep === 'payment')
                    <button type="button" wire:click="backToSelection"
                        style="width:100%; padding:0.65rem; border-radius:10px; border:1.5px solid #E6DAC0; background:#FFFFFF; color:#4F2F2A; font-weight:800; font-size:0.8125rem; cursor:pointer;">
                        &larr; {{ $bookingAddon ? 'Ubah Pilihan Alat' : 'Ubah Pilihan Slot' }}
                    </button>
                @elseif($posStep === 'receipt')
                    <button type="button" wire:click="startNewTransaction" class="pos-submit-btn">
                        <span>Mulai Transaksi Baru</span>
                    </button>
                @endif
            </div>
        </div>{{-- end pos-panel-card --}}

    </div>{{-- end pos-main --}}
    @if ($posStep === 'selection')
    </div>{{-- end pos-stage --}}

    @php
        $firstSlot = collect($selectedSlots)->first();
        $slotSummary = $firstSlot
            ? $firstSlot['court_name'] . ' ' . $firstSlot['time_label'] . (count($selectedSlots) > 1 ? ' +' . (count($selectedSlots) - 1) . ' slot' : '')
            : 'Belum pilih jam';
    @endphp
    <div class="pos-actionbar">
        <div class="pos-actionbar-summary">
            <div class="pos-sum-item">
                <span class="k">{{ $this->isBookingAddonMode ? 'Tambah alat untuk' : 'Jadwal' }}</span>
                <span class="v">{{ $settleBill ? ($settleBill['court'] . ' • ' . $settleBill['schedule'])
                    : ($bookingAddon ? ('#'.$bookingAddon['code'].' • '.$bookingAddon['court'].' '.$bookingAddon['schedule'])
                    : ($addonPicking ? 'Belum pilih booking' : $slotSummary)) }}</span>
            </div>
            <div class="pos-sum-item">
                <span class="k">Atas nama</span>
                @if ($settleBill)
                    <span class="v">{{ $settleBill['customer'] }}</span>
                @elseif ($bookingAddon)
                    <span class="v">{{ $bookingAddon['customer'] }}</span>
                @elseif ($customerMode === 'search')
                    <span class="v">{{ $selectedCustomerName ?: 'Belum dipilih' }}</span>
                @else
                    <span class="v" x-text="String($wire.walkInName || '').trim() || 'Belum diisi'">{{ $walkInName ?: 'Belum diisi' }}</span>
                @endif
            </div>
            <div class="pos-sum-item is-total">
                <span class="k">Total</span>
                <span class="v">Rp {{ number_format($settleBill ? $settleBill['amount'] : $this->grandTotal, 0, ',', '.') }}</span>
            </div>
        </div>
        <div class="pos-actionbar-actions">
            <button type="button" class="pos-btn pos-btn-ghost pos-actionbar-back" x-show="step > 1" x-cloak @click="prev()">&larr; Kembali</button>
            @if ($this->isBookingAddonMode)
                {{-- Tambah Alat: customer dari booking → langsung ke Tambahan. --}}
                <button type="button" class="pos-submit-btn pos-actionbar-next" x-show="step === 1" {{ $bookingAddon ? '' : 'disabled' }}
                    @click="go(3)">{{ $bookingAddon ? 'Lanjut: Pilih Alat →' : 'Pilih booking dulu' }}</button>
            @else
                <button type="button" class="pos-submit-btn pos-actionbar-next" x-show="step === 1" {{ count($selectedSlots) === 0 && ! $settleBill ? 'disabled' : '' }}
                    @click="go(2)">Lanjut: Data Customer &rarr;</button>
            @endif
            @if ($settleBill)
                <button type="button" class="pos-submit-btn pos-actionbar-next" x-show="step === 2" x-cloak @click="toPayment()" :disabled="busy">Lanjut ke Pembayaran &rarr;</button>
            @else
                <button type="button" class="pos-submit-btn pos-actionbar-next" x-show="step === 2" x-cloak @click="go(3)">Lanjut: Tambahan &rarr;</button>
            @endif
            <button type="button" class="pos-submit-btn pos-actionbar-next" x-show="step === 3" x-cloak @click="toPayment()" :disabled="busy">
                <span x-show="! busy">Lanjut ke Pembayaran &rarr;</span><span x-show="busy" x-cloak>Memeriksa...</span>
            </button>
        </div>
    </div>
    @endif
    @endif

    @if ($posStep === 'payment')
        @php
            $confirmName = $settleBill['customer'] ?? $bookingAddon['customer'] ?? ($selectedCustomerId ? $selectedCustomerName : ($walkInName ?: '-'));
            $confirmPhone = $settleBill['phone'] ?? $bookingAddon['phone'] ?? ($selectedCustomerId ? $selectedCustomerPhone : $walkInPhone);
            $confirmMethod = match ($paymentMethod) {
                'DEBIT_CARD', 'DEBIT' => 'Kartu Debit (EDC)',
                'CREDIT_CARD', 'CREDIT' => 'Kartu Kredit (EDC)',
                'EDC_BCA' => 'Mesin EDC BCA',
                'EDC_MANDIRI' => 'Mesin EDC Mandiri',
                'QRIS', 'QRIS_STATIS' => $qrisMode === 'MIDTRANS' ? 'QRIS (QR otomatis)' : 'QRIS Kasir Frontdesk',
                default => $paymentMethod,
            };
            $confirmToTab = ! $settleBill && ! $bookingAddon && $this->grandTotal > 0 && $this->coachingTabAcademy;
            if ($bookingAddon && $this->isAddonTab) {
                $confirmToTab = true;
                $confirmMethod = 'Dicatat pada tagihan '.$bookingAddon['academy'];
            } elseif ($confirmToTab) {
                $confirmMethod = 'Dicatat pada tagihan '.$this->coachingTabAcademy->name.' ('.$this->coachingTabAcademy->billingLabel().')';
            } elseif (! $settleBill && ! $bookingAddon && $this->grandTotal <= 0) {
                $confirmMethod = 'Gratis — ditanggung benefit (tanpa pembayaran)';
            }
            $confirmAddons = collect($equipments)->filter(fn ($eq) => ($rentalQuantities[$eq->id] ?? 0) > 0);
        @endphp
        <x-filament::modal id="walkin-confirm-payment" width="lg" :close-by-clicking-away="false">
            <x-slot name="heading">Pastikan Pesanan Sudah Benar</x-slot>
            <x-slot name="description">Periksa bersama customer sebelum pembayaran diproses.</x-slot>

            <div class="pos-confirm">
                <div class="pos-confirm-row"><span>Atas nama</span><strong>{{ $confirmName }}@if ($confirmPhone)<small>{{ $confirmPhone }}</small>@endif</strong></div>
                <div class="pos-confirm-row"><span>Tanggal</span><strong>{{ \Carbon\Carbon::parse($bookingAddon['date'] ?? $bookingDate)->translatedFormat('l, d F Y') }}</strong></div>
                <div class="pos-confirm-row"><span>Jadwal</span>
                    <strong>
                        @if ($settleBill)
                            {{ $settleBill['court'] }} &bull; {{ $settleBill['schedule'] }}
                        @elseif ($bookingAddon)
                            <span class="pos-confirm-line">Tambah alat untuk #{{ $bookingAddon['code'] }}</span>
                            <span class="pos-confirm-line">{{ $bookingAddon['court'] }} &bull; {{ $bookingAddon['schedule'] }}</span>
                        @else
                            @foreach ($selectedSlots as $s)
                                <span class="pos-confirm-line">{{ $s['court_name'] }} &bull; {{ $s['time_label'] }} WIB</span>
                            @endforeach
                        @endif
                    </strong>
                </div>
                @if ($this->isCoaching)
                    @php
                        $confirmCoaches = collect([$coachingCoachId])->merge($coachingExtraCoachIds)->filter()->unique()
                            ->map(fn ($id) => $this->coachOptions[$id] ?? null)->filter();
                    @endphp
                    <div class="pos-confirm-row"><span>Coaching</span>
                        <strong>
                            @foreach ($confirmCoaches as $coachLabel)
                                <span class="pos-confirm-line">{{ $coachLabel }}</span>
                            @endforeach
                            @if (array_filter($coachingStudents))
                                <span class="pos-confirm-line">Murid: {{ implode(', ', array_filter($coachingStudents)) }}</span>
                            @endif
                            @if (trim($coachingNotes) !== '')
                                <span class="pos-confirm-line">Catatan: {{ $coachingNotes }}</span>
                            @endif
                        </strong>
                    </div>
                @endif
                @unless ($settleBill)
                    <div class="pos-confirm-row"><span>Tambahan</span>
                        <strong>
                            @forelse ($confirmAddons as $eq)
                                <span class="pos-confirm-line">{{ $rentalQuantities[$eq->id] }}&times; {{ $eq->name }}</span>
                            @empty
                                <span class="pos-confirm-muted">Tidak ada</span>
                            @endforelse
                            @if ($appliedVoucherCode && ! $this->voucherResult['error'])
                                <span class="pos-confirm-line">Voucher {{ $appliedVoucherCode }}</span>
                            @endif
                        </strong>
                    </div>
                @endunless
                <div class="pos-confirm-row"><span>Metode bayar</span><strong>{{ $confirmMethod }}</strong></div>
                <div class="pos-confirm-total"><span>Total</span><strong>Rp {{ number_format($settleBill ? $settleBill['amount'] : $this->grandTotal, 0, ',', '.') }}</strong></div>
                {{-- Ketentuan yang sama dengan checkout online — dibacakan kasir ke customer sebelum bayar. --}}
                @unless ($bookingAddon)
                <div class="pos-confirm-policy">
                    <b>Ketentuan booking</b>
                    <ul>
                        <li>Reschedule hanya bisa sampai <strong>H-3</strong> (3 hari sebelum jam main).</li>
                        <li><strong>Tidak ada refund</strong> setelah booking dibayar.</li>
                        <li>Salah pilih tanggal, lapangan, atau jam menjadi <strong>tanggung jawab pemesan</strong>.</li>
                    </ul>
                </div>
                @endunless
            </div>

            <x-slot name="footer">
                <div class="pos-confirm-actions">
                    <button type="button" class="pos-btn pos-btn-ghost" x-on:click="$dispatch('close-modal', { id: 'walkin-confirm-payment' })">Periksa Lagi</button>
                    <button type="button" class="pos-btn pos-btn-primary" wire:click="submitWalkInBooking" wire:loading.attr="disabled" wire:target="submitWalkInBooking"
                        x-on:click="$dispatch('close-modal', { id: 'walkin-confirm-payment' })">
                        {{ $confirmToTab ? 'Ya, Catat ke Tagihan Berkala' : 'Ya, Proses Pembayaran' }}
                    </button>
                </div>
            </x-slot>
        </x-filament::modal>
    @endif

    {{-- QR Midtrans menunggu dibayar customer --}}
    @include('pos.partials.auto-pay-modal', ['pendingQris' => $pendingQris, 'pollAction' => 'pollPendingQris', 'cancelAction' => 'cancelPendingQris', 'simulateAction' => 'simulatePendingQrisPaid'])

    {{-- ============================
     MODAL SUKSES – STRUK POS
     ============================ --}}
    @if ($showSuccessModal && $completedOrderData)
        <div
            style="position:fixed; inset:0; z-index:9999; background:rgba(15,23,42,0.65); backdrop-filter:blur(6px); display:flex; align-items:center; justify-content:center; padding:1rem;">
            <div
                style="background:#FFFFFF; border:1.5px solid #E6DAC0; border-radius:18px; box-shadow:0 25px 50px -12px rgba(102,39,33,0.4); width:100%; max-width:440px; overflow:hidden; animation:fadeInUp 0.2s ease;">
                <style>
                    @keyframes fadeInUp {
                        from {
                            opacity: 0;
                            transform: translateY(20px);
                        }

                        to {
                            opacity: 1;
                            transform: translateY(0);
                        }
                    }
                </style>

                <div
                    style="background:#FCF8EE; border-bottom:1.5px solid #E6DAC0; padding:0.85rem 1.1rem; display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <div
                            style="font-size:0.625rem; font-weight:900; color:#662721; text-transform:uppercase; letter-spacing:0.06em; background:#FCF8EE; border:1px solid #E6DAC0; border-radius:4px; padding:0.08rem 0.4rem; display:inline-block;">
                            {{ ($completedOrderData['billed_to_tab'] ?? false) ? 'TERCATAT PADA TAGIHAN BERKALA' : 'TRANSAKSI LUNAS' }}</div>
                        <div style="font-size:1rem; font-weight:900; color:#4F2F2A; margin-top:0.2rem;">Tiket Walk-In
                            Siap Digunakan</div>
                    </div>
                    <button type="button" wire:click="closeSuccessModal"
                        style="background:none; border:none; font-size:1.25rem; color:#78350F; cursor:pointer; line-height:1;">&times;</button>
                </div>

                @include('filament.partials.walkin-receipt', ['receipt' => $completedOrderData])


                <div
                    style="background:#FCF8EE; border-top:1px solid #E6DAC0; padding:0.65rem 1.1rem; display:flex; justify-content:flex-end; gap:0.5rem;">
                    <button type="button" onclick="club61PrintReceipt('#printable-pos-receipt')"
                        style="padding:0.4rem 0.85rem; font-size:0.8125rem; font-weight:800; border:1.5px solid #E6DAC0; background:#FFFFFF; border-radius:7px; cursor:pointer; color:#4F2F2A;">
                        Cetak Struk
                    </button>
                    <button type="button" wire:click="closeSuccessModal"
                        style="padding:0.4rem 0.85rem; font-size:0.8125rem; font-weight:800; background:#662721; color:#F7F0DB; border:1px solid #662721; border-radius:7px; cursor:pointer;">
                        Transaksi Baru
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ============================
     MODAL BUKA SHIFT KASIR
     ============================ --}}
    @if ($showOpenShiftModal)
        <div
            style="position:fixed; inset:0; z-index:9999; background:rgba(15,23,42,0.65); backdrop-filter:blur(6px); display:flex; align-items:center; justify-content:center; padding:1rem;">
            <div
                style="background:#FFFFFF; border:1.5px solid #E6DAC0; border-radius:18px; box-shadow:0 25px 50px -12px rgba(102,39,33,0.4); width:100%; max-width:460px; overflow:hidden; animation:fadeInUp 0.2s ease;">
                <div
                    style="background:#FCF8EE; border-bottom:1.5px solid #E6DAC0; padding:0.85rem 1.1rem; display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <div
                            style="font-size:0.625rem; font-weight:900; color:#662721; text-transform:uppercase; letter-spacing:0.06em; background:#FCF8EE; border:1px solid #E6DAC0; border-radius:4px; padding:0.08rem 0.4rem; display:inline-block;">
                            REGISTER OPENING</div>
                        <div style="font-size:1rem; font-weight:900; color:#4F2F2A; margin-top:0.2rem;">Buka Shift
                            Kasir Baru</div>
                    </div>
                    <button type="button" wire:click="$set('showOpenShiftModal', false)"
                        style="background:none; border:none; font-size:1.25rem; color:#78350F; cursor:pointer; line-height:1;">&times;</button>
                </div>

                <div style="padding:1.1rem; display:flex; flex-direction:column; gap:0.85rem;">
                    <div
                        style="background:#FCF8EE; border:1px solid #E6DAC0; border-radius:8px; padding:0.65rem 0.8rem; font-size:0.75rem;">
                        <div style="display:flex; justify-content:space-between; margin-bottom:0.25rem;">
                            <span style="color:#6B7280; font-weight:600;">Loket Kasir:</span>
                            <strong style="color:#4F2F2A;">PADEL FRONTDESK</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; margin-bottom:0.25rem;">
                            <span style="color:#6B7280; font-weight:600;">Petugas Bertugas:</span>
                            <strong style="color:#4F2F2A;">{{ auth()->user()?->name ?? 'Kasir' }}</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between;">
                            <span style="color:#6B7280; font-weight:600;">Waktu Pembukaan:</span>
                            <strong
                                style="color:#4F2F2A;">{{ now()->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') }}
                                WIB</strong>
                        </div>
                    </div>

                    <div
                        style="background:#ECFDF5; border:1.5px solid #6EE7B7; border-radius:8px; padding:0.65rem 0.8rem; font-size:0.75rem; color:#065F46; font-weight:700; display:flex; align-items:center; gap:0.5rem;">
                        <span
                            style="width:8px; height:8px; border-radius:50%; background:#10B981; flex-shrink:0;"></span>
                        Loket beroperasi 100% Cashless. Sesi shift tidak memerlukan uang modal awal kas.
                    </div>

                    @include('pos.partials.shift-open-edc')
                    <div>
                        <label
                            style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">
                            Catatan Pembukaan (Opsional)
                        </label>
                        <textarea wire:model="openingNotes" rows="2"
                            style="width:100%; border:1.5px solid #E6DAC0; border-radius:8px; padding:0.5rem 0.75rem; font-size:0.75rem; color:#4F2F2A; background:#FCF8EE; outline:none;"
                            placeholder="Catatan serah terima kas atau kondisi register..."></textarea>
                    </div>
                </div>

                <div
                    style="background:#FCF8EE; border-top:1px solid #E6DAC0; padding:0.75rem 1.1rem; display:flex; justify-content:flex-end; gap:0.5rem;">
                    <button type="button" wire:click="$set('showOpenShiftModal', false)"
                        style="padding:0.45rem 0.9rem; font-size:0.8125rem; font-weight:700; border:1.5px solid #E6DAC0; background:#FFFFFF; border-radius:8px; cursor:pointer; color:#4F2F2A;">
                        Batal
                    </button>
                    <button type="button" wire:click="executeOpenShift"
                        style="padding:0.45rem 1.1rem; font-size:0.8125rem; font-weight:900; background:#662721; color:#F7F0DB; border:1px solid #662721; border-radius:8px; cursor:pointer; box-shadow:0 2px 8px rgba(102,39,33,0.25);">
                        Konfirmasi &amp; Buka Shift
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Tutup shift blind + laporan Z (sama dengan POS F&B) — App\Livewire\Concerns\ClosesPosShift. --}}
    @include('pos.partials.shift-close-modal')
    @include('pos.partials.coaching-settlement-modal')
    @include('pos.partials.shift-report-modal')
    @include('pos.partials.sales-recap-modal')

    {{-- Struk & Z-Report dicetak di printer thermal 58mm (App\Support\ReceiptPaper). --}}
    @include('pos.partials.receipt-print-style', ['selectors' => ['#printable-pos-receipt', '#printable-z-report', '#printable-sales-recap', '#printable-coaching-settlement']])
    <script>
        window.addEventListener('beforeunload', function(e) {
            if (@this.get('posStep') === 'payment') {
                e.preventDefault();
                e.returnValue = 'Transaksi pembayaran kasir sedang berlangsung. Yakin ingin meninggalkan halaman?';
                return e.returnValue;
            }
        });
    </script>

</div>{{-- end walkin-pos-root --}}
