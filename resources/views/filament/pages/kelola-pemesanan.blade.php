<div class="c61">
    @include('filament.partials.c61-admin-style')
    <style>
        .kp-dimmed { opacity: 0.5; pointer-events: none; transition: opacity 0.12s ease; }
        /* Tabel lebar tetap; di layar sempit (tablet) digeser ke samping, kolom tidak saling menimpa. */
        .kp-table { table-layout: fixed; min-width: 980px; }
        .kp-table th:first-child, .kp-table td:first-child { padding-left: 1.25rem; }
        .kp-table th:last-child, .kp-table td:last-child { padding-right: 1.25rem; text-align: center; white-space: nowrap; }
        .kp-table th, .kp-table td { padding-left: 0.7rem; padding-right: 0.7rem; }
        .kp-note { border-radius: 8px; padding: 0.35rem 0.55rem; font-size: 0.71875rem; font-weight: 600; line-height: 1.35; word-break: break-word; }
        .kp-spin { width: 14px; height: 14px; }
    </style>

    <!-- Header -->
    <div class="c61-hero">
        <div>
            <div class="c61-eyebrow"><span class="dot"></span>Modul Kelola Pemesanan &bull; Order Management</div>
            <div class="c61-hero-title">Kelola Pemesanan &amp; Booking</div>
            <div class="c61-hero-sub">
                Daftar transaksi reservasi customer, verifikasi QR Code check-in, penyesuaian jadwal (reschedule), dan
                proses refund kasir.
            </div>
        </div>

        <div class="c61-hero-actions">
            @if ($this->canCheckIn)
            <button type="button" wire:click="openCheckInModal()" class="c61-btn c61-btn-cream c61-btn-lg">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/></svg>
                <span>Scan QR / Check-In Gate</span>
            </button>
            @endif
            <a href="/admin/booking-system" class="c61-btn c61-btn-outline-cream c61-btn-lg">
                <span>Lihat Matriks Lapangan</span>
            </a>
        </div>
    </div>

    <!-- Tab status -->
    <div>
        <div class="c61-seg">
            <button type="button" wire:click="setTab('ALL')" wire:loading.attr="disabled"
                class="c61-seg-btn {{ $activeTab === 'ALL' ? 'is-active' : '' }}">
                Semua Reservasi <span class="count">{{ $counts['ALL'] }}</span>
            </button>
            <button type="button" wire:click="setTab('CONFIRMED')" wire:loading.attr="disabled"
                class="c61-seg-btn {{ $activeTab === 'CONFIRMED' ? 'is-active' : '' }}">
                Konfirmasi / Lunas <span class="count">{{ $counts['CONFIRMED'] }}</span>
            </button>
            <button type="button" wire:click="setTab('COMPLETED')" wire:loading.attr="disabled"
                class="c61-seg-btn {{ $activeTab === 'COMPLETED' ? 'is-active' : '' }}">
                Selesai <span class="count">{{ $counts['COMPLETED'] }}</span>
            </button>
            <button type="button" wire:click="setTab('CANCELLED')" wire:loading.attr="disabled"
                class="c61-seg-btn {{ $activeTab === 'CANCELLED' ? 'is-active' : '' }}">
                Dibatalkan / Refund <span class="count">{{ $counts['CANCELLED'] }}</span>
            </button>
        </div>
    </div>

    <!-- Tabel reservasi -->
    <div class="c61-card">
        <div class="c61-card-head">
            <div class="c61-row" style="gap: 0.75rem;">
                <div class="c61-card-title">Daftar Transaksi Reservasi</div>
                <span class="c61-pill c61-pill-cream">Total: {{ $bookings->total() }} Data</span>
            </div>

            <div class="c61-row" style="gap: 0.75rem;">
                <div class="c61-search" style="width: 320px;">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                    <input type="text" wire:model.live.debounce.300ms="search"
                        placeholder="Cari nama cust, no tiket, email, lapangan..." class="c61-input" />
                    @if ($search)
                        <button type="button" wire:click="$set('search', '')" title="Hapus filter pencarian" class="c61-search-clear">&times;</button>
                    @endif
                </div>

                <div class="c61-row" style="gap: 0.4rem; font-size: 0.75rem; color: var(--c-muted); font-weight: 700;">
                    <span>Tampil:</span>
                    <select wire:model.live="perPage" class="c61-select" style="width: auto; height: 36px;">
                        <option value="5">5</option>
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                    </select>
                    <span>baris</span>
                </div>
            </div>
        </div>

        <div class="c61-table-wrap" wire:loading.class="kp-dimmed"
            wire:target="setTab, gotoPage, nextPage, previousPage, search, perPage">
            <table class="c61-table kp-table">
                <thead>
                    <tr>
                        <th style="width: {{ $activeTab === 'CANCELLED' ? '12%' : '13%' }};">No Tiket</th>
                        <th style="width: {{ $activeTab === 'CANCELLED' ? '14%' : '17%' }};">Member / Customer</th>
                        <th style="width: {{ $activeTab === 'CANCELLED' ? '14%' : '16%' }};">Lapangan &amp; Durasi</th>
                        <th style="width: {{ $activeTab === 'CANCELLED' ? '13%' : '14%' }};">Jadwal Main</th>
                        <th style="width: {{ $activeTab === 'CANCELLED' ? '11%' : '14%' }};">Status</th>
                        @if ($activeTab === 'CANCELLED')
                            <th style="width: 14%;">Note / Keterangan</th>
                        @endif
                        <th style="width: {{ $activeTab === 'CANCELLED' ? '10%' : '11%' }};">Total Bayar</th>
                        <th style="width: {{ $activeTab === 'CANCELLED' ? '12%' : '15%' }}; text-align: center;">Aksi
                            Kasir</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($bookings as $b)
                        @php
                            $duration = (int) $b->start_time->diffInHours($b->end_time);
                            if ($duration < 1) {
                                $duration = 1;
                            }

                            $pendingPayment = $b->order?->payments?->firstWhere('status', 'PENDING');
                            $pendingAmount = $pendingPayment
                                ? (float) $pendingPayment->amount
                                : ($b->status === 'PENDING_PAYMENT'
                                    ? (float) ($b->order?->grand_total ?: $b->total_amount)
                                    : 0);
                        @endphp
                        <tr>
                            <td class="code">
                                <div class="c61-truncate" title="{{ $b->booking_code }}">{{ $b->booking_code }}
                                </div>
                                @if ($b->reschedule_count > 0)
                                    <div class="sub" style="color: #B45309; font-family: var(--font-sans); font-weight: 600;">Reschedule
                                        ({{ $b->reschedule_count }}x)</div>
                                @endif
                            </td>
                            <td>
                                <div class="c61-truncate strong"
                                    title="{{ $b->user?->name ?? 'Guest User' }}">
                                    {{ $b->user?->name ?? 'Guest User' }}</div>
                                <div class="c61-truncate sub"
                                    title="{{ $b->user?->email ?? '-' }}">{{ $b->user?->email ?? '-' }}</div>
                            </td>
                            <td>
                                <div class="c61-truncate strong"
                                    title="{{ $b->court?->name ?? '-' }}">{{ $b->court?->name ?? '-' }}</div>
                                <div class="sub">Sesi:
                                    {{ $duration }} Jam</div>
                            </td>
                            <td>
                                <div class="strong" style="font-weight: 600;">{{ $b->booking_date->format('d M Y') }}
                                </div>
                                <div class="sub">{{ $b->start_time->format('H:i') }} -
                                    {{ $b->end_time->format('H:i') }} WIB</div>
                            </td>
                            <td>
                                @if ($b->status === 'PAID')
                                    <span class="c61-pill c61-pill-ok">Confirmed / Lunas</span>
                                @elseif($b->status === 'PENDING_PAYMENT')
                                    <span class="c61-pill c61-pill-warn">Pending
                                        Payment</span>
                                @elseif($b->status === 'LOCKED' && $b->reschedule_count > 0 && $pendingAmount > 0)
                                    <span class="c61-pill c61-pill-warn" style="white-space: normal; height: auto; padding: 0.2rem 0.6rem;"
                                        title="Jadwal sudah dipindah, QR ditahan sampai selisih lunas">Tagihan Selisih Rp {{ number_format($pendingAmount, 0, ',', '.') }}</span>
                                @elseif($b->status === 'LOCKED')
                                    <span class="c61-pill c61-pill-cream">Locked / Waiting</span>
                                @elseif($b->status === 'CHECKED_IN')
                                    <span class="c61-pill c61-pill-terra">Checked In</span>
                                @elseif($b->status === 'COMPLETED')
                                    <span class="c61-pill c61-pill-ok">Completed</span>
                                @elseif($b->status === 'REFUNDED')
                                    <span class="c61-pill c61-pill-gray">Refunded</span>
                                @elseif($b->status === 'REFUND_PENDING')
                                    <span class="c61-pill c61-pill-orange" title="Booking sudah batal, refund menunggu persetujuan di Antrian Refund">Menunggu Refund</span>
                                @elseif($b->status === 'CANCELLED')
                                    <span class="c61-pill c61-pill-danger">Cancelled</span>
                                @elseif($b->status === 'EXPIRED')
                                    <span class="c61-pill c61-pill-danger">Expired</span>
                                @else
                                    <span class="c61-pill c61-pill-gray">{{ $b->status }}</span>
                                @endif
                            </td>
                            @if ($activeTab === 'CANCELLED')
                                <td style="font-size: 0.75rem;">
                                    @if ($b->cancel_reason)
                                        <div class="kp-note" style="background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA;"
                                            title="{{ $b->cancel_reason }}">
                                            {{ $b->cancel_reason }}
                                        </div>
                                    @elseif($b->status === 'EXPIRED')
                                        @php
                                            $isPaidNoShow =
                                                ($b->order && $b->order->payment_status === 'PAID') ||
                                                ($b->order &&
                                                    $b->order->payments &&
                                                    $b->order->payments->where('status', 'SUCCESS')->isNotEmpty());
                                        @endphp
                                        @if ($isPaidNoShow)
                                            <div class="kp-note" style="background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA;">
                                                Lewat Jadwal Main (No-Show / Lunas)
                                            </div>
                                        @else
                                            <div class="kp-note" style="background: #FFFBEB; color: #92400E; border: 1px solid #FDE68A;">
                                                Kedaluwarsa Pembayaran (Lewat Batas Bayar {{ app(\App\Services\Padel\BookingTimeService::class)->paymentWindowMinutes() }} Menit)
                                            </div>
                                        @endif
                                    @else
                                        <span style="color: var(--c-faint); font-size: 0.75rem; font-style: italic;">Tidak ada
                                            catatan</span>
                                    @endif
                                </td>
                            @endif
                            <td class="num" style="text-align: left;">
                                Rp {{ number_format($b->total_amount, 0, ',', '.') }}
                            </td>
                            <td>
                                <div class="c61-actions">
                                    @if ($this->canSettle && $b->order_id && ($b->status === 'PENDING_PAYMENT' || ($b->status === 'LOCKED' && $pendingAmount > 0)))
                                        <button type="button" wire:click="checkMidtransPayment('{{ $b->id }}')"
                                            wire:loading.attr="disabled" title="Cek Status Pembayaran ke Midtrans"
                                            class="c61-icon-btn is-info">
                                            <span wire:loading.remove wire:target="checkMidtransPayment('{{ $b->id }}')">
                                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                                </svg>
                                            </span>
                                            <span wire:loading wire:target="checkMidtransPayment('{{ $b->id }}')">
                                                <svg class="c61-spin kp-spin" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" style="opacity: 0.25;"></circle><path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" style="opacity: 0.75;"></path></svg>
                                            </span>
                                        </button>
                                    @endif

                                    @if ($this->canSettle && $this->canOpenPos && (($b->status === 'LOCKED' && $pendingAmount > 0) || $b->status === 'PENDING_PAYMENT'))
                                        {{-- Pembayaran tidak dieksekusi di sini: buka POS Walk-In langsung di tagihan ini. --}}
                                        <a href="{{ \App\Filament\Pages\BookOfflineCourt::getUrl(['tagihan' => $b->id]) }}"
                                            title="Bayar di POS Walk-In (Rp {{ number_format($pendingAmount ?: $b->total_amount, 0, ',', '.') }})"
                                            class="c61-icon-btn is-solid">
                                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                                            </svg>
                                        </a>
                                    @endif

                                    @if ($this->canCheckIn && $b->status === 'PAID')
                                        <button type="button"
                                            wire:click="openCheckInModal('{{ $b->booking_code }}')"
                                            wire:loading.attr="disabled" title="Check-In Customer (Scan QR)"
                                            class="c61-icon-btn is-ok">
                                            <span wire:loading.remove
                                                wire:target="openCheckInModal('{{ $b->booking_code }}')">
                                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z" />
                                                </svg>
                                            </span>
                                            <span wire:loading
                                                wire:target="openCheckInModal('{{ $b->booking_code }}')">
                                                <svg class="c61-spin kp-spin" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" style="opacity: 0.25;"></circle><path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" style="opacity: 0.75;"></path></svg>
                                            </span>
                                        </button>
                                    @endif

                                    @if ($this->canCheckIn && $b->status === 'CHECKED_IN')
                                        <button type="button" x-on:click="$dispatch('club61-confirm', { title: 'Tandai Sesi Selesai?', message: @js('Sesi bermain tiket '.$b->booking_code.' akan ditandai selesai (COMPLETED).'), confirmLabel: 'Ya, Selesai', onConfirm: () => $wire.executeComplete(@js($b->id)) })"
                                            wire:loading.attr="disabled" title="Tandai Selesai (Complete)"
                                            class="c61-icon-btn is-warn">
                                            <span wire:loading.remove
                                                wire:target="executeComplete('{{ $b->id }}')">
                                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                            </span>
                                            <span wire:loading wire:target="executeComplete('{{ $b->id }}')">
                                                <svg class="c61-spin kp-spin" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" style="opacity: 0.25;"></circle><path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" style="opacity: 0.75;"></path></svg>
                                            </span>
                                        </button>
                                    @endif

                                    @php
                                        $hasPendingEquipmentReturn = $b->equipments->contains(function ($e) {
                                            return $e->equipment
                                                && in_array(strtoupper($e->equipment->type), ['RACKET', 'TOWEL'])
                                                && $e->stock_deducted_at
                                                && ! $e->returned_at;
                                        });
                                    @endphp
                                    @if ($this->canCheckIn && in_array($b->status, ['CHECKED_IN', 'COMPLETED']) && $hasPendingEquipmentReturn)
                                        <button type="button" x-on:click="$dispatch('club61-confirm', { title: 'Alat Sewa Sudah Kembali?', message: @js('Raket/handuk tiket '.$b->booking_code.' akan ditandai sudah dikembalikan ke frontdesk dan stoknya bertambah lagi.'), confirmLabel: 'Ya, Sudah Kembali', onConfirm: () => $wire.executeReturnEquipment(@js($b->id)) })"
                                            wire:loading.attr="disabled" title="Retur Alat Sewa (Restock)"
                                            class="c61-icon-btn is-info">
                                            <span wire:loading.remove
                                                wire:target="executeReturnEquipment('{{ $b->id }}')">
                                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M9 14l-4-4m0 0l4-4m-4 4h11a4 4 0 010 8h-1" />
                                                </svg>
                                            </span>
                                            <span wire:loading
                                                wire:target="executeReturnEquipment('{{ $b->id }}')">
                                                <svg class="c61-spin kp-spin" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" style="opacity: 0.25;"></circle><path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" style="opacity: 0.75;"></path></svg>
                                            </span>
                                        </button>
                                    @endif

                                    @if ($this->canReschedule && in_array($b->status, ['PAID', 'LOCKED']))
                                        <button type="button"
                                            wire:click="openRescheduleModal('{{ $b->id }}')"
                                            wire:loading.attr="disabled" title="Pindah Jadwal (Reschedule)"
                                            class="c61-icon-btn">
                                            <span wire:loading.remove
                                                wire:target="openRescheduleModal('{{ $b->id }}')">
                                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                </svg>
                                            </span>
                                            <span wire:loading
                                                wire:target="openRescheduleModal('{{ $b->id }}')">
                                                <svg class="c61-spin kp-spin" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" style="opacity: 0.25;"></circle><path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" style="opacity: 0.75;"></path></svg>
                                            </span>
                                        </button>
                                    @endif

                                    @if ($this->canRequestRefundFor($b))
                                        <button type="button"
                                            wire:click="openCancelRefundModal('{{ $b->id }}')"
                                            wire:loading.attr="disabled" title="Ajukan Pembatalan &amp; Refund"
                                            class="c61-icon-btn is-danger">
                                            <span wire:loading.remove
                                                wire:target="openCancelRefundModal('{{ $b->id }}')">
                                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </span>
                                            <span wire:loading
                                                wire:target="openCancelRefundModal('{{ $b->id }}')">
                                                <svg class="c61-spin kp-spin" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" style="opacity: 0.25;"></circle><path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" style="opacity: 0.75;"></path></svg>
                                            </span>
                                        </button>
                                    @endif

                                    @if (in_array($b->status, ['REFUNDED', 'REFUND_PENDING', 'CANCELLED', 'EXPIRED', 'COMPLETED']))
                                        <span
                                            title="{{ $b->status === 'COMPLETED' ? 'Sesi Telah Selesai' : 'Tiket Telah Dinonaktifkan' }}"
                                            class="c61-icon-btn is-muted">
                                            @if ($b->status === 'COMPLETED')
                                                <svg style="color: #047857;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                                                </svg>
                                            @else
                                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                                </svg>
                                            @endif
                                        </span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $activeTab === 'CANCELLED' ? 8 : 7 }}" class="c61-empty">
                                @if ($search)
                                    <div style="font-weight: 700; color: var(--c-brown); margin-bottom: 0.25rem;">Tidak ada reservasi yang cocok</div>
                                    <div style="font-size: 0.75rem; margin-bottom: 0.75rem;">
                                        Tidak ditemukan hasil untuk kata kunci "<strong>{{ $search }}</strong>".
                                    </div>
                                    <button type="button" wire:click="$set('search', '')" class="c61-btn c61-btn-ghost c61-btn-sm">
                                        Hapus Filter Pencarian
                                    </button>
                                @else
                                    <div style="font-weight: 600;">Belum ada reservasi pada kategori ini.</div>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="c61-card-foot">
            <div style="font-size: 0.75rem; color: var(--c-muted); font-weight: 600;">
                Menampilkan <strong style="color: var(--c-brown);">{{ $bookings->firstItem() ?? 0 }}</strong> - <strong
                    style="color: var(--c-brown);">{{ $bookings->lastItem() ?? 0 }}</strong> dari <strong
                    style="color: var(--c-brown);">{{ $bookings->total() }}</strong> reservasi
                @if ($search)
                    <span style="color: var(--c-terra);">(difilter)</span>
                @endif
            </div>

            @if ($bookings->hasPages())
                <div class="c61-pages">
                    <button type="button" wire:click="previousPage" wire:loading.attr="disabled"
                        @if ($bookings->onFirstPage()) disabled @endif class="c61-page">
                        &larr; Prev
                    </button>

                    @foreach ($bookings->getUrlRange(1, $bookings->lastPage()) as $page => $url)
                        <button type="button" wire:click="gotoPage({{ $page }})"
                            wire:loading.attr="disabled"
                            class="c61-page {{ $page == $bookings->currentPage() ? 'is-active' : '' }}">
                            {{ $page }}
                        </button>
                    @endforeach

                    <button type="button" wire:click="nextPage" wire:loading.attr="disabled"
                        @if (!$bookings->hasMorePages()) disabled @endif class="c61-page">
                        Next &rarr;
                    </button>
                </div>
            @endif
        </div>
    </div>

    <!-- MODAL 1: PINDAH JADWAL (ADMIN OVERRIDE) -->
    @if ($showRescheduleModal && $selectedBookingData)
        <div class="c61-modal-backdrop" style="z-index: 99999;">
            <div class="c61-modal" style="max-width: 600px;">
                <div class="c61-modal-head">
                    <div>
                        <div class="c61-modal-eyebrow">Admin Override &bull; Concierge</div>
                        <div class="c61-modal-title">Pindah Jadwal Reservasi</div>
                    </div>
                    <button type="button" wire:click="$set('showRescheduleModal', false)" class="c61-modal-close">&times;</button>
                </div>

                <div class="c61-modal-body" style="gap: 0;">
                    <div class="c61-note" style="margin-bottom: 1.25rem;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                            <span style="color: var(--c-muted); font-weight: 600;">Customer &amp;
                                Tiket</span>
                            <span class="c61-mono" style="font-weight: 800; color: var(--c-terra);">#{{ $selectedBookingData['booking_code'] }}</span>
                        </div>
                        <div style="font-size: 0.9375rem; font-weight: 800;">
                            {{ $selectedBookingData['customer_name'] }}</div>
                        <div style="color: var(--c-muted); margin-top: 0.25rem;">
                            Jadwal Asli: {{ $selectedBookingData['court_name'] }} &bull;
                            {{ $selectedBookingData['original_date'] }}, {{ $selectedBookingData['original_time'] }}
                        </div>
                        <div class="c61-row" style="gap: 0.4rem; margin-top: 0.6rem;">
                            <span class="c61-pill c61-pill-ok">
                                Durasi Terkunci: {{ $rescheduleDurationHours }} Jam
                            </span>
                            <span class="c61-pill c61-pill-gray">
                                Sewa Raket: Terikut Otomatis (Order ID)
                            </span>
                        </div>
                    </div>

                    <div class="c61-field">
                        <label class="c61-label">Pilih Lapangan Tujuan</label>
                        <select wire:model.live="rescheduleCourtId" wire:loading.attr="disabled" class="c61-select">
                            @foreach ($courts as $court)
                                <option value="{{ $court->id }}">{{ $court->name }} ({{ $court->type }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="c61-field">
                        <label class="c61-label">
                            Tanggal Baru <small>(Hanya Hari Ini atau Masa Depan)</small>
                        </label>
                        <input type="date" min="{{ now()->format('Y-m-d') }}"
                            wire:model.live.debounce.250ms="rescheduleDate" wire:loading.attr="disabled" class="c61-input">
                    </div>

                    <div class="c61-field">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.35rem;">
                            <label class="c61-label" style="margin-bottom: 0;">
                                Pilih Jam Main Baru (Blok Kontigu {{ $rescheduleDurationHours }} Jam)
                            </label>
                            <span wire:loading wire:target="rescheduleDate, rescheduleCourtId"
                                style="font-size: 0.6875rem; color: var(--c-terra); font-weight: 700;">
                                Memeriksa ketersediaan...
                            </span>
                        </div>
                        @if (empty($availableSlots))
                            <div class="c61-note c61-note-danger">
                                Tidak ada jadwal kosong yang memiliki {{ $rescheduleDurationHours }} jam berturut-turut
                                pada lapangan dan tanggal ini. Silakan pilih tanggal atau lapangan lain.
                            </div>
                        @else
                            <select wire:model.live="rescheduleStartTime" wire:loading.attr="disabled"
                                wire:target="rescheduleDate, rescheduleCourtId" class="c61-select">
                                @foreach ($availableSlots as $slot)
                                    <option value="{{ $slot['start_time'] }}">{{ $slot['label'] }}{{ ! empty($slot['benefit_dropped_reason']) ? ' ⚠ benefit gugur, harga normal' : '' }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>

                    <!-- Perhitungan Selisih Tarif (rumus sama persis dengan yang ditagih: PadelBookingService::quoteReschedule) -->
                    @if (!empty($availableSlots) && $rescheduleStartTime && !empty($rescheduleQuote))
                        @php
                            $q = $rescheduleQuote;
                            $rp = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
                            $totalDelta = (float) ($q['total_delta'] ?? 0);
                            $forfeited = (float) ($q['forfeited'] ?? 0);
                            $tone = $totalDelta > 0 ? ['#F87171', '#FEF2F2', '#991B1B'] : ($forfeited > 0 ? ['#FCD34D', '#FFFBEB', '#92400E'] : ['#E5E7EB', '#F9FAFB', '#374151']);
                            $row = 'display:flex; justify-content:space-between; font-size:0.75rem; color:#4B5563; margin-bottom:0.25rem;';
                        @endphp
                        @if (! empty($q['benefit_dropped_reason']))
                            {{-- Benefit member / voucher sponsor tidak berlaku di jadwal ini → harga normal. Admin wajib menjelaskan ke customer
                                 sebelum menyimpan; pilih tanggal yang masih berlaku kalau customer tidak mau bayar harga normal. --}}
                            <div class="c61-note c61-note-warn" style="margin-bottom: 0.75rem;">
                                <strong>⚠ Benefit tidak berlaku di jadwal ini.</strong> {{ $q['benefit_dropped_reason'] }}
                                Kalau customer tetap mau pindah ke jadwal ini, ia membayar selisih harga normal (jam kuota/voucher yang terpakai dikembalikan).
                                Pilih tanggal yang masih dalam masa berlaku kalau ingin benefitnya tetap dipakai.
                            </div>
                        @endif
                        <div style="border-radius: 12px; padding: 1rem; margin-bottom: 1rem; border: 1px solid {{ $tone[0] }}; background: {{ $tone[1] }};">
                            <div style="{{ $row }}"><span>Sudah dibayar (tarif sesi lama):</span><span style="font-weight:700;">{{ $rp($selectedBookingData['original_court_fee']) }}</span></div>
                            <div style="{{ $row }}"><span>Tarif normal jadwal baru:</span><span style="font-weight:700;">{{ $rp($q['gross_fee'] ?? 0) }}</span></div>
                            @if (($q['benefit_discount'] ?? 0) > 0)
                                <div style="{{ $row }} color:#166534;"><span>Benefit member / voucher sponsor ikut pindah:</span><span style="font-weight:700;">- {{ $rp($q['benefit_discount']) }}</span></div>
                            @endif
                            <div style="{{ $row }}"><span>Tarif jadwal baru yang berlaku:</span><span style="font-weight:700;">{{ $rp($q['estimated_fee'] ?? 0) }}</span></div>

                            @if ($totalDelta > 0)
                                <div style="border-top:1px dashed {{ $tone[0] }}; margin-top:0.35rem; padding-top:0.35rem;">
                                    <div style="{{ $row }}"><span>Selisih sewa lapangan:</span><span style="font-weight:700;">{{ $rp($q['delta']) }}</span></div>
                                    @if (($q['tax_delta'] ?? 0) > 0)
                                        <div style="{{ $row }}"><span>Pajak:</span><span style="font-weight:700;">{{ $rp($q['tax_delta']) }}</span></div>
                                    @endif
                                    @if (($q['admin_fee_delta'] ?? 0) > 0)
                                        <div style="{{ $row }}"><span>Biaya layanan:</span><span style="font-weight:700;">{{ $rp($q['admin_fee_delta']) }}</span></div>
                                    @endif
                                </div>
                            @endif

                            <div style="border-top: 1px dashed {{ $tone[0] }}; padding-top: 0.5rem; margin-top:0.35rem; display: flex; justify-content: space-between; align-items: center; gap: 0.75rem;">
                                <span style="font-size: 0.8125rem; font-weight: 800; color: {{ $tone[2] }};">
                                    @if ($totalDelta > 0)
                                        Total Kurang Bayar (Wajib Ditagih):
                                    @elseif ($forfeited > 0)
                                        Selisih Lebih Bayar (HANGUS, tidak dikembalikan):
                                    @else
                                        Tidak Ada Selisih Biaya:
                                    @endif
                                </span>
                                <span class="c61-mono" style="font-size: 1.0625rem; font-weight: 900; color: {{ $tone[2] }}; white-space: nowrap;">
                                    {{ $rp($totalDelta > 0 ? $totalDelta : $forfeited) }}
                                </span>
                            </div>

                            @if ($totalDelta > 0)
                                <div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid #FECACA;">
                                    <label style="display: block; font-size: 0.6875rem; font-weight: 700; color: #991B1B; margin-bottom: 0.35rem;">Customer mau bayar selisihnya lewat:</label>
                                    <div style="display: flex; flex-direction: column; gap: 0.4rem; font-size: 0.75rem; margin-bottom: 0.5rem; color: var(--c-brown);">
                                        <label style="display: flex; align-items: flex-start; gap: 0.45rem; cursor: pointer; padding: 0.5rem 0.65rem; border-radius: 10px; background: #FFFFFF; border: 1px solid #FECACA;">
                                            <input type="radio" wire:model.live="rescheduleDeltaChannel" value="CASHIER" style="margin-top: 0.15rem; accent-color: #662721;">
                                            <span><b>Bayar di Kasir</b>: dilunasi di <b>POS Walk-In</b> (kasir klik slot &quot;Bayar&quot; di grid jadwal), misalnya saat customer datang.</span>
                                        </label>
                                        <label style="display: flex; align-items: flex-start; gap: 0.45rem; cursor: pointer; padding: 0.5rem 0.65rem; border-radius: 10px; background: #FFFFFF; border: 1px solid #FECACA;">
                                            <input type="radio" wire:model.live="rescheduleDeltaChannel" value="ONLINE" style="margin-top: 0.15rem; accent-color: #662721;">
                                            <span><b>Bayar Online (Midtrans)</b>: customer membayar dari halaman invoice-nya.</span>
                                        </label>
                                    </div>
                                    <div style="font-size: 0.65rem; color: #7F1D1D; line-height: 1.4;">
                                        Halaman ini tidak menerima pembayaran. Jadwal langsung dipindah &amp; slot ditahan atas nama customer; QR tiket dan check-in terkunci sampai selisih lunas.
                                    </div>
                                </div>
                            @elseif ($forfeited > 0)
                                <div style="margin-top: 0.5rem; font-size: 0.6875rem; color: #92400E;">
                                    Kebijakan venue: pindah ke jadwal yang lebih murah, selisih tidak dikembalikan. Nominal hangus tetap tercatat di invoice customer & log aktivitas.
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="c61-field" style="margin-bottom: 0;">
                        <label class="c61-label">Alasan Perubahan Jadwal</label>
                        <input type="text" wire:model="rescheduleReason"
                            placeholder="Misal: Customer salah booking via WhatsApp, hujan di lapangan outdoor..." class="c61-input">
                    </div>
                </div>

                <div class="c61-modal-foot">
                    <button type="button" wire:click="$set('showRescheduleModal', false)" class="c61-btn c61-btn-ghost">Batal</button>
                    <button type="button" wire:click="executeReschedule" wire:loading.attr="disabled"
                        @if (empty($availableSlots) || !$rescheduleStartTime) disabled @endif class="c61-btn c61-btn-primary">
                        <span wire:loading.remove wire:target="executeReschedule">Simpan &amp; Proses Jadwal</span>
                        <span wire:loading wire:target="executeReschedule">Memproses &amp; Mengunci...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- MODAL 3: CANCEL & REFUND -->
    @if ($showCancelRefundModal)
        <div class="c61-modal-backdrop" style="z-index: 99999;">
            <div class="c61-modal is-danger" style="max-width: 520px;">
                <div class="c61-modal-head">
                    <div>
                        <div class="c61-modal-eyebrow">Pengajuan &bull; Disetujui di Antrian Refund</div>
                        <div class="c61-modal-title">Ajukan Pembatalan &amp; Refund</div>
                    </div>
                    <button type="button" wire:click="$set('showCancelRefundModal', false)" class="c61-modal-close">&times;</button>
                </div>

                <div class="c61-modal-body" style="gap: 0;">
                    <div class="c61-note" style="margin-bottom: 1.25rem;">
                        <div style="color: var(--c-muted);">Customer &amp; Tiket:</div>
                        <div style="font-size: 0.9375rem; font-weight: 800;">{{ $cancelCustomerName }}
                            (#{{ $cancelBookingCode }})</div>
                        <div style="margin-top: 0.25rem; color: var(--c-muted);">
                            Uang yang sudah masuk untuk booking ini: <strong style="color: var(--c-brown);">Rp {{ number_format($originalTotalAmount, 0, ',', '.') }}</strong>
                        </div>
                    </div>

                    <div class="c61-field">
                        <label class="c61-label">Kategori Alasan Pembatalan:</label>
                        <select wire:model="refundCategory" class="c61-select">
                            <option value="PERMINTAAN_CUSTOMER">Permintaan Customer</option>
                            <option value="KESALAHAN_VENUE">Kesalahan Venue (Lapangan Rusak / Venue Tutup)</option>
                            <option value="FORCE_MAJEURE">Force Majeure (Hujan Badai / Listrik Padam)</option>
                            <option value="SALAH_BAYAR">Salah Bayar / Double Transfer</option>
                        </select>
                    </div>

                    <div class="c61-note c61-note-orange" style="margin-bottom: 1rem;">
                        <div style="font-weight: 800;">
                            @if ($originalTotalAmount > 0)
                                Refund yang diajukan: Rp {{ number_format($originalTotalAmount, 0, ',', '.') }} (penuh)
                            @else
                                Tidak ada uang yang perlu dikembalikan
                            @endif
                        </div>
                        <div style="font-size: 0.6875rem; line-height: 1.45; margin-top: 0.25rem;">
                            @if ($originalTotalAmount > 0)
                                Uang belum keluar dari sini. Pengajuan masuk ke <strong>Antrian Refund</strong> untuk disetujui superadmin / manager.
                                Kalau ditolak, uangnya otomatis jadi <strong>voucher saldo</strong> di akun customer.
                            @else
                                Booking ini ditanggung kuota member / voucher sponsor atau belum dibayar. Kuota / jam voucher dikembalikan ke customer.
                            @endif
                        </div>
                    </div>

                    <div class="c61-field" style="margin-bottom: 0.5rem;">
                        <label class="c61-label">Alasan (wajib, dibaca pemeriksa refund):</label>
                        <textarea wire:model="refundNotes" rows="2" maxlength="500" placeholder="Contoh: customer sakit, minta uang kembali via transfer BCA a.n. ..." class="c61-textarea"></textarea>
                    </div>

                    <div style="font-size: 0.6875rem; color: #B42318; line-height: 1.4; margin-top: 0.5rem;">
                        Perhatian: QR Code tiket akan langsung DIMATIKAN dan slot lapangan otomatis kembali TERSEDIA
                        untuk publik.
                    </div>
                </div>

                <div class="c61-modal-foot">
                    <button type="button" wire:click="$set('showCancelRefundModal', false)" class="c61-btn c61-btn-ghost">Tutup</button>
                    <button type="button" wire:click="executeCancelRefund" wire:loading.attr="disabled" class="c61-btn c61-btn-danger">
                        <span wire:loading.remove wire:target="executeCancelRefund">Batalkan &amp; Ajukan</span>
                        <span wire:loading wire:target="executeCancelRefund">Membatalkan...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- MODAL CHECK-IN GATE & HANDOVER ALAT -->
    @if ($showCheckInModal)
        <div class="c61-modal-backdrop">
            <div class="c61-modal">
                <div class="c61-modal-head">
                    <div>
                        <div class="c61-modal-eyebrow">Gate Access &bull; Club 61 Padel Court</div>
                        <div class="c61-modal-title">Check-In Gate &amp; Scanner</div>
                    </div>
                    <button type="button" wire:click="closeCheckInModal" class="c61-modal-close">&times;</button>
                </div>

                <div class="c61-modal-body">
                    <div>
                        <label class="c61-label">Scan Barcode Gun / Input Kode Tiket:</label>
                        <div style="display: flex; gap: 0.5rem;">
                            <input type="text" wire:model="checkInQuery" wire:keydown.enter="executeCheckIn"
                                placeholder="Scan QR atau ketik BK-PAD-XXXX..." autocomplete="off"
                                x-init="$nextTick(() => $el.focus())"
                                class="c61-input c61-mono" style="flex: 1; height: 46px; font-size: 0.875rem; font-weight: 700;">
                            <button type="button" wire:click="executeCheckIn" wire:loading.attr="disabled" class="c61-btn c61-btn-primary c61-btn-lg">
                                <span wire:loading.remove wire:target="executeCheckIn">Verifikasi</span>
                                <span wire:loading wire:target="executeCheckIn">Memproses...</span>
                            </button>
                        </div>
                        <div class="c61-hint">
                            Mendukung tembakan Barcode Scanner Gun USB, QR Code Hash, atau input manual kode booking.
                        </div>
                    </div>

                    @if ($checkInResult)
                        <div class="c61-note {{ $checkInResult['already_checked_in'] ? 'c61-note-warn' : 'c61-note-ok' }}" style="padding: 1.1rem 1.25rem;">
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin-bottom: 0.75rem;">
                                <span class="c61-pill {{ $checkInResult['already_checked_in'] ? 'c61-pill-warn' : 'c61-pill-ok' }}" style="font-weight: 800;">
                                    {{ $checkInResult['already_checked_in'] ? 'SUDAH PERNAH CHECK-IN' : 'CHECK-IN BERHASIL' }}
                                </span>
                                <span class="c61-mono" style="font-size: 0.6875rem; color: var(--c-muted);">
                                    Gate Staff: {{ $checkInResult['gate_marshall'] ?? 'Kasir' }}
                                </span>
                            </div>

                            <div style="font-family: var(--font-serif); font-size: 1.25rem; font-weight: 600; color: var(--c-brown);">
                                {{ $checkInResult['player_name'] }}
                            </div>
                            <div style="font-size: 0.8125rem; color: var(--c-brown); font-weight: 600; margin-top: 0.2rem;">
                                {{ $checkInResult['court_name'] }} &bull; {{ $checkInResult['schedule'] }}
                            </div>
                            <div class="c61-mono" style="font-size: 0.75rem; color: var(--c-muted); margin-top: 0.2rem;">
                                Tiket: <strong>{{ $checkInResult['booking_code'] }}</strong>
                            </div>

                            <div style="margin-top: 1rem; border-top: 1px dashed {{ $checkInResult['already_checked_in'] ? '#FCD34D' : '#86EFAC' }}; padding-top: 0.75rem;">
                                <div style="font-size: 0.6875rem; font-weight: 800; color: var(--c-brown); text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 0.45rem;">
                                    Serah-Terima Peralatan (Equipment Handover):
                                </div>
                                @if (!empty($checkInResult['equipments']))
                                    <div style="display: flex; flex-direction: column; gap: 0.35rem;">
                                        @foreach ($checkInResult['equipments'] as $eq)
                                            <div style="background: #FFFFFF; border: 1px solid var(--c-line); border-radius: 10px; padding: 0.5rem 0.75rem; display: flex; align-items: center; justify-content: space-between; font-size: 0.8125rem;">
                                                <span style="font-weight: 700; color: var(--c-brown);">{{ $eq['name'] }}</span>
                                                <span class="c61-pill c61-pill-terra">{{ $eq['quantity'] }} Pcs</span>
                                            </div>
                                        @endforeach
                                    </div>
                                    <div style="margin-top: 0.5rem; font-size: 0.6875rem; color: #047857; font-weight: 700;">
                                        Harap serahkan raket &amp; bola di atas kepada pemain sebelum memasuki lapangan.
                                    </div>
                                @else
                                    <div style="font-size: 0.75rem; color: var(--c-muted); font-style: italic;">
                                        Tidak ada tambahan sewa raket atau bola pada tiket ini.
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>

                <div class="c61-modal-foot">
                    <button type="button" wire:click="closeCheckInModal" class="c61-btn c61-btn-ghost">
                        Tutup
                    </button>
                    @if ($checkInResult)
                        <button type="button" wire:click="closeCheckInModal" class="c61-btn c61-btn-primary">
                            Selesai &amp; Buka Akses Gate
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
