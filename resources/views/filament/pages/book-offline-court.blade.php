{{-- Walk-In Offline Booking – POS Frontdesk (tablet first). Brand Club 61: Terakota #662721 (dominan), Cream #F7F0DB. --}}
<div class="walkin-pos-root">
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
            <span class="pos-date-label">{{ \Carbon\Carbon::parse($bookingDate)->translatedFormat('l, d F Y') }}</span>
        </div>

        <div class="pos-kpis">
            @if (count($selectedSlots) > 0)
                <button type="button" wire:click="clearSelectedSlots" class="pos-btn pos-btn-danger">
                    Hapus {{ count($selectedSlots) }} Slot
                </button>
            @endif
            <div class="pos-kpi"><span class="pos-kpi-value">{{ $bookedSlotsAll }}/{{ $totalSlotsAll }}</span><span class="pos-kpi-label">Slot Terisi</span></div>
            <div class="pos-kpi"><span class="pos-kpi-value">{{ $walkInStatsToday['count'] }}</span><span class="pos-kpi-label">Transaksi</span></div>
            <div class="pos-kpi"><span class="pos-kpi-value">Rp {{ number_format($walkInStatsToday['revenue'], 0, ',', '.') }}</span><span class="pos-kpi-label">Omzet Walk-In</span></div>
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
     MAIN 2-COLUMN POS LAYOUT
     ============================ --}}
    <div class="pos-main">

        @if ($posStep === 'selection')
            {{-- ==================== KIRI: JADWAL (lapangan = kolom, jam = baris) ==================== --}}
            @php
                $courtCount = count($gridData);
                // Baris jam yang di SEMUA lapangan sudah lewat/tutup tidak bisa dipilih → disembunyikan (hanya tampilan).
                $visibleHours = collect($operationalHours)->map(fn ($oh, $i) => $oh + ['index' => $i])
                    ->reject(fn ($oh) => $courtCount > 0 && collect($gridData)->every(
                        fn ($cr) => in_array($cr['slots'][$oh['index']]['status'] ?? 'CLOSED', ['PAST', 'CLOSED'], true)));
                $firstVisibleHour = $visibleHours->first();
                $hiddenPastBefore = $isToday && $firstVisibleHour && $firstVisibleHour['index'] > 0 ? $firstVisibleHour['label'] : null;
                // Jam dikelompokkan siang (sebelum 17:00) & malam (17:00 ke atas), sama dengan halaman booking customer.
                $hourGroups = $visibleHours
                    ->groupBy(fn ($oh) => $oh['hour'] < 17 ? 'day' : 'night')
                    ->map(fn ($rows) => [
                        'label' => $rows->first()['label'] . ' – ' . substr($rows->last()['end_time'], 0, 5) . ' WIB',
                        'rows' => $rows->values(),
                    ]);
            @endphp
            <div class="pos-grid-card">
                <div class="pos-grid-header">
                    <div>
                        <div class="pos-grid-title">Slot Lapangan &mdash;
                            {{ !empty($operationalHours) ? $operationalHours[0]['label'] . ' sampai ' . substr(end($operationalHours)['end_time'], 0, 5) . ' WIB' : 'Jam Operasional' }}
                        </div>
                        <div class="pos-grid-sub">Ketuk kotak jam untuk memilih, ketuk lagi untuk batal &bull; Prime 17:00–23:00, weekend tarif prime</div>
                    </div>
                    <div class="pos-legend">
                        <span class="legend-dot"><i style="background:#FFFFFF; border:1px solid #E6DAC0;"></i>Tersedia</span>
                        <span class="legend-dot"><i style="background:#662721;"></i>Dipilih</span>
                        <span class="legend-dot"><i style="background:#EEE5D3;"></i>Terisi</span>
                        <span class="legend-dot"><i style="background:#FFFBEB; border:1px dashed #D97706;"></i>Hold</span>
                        <span class="legend-dot"><i style="background:#FEF3C7; border:1.5px solid #D97706;"></i>Bayar Selisih</span>
                        <span class="legend-dot"><i style="width:6px; height:6px; border-radius:50%; background:#662721;"></i>Prime</span>
                    </div>
                </div>

                <div class="pos-grid-scroll">
                    <div class="pos-slotgrid" style="grid-template-columns: 72px repeat({{ max($courtCount, 1) }}, minmax(136px, 1fr));">
                        <div class="hd corner"></div>
                        @foreach ($gridData as $courtRow)
                            @php $court = $courtRow['court']; @endphp
                            <div class="hd" wire:key="court-head-{{ $court->id }}">
                                <div class="pos-court-name">{{ $court->name }}</div>
                                <div class="pos-court-meta">{{ $court->type }} &bull; Rp{{ number_format($court->hourly_rate_regular / 1000, 0) }}k/jam</div>
                            </div>
                        @endforeach

                        @if ($hiddenPastBefore)
                            <div class="pos-past-note">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                Jam sebelum {{ $hiddenPastBefore }} hari ini sudah lewat
                            </div>
                        @endif
                        @if ($hourGroups->isEmpty())
                            <div class="pos-past-note">Tidak ada jam yang masih bisa dipesan di tanggal ini &mdash; pilih tanggal lain.</div>
                        @endif
                        @foreach ($hourGroups as $groupKey => $group)
                            <div class="grp" wire:key="hour-group-{{ $groupKey }}">{{ $group['label'] }}</div>
                            @foreach ($group['rows'] as $oh)
                                <div class="tm" wire:key="oh-row-{{ $oh['hour'] }}">{{ $oh['label'] }}</div>
                                @foreach ($gridData as $courtRow)
                                    @php
                                        $court = $courtRow['court'];
                                        $slot = $courtRow['slots'][$oh['index']] ?? null;
                                    @endphp
                                    <div class="cell" wire:key="slot-cell-{{ $slot['slot_key'] ?? $court->id . '-' . $oh['hour'] }}">
                                        @if ($slot)
                                            @php
                                                $st = $slot['status'];
                                                $rate = number_format($slot['rate'] / 1000, 0) . 'k';
                                            @endphp
                                            @if ($st === 'SELECTED')
                                                <button type="button"
                                                    wire:click="toggleSlot('{{ $court->id }}', '{{ addslashes($court->name) }}', '{{ $slot['start_time'] }}', '{{ $slot['end_time'] }}', {{ $slot['rate'] }})"
                                                    class="slot-btn slot-selected"
                                                    title="Batal pilih: {{ $slot['full_label'] }} ({{ $court->name }})">
                                                    <span class="lbl">&#10003; Dipilih</span>
                                                    <span class="prc">{{ $rate }}</span>
                                                    @if ($slot['is_prime'])<span class="prime"></span>@endif
                                                </button>
                                            @elseif($st === 'AVAILABLE')
                                                <button type="button"
                                                    wire:click="toggleSlot('{{ $court->id }}', '{{ addslashes($court->name) }}', '{{ $slot['start_time'] }}', '{{ $slot['end_time'] }}', {{ $slot['rate'] }})"
                                                    class="slot-btn slot-available"
                                                    title="Pilih: {{ $slot['full_label'] }} – Rp{{ number_format($slot['rate'], 0, ',', '.') }}">
                                                    <span class="prc">{{ $rate }}</span>
                                                    @if ($slot['is_prime'])<span class="prime"></span>@endif
                                                </button>
                                            @elseif($st === 'BOOKED')
                                                <div class="slot-btn slot-booked"
                                                    title="Terisi: {{ $slot['booking']['player'] ?? 'Pemain' }}">
                                                    <span class="lbl">Terisi &bull; {{ $slot['booking']['player'] ?? 'Main' }}</span>
                                                </div>
                                            @elseif($st === 'UNPAID_DELTA')
                                                <button type="button"
                                                    wire:click="startSettlement('{{ $slot['booking']['id'] }}')"
                                                    class="slot-btn slot-delta {{ $slot['booking']['is_active_bill'] ? 'is-active' : '' }}"
                                                    title="Selisih reschedule belum dibayar: {{ $slot['booking']['player'] }} (#{{ $slot['booking']['code'] }}) — klik untuk melunasi">
                                                    <span class="lbl">Bayar &bull; {{ $slot['booking']['player'] }}</span>
                                                </button>
                                            @elseif($st === 'LOCKED')
                                                <div class="slot-btn slot-locked" title="Hold di keranjang">
                                                    <span class="lbl">Hold &bull; Cart</span>
                                                </div>
                                            @elseif($st === 'CLOSED')
                                                <div class="slot-btn slot-past" title="Di luar jam operasional lapangan">
                                                    <span class="lbl">Tutup</span>
                                                </div>
                                            @else
                                                <div class="slot-btn slot-past" title="Jam sudah lewat">
                                                    <span class="lbl">&mdash;</span>
                                                </div>
                                            @endif
                                        @endif
                                    </div>
                                @endforeach
                            @endforeach
                        @endforeach
                    </div>

                    {{-- Transaksi walk-in terakhir --}}
                    <div class="pos-grid-footer">
                        <div class="pos-recent-header">Transaksi Walk-In Terakhir</div>
                        <div class="pos-recent-list">
                            @forelse($recentWalkInOrders as $ro)
                                <div wire:key="recent-order-{{ $ro->id }}" class="pos-recent-row">
                                    <div style="min-width:0;">
                                        <div class="pos-recent-name">{{ $ro->user?->name ?? 'Walk-In' }}</div>
                                        <div class="pos-recent-sub">
                                            {{ $ro->padelBookings->pluck('court.name')->filter()->unique()->implode(', ') ?: 'Lapangan' }}
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
            </div>
        @elseif($posStep === 'payment')
            {{-- ==================== KIRI: TERMINAL PEMBAYARAN KASIR IN-PAGE ==================== --}}
            <div class="pos-terminal-card">
                <div class="pos-terminal-header">
                    <div>
                        <span class="pos-terminal-eyebrow">TERMINAL KASIR LOKET</span>
                        <div class="pos-terminal-title">Layar Pembayaran &amp; Penyelesaian Transaksi</div>
                    </div>
                    <button type="button" wire:click="backToSelection" class="pos-btn pos-btn-ghost">
                        &larr; Ubah Pilihan Slot
                    </button>
                </div>

                <div class="pos-terminal-body">
                    {{-- Form metode pembayaran bersama (dipakai juga oleh Kasir F&B) --}}
                    @if ($appliedVoucherCode && ! $settleBill && $this->grandTotal <= 0)
                        <div style="background:#ECFDF5; border:1px solid #A7F3D0; border-radius:12px; padding:0.9rem 1rem; color:#065F46; font-size:0.875rem; font-weight:700;">
                            Tagihan lunas penuh dengan voucher {{ $appliedVoucherCode }} &mdash; tidak perlu EDC / QRIS. Langsung selesaikan transaksi.
                        </div>
                    @else
                        @include("pos.partials.payment-method-form", ["grandTotal" => $this->grandTotal, "qrisMidtrans" => true])
                    @endif

                    {{-- Action Buttons --}}
                    <div class="pos-terminal-actions">
                        <button type="button" wire:click="backToSelection" class="pos-btn pos-btn-ghost" style="height:50px; padding:0 1.2rem;">
                            &larr; Kembali ke Pilih Jadwal
                        </button>
                        <button type="button" wire:click="submitWalkInBooking" wire:loading.attr="disabled" class="pos-submit-btn" style="flex:1;">
                            <span wire:loading.remove wire:target="submitWalkInBooking">Bayar Lunas &amp; Cetak
                                Struk</span>
                            <span wire:loading wire:target="submitWalkInBooking">Memproses Transaksi...</span>
                        </button>
                    </div>
                </div>
            </div>
        @elseif($posStep === 'receipt')
            {{-- ==================== KIRI: STRUK POS RESMI IN-PAGE ==================== --}}
            <div class="pos-receipt-inpage">
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
                        <div class="pos-panel-eyebrow">LANGKAH 2 DARI 2</div>
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
                    <div class="pos-panel-count">{{ count($selectedSlots) }} slot dipilih</div>
                </div>
            </div>

            {{-- Scrollable Body --}}
            <div class="pos-panel-body">

                @if ($settleBill)
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
                @else
                {{-- 1. DATA CUSTOMER --}}
                <div class="pos-customer-box">
                    <div
                        style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.4rem; margin-bottom:0.6rem;">
                        <span class="pos-section-label" style="margin-bottom:0;">Data Customer</span>
                        @if ($posStep === 'selection')
                            <div class="pos-tab-group">
                                <button type="button" wire:click="setCustomerMode('quick_create')"
                                    class="pos-tab {{ $customerMode === 'quick_create' ? 'active' : '' }}">Walk-In
                                    Baru</button>
                                <button type="button" wire:click="setCustomerMode('search')"
                                    class="pos-tab {{ $customerMode === 'search' ? 'active' : '' }}">Cari
                                    Member</button>
                            </div>
                        @endif
                    </div>

                    @if ($customerMode === 'quick_create')
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
                                    @if ($activeMembershipInfo)
                                        <div
                                            style="margin-top:0.25rem; display:flex; align-items:center; gap:0.35rem; flex-wrap:wrap;">
                                            <div
                                                style="display:inline-flex; align-items:center; gap:0.25rem; background:#FEF3C7; border:1px solid #F59E0B; border-radius:4px; padding:0.15rem 0.35rem; font-size:0.65rem; color:#92400E; font-weight:700;">
                                                <span>{{ $activeMembershipInfo['plan_name'] }}</span>
                                                <span>•</span>
                                                <span>
                                                    @if ($activeMembershipInfo['quota_type'] === 'HOURS')
                                                        Sisa:
                                                        {{ number_format($activeMembershipInfo['remaining_quota'], 1) }}
                                                        Jam
                                                    @elseif($activeMembershipInfo['discount_percent'] > 0)
                                                        Diskon {{ $activeMembershipInfo['discount_percent'] }}%
                                                    @else
                                                        Member
                                                    @endif
                                                </span>
                                            </div>
                                            @if ($posStep === 'selection')
                                                <button type="button" wire:click="toggleMembershipBenefit"
                                                    style="display:inline-flex; align-items:center; gap:0.3rem; background:{{ $useMembershipBenefit ? '#ECFDF5' : '#F3F4F6' }}; border:1px solid {{ $useMembershipBenefit ? '#6EE7B7' : '#D1D5DB' }}; border-radius:999px; padding:0.15rem 0.5rem 0.15rem 0.3rem; font-size:0.6rem; font-weight:800; color:{{ $useMembershipBenefit ? '#047857' : '#6B7280' }}; cursor:pointer;"
                                                    title="{{ $useMembershipBenefit ? 'Klik untuk tidak memakai benefit membership' : 'Klik untuk memakai benefit membership' }}">
                                                    <span
                                                        style="width:0.55rem; height:0.55rem; border-radius:999px; background:{{ $useMembershipBenefit ? '#10B981' : '#9CA3AF' }};"></span>
                                                    {{ $useMembershipBenefit ? 'Benefit Dipakai' : 'Benefit Dimatikan' }}
                                                </button>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                                @if ($posStep === 'selection')
                                    <button type="button" wire:click="clearSelectedCustomer"
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
                                                wire:click="selectCustomer('{{ $res->id }}')"
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

                {{-- 2. SLOT TERPILIH --}}
                <div>
                    <div
                        style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.4rem;">
                        <span class="pos-section-label">Slot Dipilih ({{ count($selectedSlots) }})</span>
                        <span style="font-size:0.6875rem; font-weight:800; color:#662721;">Rp
                            {{ number_format($this->courtTotal, 0, ',', '.') }}</span>
                    </div>
                    @if (empty($selectedSlots))
                        <div
                            style="background:#F9FAFB; border:1px dashed #D1D5DB; border-radius:8px; padding:0.85rem; text-align:center; color:#9CA3AF; font-size:0.6875rem;">
                            Klik kotak jam di tabel kiri untuk memilih slot.
                        </div>
                    @else
                        <div style="display:flex; flex-direction:column; gap:0.3rem;">
                            @foreach ($selectedSlots as $sKey => $s)
                                <div wire:key="selected-slot-{{ $sKey }}" class="pos-slot-chip">
                                    <div>
                                        <div style="font-weight:800; font-size:0.6875rem; color:#4F2F2A;">
                                            {{ $s['court_name'] }}</div>
                                        <div style="font-size:0.5625rem; color:#662721;">{{ $s['time_label'] }} WIB
                                        </div>
                                    </div>
                                    <div style="display:flex; align-items:center; gap:0.4rem;">
                                        <span
                                            style="font-weight:900; font-size:0.75rem; color:#662721;">Rp{{ number_format($s['price'], 0, ',', '.') }}</span>
                                        @if ($posStep === 'selection')
                                            <button type="button" wire:click="removeSlot('{{ $sKey }}')"
                                                style="background:#FEF2F2; border:1px solid #FECACA; color:#DC2626; border-radius:50%; width:18px; height:18px; font-weight:900; font-size:0.625rem; cursor:pointer; display:flex; align-items:center; justify-content:center; line-height:1;">&times;</button>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- 3. SEWA ALAT --}}
                @if (count($equipments) > 0)
                    <div>
                        <div
                            style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.4rem;">
                            <span class="pos-section-label">Sewa Alat (Opsional)</span>
                            <span style="font-size:0.6875rem; font-weight:800; color:#662721;">Rp
                                {{ number_format($this->equipmentTotal, 0, ',', '.') }}</span>
                        </div>
                        <div
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
                                    <div style="display:flex; align-items:center; gap:0.3rem;">
                                        @if ($posStep === 'selection')
                                            <button type="button"
                                                wire:click="decrementEquipment('{{ $eq->id }}')"
                                                class="pos-qty-btn">-</button>
                                        @endif
                                        <span
                                            style="min-width:20px; text-align:center; font-weight:900; font-size:0.8125rem; color:#4F2F2A;">{{ $qty }}</span>
                                        @if ($posStep === 'selection')
                                            <button type="button"
                                                wire:click="incrementEquipment('{{ $eq->id }}', {{ $eq->stock_quantity }})"
                                                class="pos-qty-btn">+</button>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- 3b. VOUCHER (promo / voucher saldo customer — Modul 21) --}}
                @if (! $settleBill)
                    <div style="margin-bottom:0.6rem;">
                        <div class="pos-section-label" style="margin-bottom:0.35rem;">Voucher</div>
                        @if ($appliedVoucherCode)
                            <div style="display:flex; justify-content:space-between; align-items:center; gap:0.5rem; background:#ECFDF5; border:1px solid #A7F3D0; border-radius:8px; padding:0.45rem 0.6rem;">
                                <div style="min-width:0;">
                                    <div style="font-family:var(--font-mono, monospace); font-weight:900; font-size:0.75rem; color:#065F46;">{{ $appliedVoucherCode }}</div>
                                    @if ($this->voucherResult['error'])
                                        <div style="font-size:0.6875rem; color:#B91C1C; font-weight:700;">{{ $this->voucherResult['error'] }}</div>
                                    @else
                                        <div style="font-size:0.6875rem; color:#047857;">Potongan Rp {{ number_format($this->voucherDiscount, 0, ',', '.') }}</div>
                                    @endif
                                </div>
                                <button type="button" wire:click="removeVoucher" style="font-size:0.6875rem; font-weight:800; color:#B91C1C; background:none; border:none; cursor:pointer;">Hapus</button>
                            </div>
                        @else
                            <div style="display:flex; gap:0.4rem;">
                                <input type="text" wire:model="voucherInput" wire:keydown.enter.prevent="applyVoucher" maxlength="30" placeholder="Kode voucher"
                                    style="flex:1; min-width:0; border:1.5px solid #E6DAC0; border-radius:8px; padding:0.4rem 0.55rem; font-size:0.75rem; font-family:var(--font-mono, monospace); text-transform:uppercase;">
                                <button type="button" wire:click="applyVoucher" wire:loading.attr="disabled"
                                    style="padding:0.4rem 0.75rem; border-radius:8px; border:1.5px solid #E6DAC0; background:#FCF8EE; color:#662721; font-weight:800; font-size:0.75rem; cursor:pointer;">Pakai</button>
                            </div>
                            @foreach ($this->customerCreditVouchers as $cv)
                                <div style="display:flex; justify-content:space-between; align-items:center; gap:0.5rem; margin-top:0.35rem; background:#F0FDF4; border:1px dashed #86EFAC; border-radius:8px; padding:0.35rem 0.55rem;">
                                    <div style="font-size:0.6875rem; color:#065F46;">
                                        <strong style="font-family:var(--font-mono, monospace);">{{ $cv['code'] }}</strong>
                                        &middot; saldo Rp {{ number_format($cv['available'], 0, ',', '.') }}
                                    </div>
                                    <button type="button" wire:click="applyVoucher('{{ $cv['code'] }}')"
                                        style="font-size:0.6875rem; font-weight:800; color:#047857; background:none; border:none; cursor:pointer;">Pakai</button>
                                </div>
                            @endforeach
                        @endif
                    </div>
                @endif

                {{-- 4. TOTAL --}}                <div class="pos-total-box">
                    <div
                        style="display:flex; justify-content:space-between; font-size:0.6875rem; color:#7A5A52; margin-bottom:0.2rem;">
                        <span>Lapangan:</span><span>Rp {{ number_format($this->courtTotal, 0, ',', '.') }}</span>
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

                @if (! $settleBill)
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
                        &larr; Ubah Pilihan Slot
                    </button>
                @elseif($posStep === 'receipt')
                    <button type="button" wire:click="startNewTransaction" class="pos-submit-btn">
                        <span>Mulai Transaksi Baru</span>
                    </button>
                @endif
            </div>
        </div>{{-- end pos-panel-card --}}

    </div>{{-- end pos-main --}}
    @endif

    {{-- QR Midtrans menunggu dibayar customer --}}
    @include('pos.partials.midtrans-qris-modal', ['pendingQris' => $pendingQris, 'pollAction' => 'pollPendingQris', 'cancelAction' => 'cancelPendingQris', 'simulateAction' => 'simulatePendingQrisPaid'])

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
                            TRANSAKSI LUNAS</div>
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

    {{-- ============================
     MODAL TUTUP SHIFT KASIR (100% CASHLESS)
     ============================ --}}
    @if ($showCloseShiftModal && $activeShift)
        <div
            style="position:fixed; inset:0; z-index:9999; background:rgba(15,23,42,0.65); backdrop-filter:blur(6px); display:flex; align-items:center; justify-content:center; padding:1rem;">
            <div
                style="background:#FFFFFF; border:1.5px solid #E6DAC0; border-radius:18px; box-shadow:0 25px 50px -12px rgba(102,39,33,0.4); width:100%; max-width:480px; overflow:hidden; animation:fadeInUp 0.2s ease;">
                <div
                    style="background:#FCF8EE; border-bottom:1.5px solid #E6DAC0; padding:0.85rem 1.1rem; display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <div
                            style="font-size:0.625rem; font-weight:900; color:#BE123C; text-transform:uppercase; letter-spacing:0.06em; background:#FFF1F2; border:1px solid #FECDD3; border-radius:4px; padding:0.08rem 0.4rem; display:inline-block;">
                            CLOSING REGISTER</div>
                        <div style="font-size:1rem; font-weight:900; color:#4F2F2A; margin-top:0.2rem;">Tutup Shift
                            &amp; Rekonsiliasi Kas</div>
                    </div>
                    <button type="button" wire:click="$set('showCloseShiftModal', false)"
                        style="background:none; border:none; font-size:1.25rem; color:#78350F; cursor:pointer; line-height:1;">&times;</button>
                </div>

                <div style="padding:1.1rem; display:flex; flex-direction:column; gap:0.85rem;">
                    <div
                        style="background:#FCF8EE; border:1px solid #E6DAC0; border-radius:8px; padding:0.65rem 0.8rem; font-size:0.75rem;">
                        <div style="display:flex; justify-content:space-between; margin-bottom:0.25rem;">
                            <span style="color:#6B7280; font-weight:600;">No. Shift:</span>
                            <strong style="color:#4F2F2A;">{{ $activeShift->shift_number }}</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; margin-bottom:0.25rem;">
                            <span style="color:#6B7280; font-weight:600;">Dibuka Oleh:</span>
                            <strong style="color:#4F2F2A;">{{ $activeShift->openedBy?->name ?? 'Kasir' }}
                                ({{ $activeShift->opened_at->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') }}
                                WIB)</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; margin-bottom:0.25rem;">
                            <span style="color:#6B7280; font-weight:600;">Petugas Closing:</span>
                            <strong style="color:#4F2F2A;">{{ auth()->user()?->name ?? 'Kasir' }}</strong>
                        </div>
                    </div>

                    <div
                        style="background:#ECFDF5; border:1.5px solid #6EE7B7; border-radius:8px; padding:0.65rem 0.8rem; font-size:0.75rem; color:#065F46; font-weight:700; display:flex; align-items:center; gap:0.5rem;">
                        <span
                            style="width:8px; height:8px; border-radius:50%; background:#10B981; flex-shrink:0;"></span>
                        Rekonsiliasi 100% Cashless &mdash; berbasis audit digital QRIS &amp; EDC, tanpa hitung fisik
                        kas.
                    </div>

                    <div>
                        <label
                            style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">
                            Catatan Penutupan Kasir (Opsional)
                        </label>
                        <textarea wire:model="closingNotes" rows="2"
                            style="width:100%; border:1.5px solid #E6DAC0; border-radius:8px; padding:0.5rem 0.75rem; font-size:0.75rem; color:#4F2F2A; background:#FCF8EE; outline:none;"
                            placeholder="Catatan serah terima kas, selisih uang, atau catatan operasional..."></textarea>
                    </div>
                </div>

                <div
                    style="background:#FCF8EE; border-top:1px solid #E6DAC0; padding:0.75rem 1.1rem; display:flex; justify-content:flex-end; gap:0.5rem;">
                    <button type="button" wire:click="$set('showCloseShiftModal', false)"
                        style="padding:0.45rem 0.9rem; font-size:0.8125rem; font-weight:700; border:1.5px solid #E6DAC0; background:#FFFFFF; border-radius:8px; cursor:pointer; color:#4F2F2A;">
                        Batal
                    </button>
                    <button type="button" wire:click="executeCloseShift"
                        style="padding:0.45rem 1.1rem; font-size:0.8125rem; font-weight:900; background:#BE123C; color:#FFFFFF; border:none; border-radius:8px; cursor:pointer; box-shadow:0 2px 8px rgba(190,18,60,0.25);">
                        Tutup Shift &amp; Rekonsiliasi Kas
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ============================
     MODAL LAPORAN SHIFT / STRUK Z-REPORT
     ============================ --}}
    @if ($showShiftReportModal && $reportShiftData)
        <div
            style="position:fixed; inset:0; z-index:9999; background:rgba(15,23,42,0.65); backdrop-filter:blur(6px); display:flex; align-items:center; justify-content:center; padding:1rem;">
            <div
                style="background:#FFFFFF; border:1.5px solid #E6DAC0; border-radius:18px; box-shadow:0 25px 50px -12px rgba(102,39,33,0.4); width:100%; max-width:460px; max-height:90vh; display:flex; flex-direction:column; overflow:hidden; animation:fadeInUp 0.2s ease;">
                <div
                    style="background:#FCF8EE; border-bottom:1.5px solid #E6DAC0; padding:0.85rem 1.1rem; display:flex; justify-content:space-between; align-items:center; flex-shrink:0;">
                    <div>
                        <div
                            style="font-size:0.625rem; font-weight:900; color:#662721; text-transform:uppercase; letter-spacing:0.06em; background:#FCF8EE; border:1px solid #E6DAC0; border-radius:4px; padding:0.08rem 0.4rem; display:inline-block;">
                            REKONSILIASI KAS</div>
                        <div style="font-size:1rem; font-weight:900; color:#4F2F2A; margin-top:0.2rem;">Laporan
                            Penutupan Kasir (Z-Report)</div>
                    </div>
                    <button type="button" wire:click="closeShiftReportModal"
                        style="background:none; border:none; font-size:1.25rem; color:#78350F; cursor:pointer; line-height:1;">&times;</button>
                </div>

                <div style="flex:1; overflow-y:auto; padding:1rem 1.1rem; background:#FFFFFF;">
                    <div id="printable-z-report"
                        style="font-family:monospace; font-size:0.75rem; color:#111827; background:#FFFFFF; padding:0.5rem 0;">
                        <div
                            style="text-align:center; border-bottom:1px dashed #000; padding-bottom:0.6rem; margin-bottom:0.6rem;">
                            <div style="font-weight:900; font-size:0.9375rem;">CLUB 61 PADEL ARENA</div>
                            <div style="font-size:0.6rem;">{{ \App\Models\Setting\CompanyProfileSetting::receiptAddress() }}</div>
                            <div style="font-size:0.65rem; font-weight:800; margin-top:0.25rem;">LAPORAN PENUTUPAN
                                KASIR (Z-REPORT)</div>
                            <div style="font-size:0.6rem;">Loket: {{ $reportShiftData['counter'] }}</div>
                        </div>

                        <div style="border-bottom:1px dashed #000; padding-bottom:0.45rem; margin-bottom:0.45rem;">
                            <div>No. Shift: <strong>{{ $reportShiftData['shift_number'] }}</strong></div>
                            <div>Buka : {{ $reportShiftData['opened_at'] }} ({{ $reportShiftData['opened_by'] }})
                            </div>
                            <div>Tutup: {{ $reportShiftData['closed_at'] }} ({{ $reportShiftData['closed_by'] }})
                            </div>
                            <div>Total Transaksi: {{ $reportShiftData['total_transactions'] }} transaksi</div>
                        </div>

                        <div style="border-bottom:1px dashed #000; padding-bottom:0.45rem; margin-bottom:0.45rem;">
                            <div style="font-weight:900; margin-bottom:0.25rem;">RINGKASAN PENJUALAN POS (100%
                                CASHLESS):</div>
                            @if (($reportShiftData['total_debit_sales'] ?? 0) > 0)
                                <div style="display:flex; justify-content:space-between; margin-bottom:0.15rem;">
                                    <span>Kartu Debit (EDC):</span>
                                    <span>Rp
                                        {{ number_format($reportShiftData['total_debit_sales'], 0, ',', '.') }}</span>
                                </div>
                            @endif
                            @if (($reportShiftData['total_credit_sales'] ?? 0) > 0)
                                <div style="display:flex; justify-content:space-between; margin-bottom:0.15rem;">
                                    <span>Kartu Kredit (EDC):</span>
                                    <span>Rp
                                        {{ number_format($reportShiftData['total_credit_sales'], 0, ',', '.') }}</span>
                                </div>
                            @endif
                            @if (($reportShiftData['total_edc_bca_sales'] ?? 0) > 0)
                                <div style="display:flex; justify-content:space-between; margin-bottom:0.15rem;">
                                    <span>EDC BCA:</span>
                                    <span>Rp
                                        {{ number_format($reportShiftData['total_edc_bca_sales'], 0, ',', '.') }}</span>
                                </div>
                            @endif
                            @if (($reportShiftData['total_edc_mandiri_sales'] ?? 0) > 0)
                                <div style="display:flex; justify-content:space-between; margin-bottom:0.15rem;">
                                    <span>EDC Mandiri:</span>
                                    <span>Rp
                                        {{ number_format($reportShiftData['total_edc_mandiri_sales'], 0, ',', '.') }}</span>
                                </div>
                            @endif
                            <div style="display:flex; justify-content:space-between; margin-bottom:0.15rem;">
                                <span>QRIS:</span>
                                <span>Rp {{ number_format($reportShiftData['total_qris_sales'], 0, ',', '.') }}</span>
                            </div>
                            @if ($reportShiftData['total_other_sales'] > 0)
                                <div style="display:flex; justify-content:space-between; margin-bottom:0.15rem;">
                                    <span>Lainnya:</span>
                                    <span>Rp
                                        {{ number_format($reportShiftData['total_other_sales'], 0, ',', '.') }}</span>
                                </div>
                            @endif
                            <div
                                style="display:flex; justify-content:space-between; font-weight:900; border-top:1px dashed #000; padding-top:0.25rem; margin-top:0.25rem;">
                                <span>TOTAL OMSET POS:</span>
                                <span>Rp {{ number_format($reportShiftData['total_sales'], 0, ',', '.') }}</span>
                            </div>
                        </div>

                        @if (!empty($reportShiftData['closing_notes']))
                            <div
                                style="border-bottom:1px dashed #000; padding-bottom:0.45rem; margin-bottom:0.45rem; font-size:0.6875rem;">
                                <strong>Catatan:</strong> {{ $reportShiftData['closing_notes'] }}
                            </div>
                        @endif

                        <div
                            style="display:flex; justify-content:space-between; text-align:center; padding-top:1.2rem; font-size:0.625rem;">
                            <div style="width:45%;">
                                <div>Kasir Bertugas,</div>
                                <div style="height:2.2rem;"></div>
                                <div style="border-top:1px solid #000; padding-top:0.15rem;">(
                                    {{ $reportShiftData['closed_by'] }} )</div>
                            </div>
                            <div style="width:45%;">
                                <div>Supervisor / Manager,</div>
                                <div style="height:2.2rem;"></div>
                                <div style="border-top:1px solid #000; padding-top:0.15rem;">( .................... )
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    style="background:#FCF8EE; border-top:1px solid #E6DAC0; padding:0.65rem 1.1rem; display:flex; justify-content:flex-end; gap:0.5rem; flex-shrink:0;">
                    <button type="button" onclick="club61PrintReceipt('#printable-z-report')"
                        style="padding:0.4rem 0.85rem; font-size:0.8125rem; font-weight:800; border:1.5px solid #E6DAC0; background:#FFFFFF; border-radius:7px; cursor:pointer; color:#4F2F2A;">
                        Cetak Z-Report
                    </button>
                    <button type="button" wire:click="closeShiftReportModal"
                        style="padding:0.4rem 0.85rem; font-size:0.8125rem; font-weight:800; background:#662721; color:#F7F0DB; border:1px solid #662721; border-radius:7px; cursor:pointer;">
                        Selesai
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Struk & Z-Report dicetak di printer thermal 58mm (App\Support\ReceiptPaper). --}}
    @include('pos.partials.receipt-print-style', ['selectors' => ['#printable-pos-receipt', '#printable-z-report']])
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
