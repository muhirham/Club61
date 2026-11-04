<x-filament-panels::page>
    <div class="c61" style="padding: 0; gap: 1rem;">
        @include('filament.partials.c61-admin-style')

        @if ($this->overdueCount > 0)
            <div class="c61-card" style="border-left: 4px solid #B91C1C; background: #FEF2F2;" data-loan-overdue>
                <div class="c61-card-body" style="padding: 0.85rem 1.25rem; font-size: 0.875rem; font-weight: 700; color: #991B1B;">
                    {{ $this->overdueCount }} transaksi punya alat sewa yang lewat batas kembali (lebih dari {{ \App\Models\Padel\EquipmentLoan::OVERDUE_GRACE_MINUTES }} menit). Hubungi peminjamnya, lalu buka Detail dan terima alatnya begitu dikembalikan.
                </div>
            </div>
        @endif

        <div class="c61-card" style="border-left: 4px solid var(--c-terra);">
            <div class="c61-card-body" style="display: flex; gap: 1rem; align-items: flex-start; padding: 1rem 1.25rem;">
                <span class="c61-kpi-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg></span>
                <div style="font-size: 0.8125rem; line-height: 1.6; color: var(--c-muted);">
                    <div class="c61-card-title" style="font-size: 1.0625rem; margin-bottom: 0.2rem;">Raket &amp; handuk yang sedang dipinjam</div>
                    Satu baris = satu transaksi. Tercatat otomatis saat customer check-in (alat sewa diserahkan). Setelah selesai bermain, buka
                    <b style="color: var(--c-brown);">Detail</b>, cek kondisi tiap alat bersama peminjam, lalu terima kembali. Rusak / hilang karena disengaja &rarr; denda menunggu keputusan management &rarr; dibayar di POS kasir.
                    <details style="margin-top: 0.4rem;">
                        <summary style="cursor: pointer; font-weight: 800; color: var(--c-brown);">Ketentuan peminjaman &amp; denda</summary>
                        @include('filament.pages.partials.equipment-loan-rules')
                    </details>
                </div>
            </div>
        </div>

        @if ($booking)
            <div style="font-size: 0.8125rem; color: var(--c-muted);">
                Menampilkan transaksi <b style="font-family: monospace;">{{ $booking }}</b> &middot;
                <a href="{{ \App\Filament\Pages\PeminjamanAlat::getUrl() }}" style="color: var(--c-terra); font-weight: 800;">Tampilkan semua</a>
            </div>
        @endif

        {{ $this->table }}
    </div>

    @if ($detailBookingId && ($detail = $this->detailBooking))
        @php
            $loans = $this->detailLoans;
            $tz = \App\Services\Finance\LedgerReport::TIMEZONE;
            $rupiah = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
            $openCount = $loans->filter(fn ($l) => $l->isOpen())->count();
            $first = $loans->first();
        @endphp
        <div class="c61-modal-backdrop" wire:key="loan-detail-{{ $detailBookingId }}">
            <div class="c61-modal" style="max-width: 760px;" data-loan-detail>
                <div class="c61-modal-head">
                    <div>
                        <div class="c61-modal-eyebrow">Detail peminjaman alat</div>
                        <div class="c61-modal-title">{{ $detail->booking_code }} &middot; {{ $first?->borrower_name }}</div>
                    </div>
                    <button type="button" wire:click="closeDetail" class="c61-modal-close" aria-label="Tutup">&times;</button>
                </div>
                <div class="c61-modal-body">
                    <div class="c61-kv">
                        <div><div class="k">Peminjam</div><div class="v">{{ $first?->borrower_name }}</div><div class="s">{{ $first?->borrower_phone ?: '-' }}</div></div>
                        <div><div class="k">Booking</div><div class="v">{{ $detail->court?->name ?? '-' }}</div><div class="s">{{ $detail->start_time->format('d M Y, H:i') }}–{{ $detail->end_time->format('H:i') }} WIB</div></div>
                        <div><div class="k">Diserahkan</div><div class="v">{{ $loans->min('handed_out_at')?->setTimezone($tz)->format('d M, H:i') }}</div><div class="s">{{ $loans->map(fn ($l) => $l->handedOutBy?->name ?? 'Check-in otomatis')->unique()->implode(', ') }}</div></div>
                        <div><div class="k">Batas kembali</div><div class="v">{{ $loans->max('due_at')?->setTimezone($tz)->format('d M, H:i') }} WIB</div><div class="s">{{ $loans->sum('quantity') }} alat &middot; {{ $openCount }} belum kembali</div></div>
                    </div>

                    @foreach ($loans as $loan)
                        <div wire:key="loan-item-{{ $loan->id }}" style="border: 1px solid var(--c-line); border-radius: 14px; padding: 0.85rem 1rem; display: flex; flex-direction: column; gap: 0.6rem;" data-loan-item="{{ $loan->id }}">
                            <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                                <div style="font-weight: 900; color: var(--c-brown); font-size: 0.9375rem;">{{ $loan->quantity }}&times; {{ $loan->equipment_name }}</div>
                                @if ($loan->isOverdue())
                                    <span class="c61-pill c61-pill-danger">Terlambat</span>
                                @elseif ($loan->isOpen())
                                    <span class="c61-pill c61-pill-orange">Dipinjam</span>
                                @else
                                    <span class="c61-pill c61-pill-ok">Kembali {{ $loan->returned_at->setTimezone($tz)->format('d M, H:i') }} &middot; {{ $loan->returnedBy?->name ?? '-' }}</span>
                                @endif
                            </div>

                            {{-- Terima kembali: jumlah per kondisi (total = jumlah dipinjam). --}}
                            @if ($loan->isOpen() && $this->canReturn && isset($returnForm[$loan->id]))
                                {{-- Label pendek 1 baris di atas, keterangan & denda di bawah input: semua input sejajar. --}}
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 0.5rem; align-items: start;">
                                    @foreach (\App\Models\Padel\EquipmentLoan::CONDITIONS as $key => $condition)
                                        @php $fine = $condition['fine'] ? (float) ($loan->equipment?->{$condition['fine']} ?? 0) : 0; @endphp
                                        <label style="display: flex; flex-direction: column; gap: 0.25rem; min-width: 0;" title="{{ $condition['label'] }}">
                                            <span style="font-size: 0.75rem; font-weight: 800; color: var(--c-brown); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $condition['short'] }}</span>
                                            <input type="number" min="0" max="{{ $loan->quantity }}" step="1" class="c61-input" wire:model="returnForm.{{ $loan->id }}.{{ $key }}" aria-label="{{ $condition['label'] }}">
                                            <span style="font-size: 0.625rem; line-height: 1.35; color: var(--c-muted);">
                                                {{ $condition['label'] }}@if ($condition['fine'])<br><b style="color: {{ $fine > 0 ? '#991B1B' : 'var(--c-muted)' }};">{{ $fine > 0 ? 'Denda '.$rupiah($fine).'/unit' : 'Belum ada nominal denda' }}</b>@endif
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                                <textarea class="c61-input" rows="2" maxlength="500" wire:model="returnForm.{{ $loan->id }}.notes" placeholder="Catatan kondisi (wajib bila ada yang rusak / hilang)" style="height: auto; padding: 0.5rem 0.7rem;"></textarea>
                                <div style="display: flex; justify-content: flex-end;">
                                    <button type="button" wire:click="saveReturn(@js($loan->id))" wire:loading.attr="disabled" class="c61-btn c61-btn-primary c61-btn-sm">Terima Kembali</button>
                                </div>
                            @elseif (! $loan->isOpen())
                                <div style="font-size: 0.8125rem; color: var(--c-brown);">
                                    {{ app(\App\Services\Padel\EquipmentLoanService::class)->conditionSummary($loan) }}{{ $loan->return_notes ? ' — '.$loan->return_notes : '' }}
                                </div>
                            @endif

                            {{-- Denda --}}
                            @if ($loan->fine_status === \App\Models\Padel\EquipmentLoan::FINE_PENDING)
                                <div class="c61-note c61-note-warn" style="font-size: 0.75rem;">Denda menunggu keputusan management &middot; usulan {{ $rupiah($loan->fine_proposed) }}</div>
                                @if ($this->canDecide && isset($decideForm[$loan->id]))
                                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 0.5rem; align-items: end;">
                                        <label style="display: flex; flex-direction: column; gap: 0.2rem; font-size: 0.6875rem; font-weight: 700; color: var(--c-muted);">
                                            Keputusan
                                            <select class="c61-select" wire:model.live="decideForm.{{ $loan->id }}.decision">
                                                <option value="APPROVE">Setujui denda</option>
                                                <option value="WAIVE">Hapus denda</option>
                                            </select>
                                        </label>
                                        @if (($decideForm[$loan->id]['decision'] ?? 'APPROVE') === 'APPROVE')
                                            <label style="display: flex; flex-direction: column; gap: 0.2rem; font-size: 0.6875rem; font-weight: 700; color: var(--c-muted);">
                                                Nominal (Rp)
                                                <input type="number" min="1" step="1000" class="c61-input" wire:model="decideForm.{{ $loan->id }}.amount">
                                            </label>
                                        @endif
                                    </div>
                                    <textarea class="c61-input" rows="2" maxlength="500" wire:model="decideForm.{{ $loan->id }}.note" placeholder="Alasan keputusan (wajib, tercatat di Log Aktivitas)" style="height: auto; padding: 0.5rem 0.7rem;"></textarea>
                                    <div style="display: flex; justify-content: flex-end;">
                                        <button type="button" wire:click="saveDecision(@js($loan->id))" wire:loading.attr="disabled" class="c61-btn c61-btn-primary c61-btn-sm">Simpan Keputusan</button>
                                    </div>
                                @endif
                            @elseif ($loan->fine_status === \App\Models\Padel\EquipmentLoan::FINE_APPROVED)
                                <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                                    <div class="c61-note {{ $loan->isFinePaid() ? 'c61-note-ok' : 'c61-note-danger' }}" style="font-size: 0.75rem; flex: 1;">
                                        Denda {{ $rupiah($loan->fine_amount) }} {{ $loan->isFinePaid() ? '· LUNAS' : '· belum dibayar' }} &middot; diputuskan {{ $loan->fineDecidedBy?->name ?? '-' }}: {{ $loan->fine_decision_note }}
                                    </div>
                                    @if ($loan->fineDue() > 0 && $this->canPay)
                                        <a href="{{ \App\Filament\Pages\KasirProShop::getUrl(['denda' => $loan->id]) }}" class="c61-btn c61-btn-primary c61-btn-sm">Bayar di Kasir</a>
                                    @endif
                                </div>
                            @elseif ($loan->fine_status === \App\Models\Padel\EquipmentLoan::FINE_WAIVED)
                                <div class="c61-note" style="font-size: 0.75rem;">Denda dihapus oleh {{ $loan->fineDecidedBy?->name ?? '-' }}: {{ $loan->fine_decision_note }}</div>
                            @endif
                        </div>
                    @endforeach

                    <details>
                        <summary style="cursor: pointer; font-weight: 800; color: var(--c-brown);">Slip peminjaman &amp; ketentuan</summary>
                        <div style="margin-top: 0.5rem;">
                            @include('filament.pages.partials.equipment-loan-slip', ['loans' => $loans, 'booking' => $detail])
                        </div>
                    </details>
                </div>
                <div class="c61-modal-foot">
                    @if ($openCount > 0 && $this->canReturn)
                        <button type="button"
                            x-on:click="$dispatch('club61-confirm', { title: 'Terima semua dalam kondisi baik?', message: @js('Semua alat yang belum kembali di transaksi '.$detail->booking_code.' dicatat kembali dalam kondisi baik dan stoknya bertambah lagi. Kalau ada yang rusak / hilang, isi per alat saja.'), confirmLabel: 'Ya, Semua Baik', onConfirm: () => $wire.returnAllGood() })"
                            class="c61-btn c61-btn-ghost">Terima Semua (Kondisi Baik)</button>
                    @endif
                    <button type="button" wire:click="closeDetail" class="c61-btn c61-btn-primary">Tutup</button>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
