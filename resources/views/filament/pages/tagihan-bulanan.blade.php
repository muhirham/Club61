<x-filament-panels::page>
    <div class="c61" style="padding: 0; gap: 1rem;">
        @include('filament.partials.c61-admin-style')
        @php
            $rupiah = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
            $hours = fn ($v) => \App\Services\Membership\MonthlyBilling::hoursLabel((float) $v);
        @endphp

        <div class="c61-card">
            <div class="c61-card-body" style="font-size: 0.8125rem; line-height: 1.55; color: var(--c-muted); padding: 0.9rem 1.25rem;">
                Rekap jam main paket <b style="color: var(--c-brown);">Tagihan Bulanan</b> perusahaan untuk Finance. Harga per jam dan tagihan
                ditentukan serta diterbitkan oleh Finance. Pembayaran yang ditransfer perusahaan dicatat melalui
                <b style="color: var(--c-brown);">Kelola Sponsor → Catat Transfer</b>.
                @if ($sponsorUrl)
                    <a href="{{ $sponsorUrl }}" style="color: var(--c-terra); font-weight: 700;">Buka Kelola Sponsor →</a>
                @endif
            </div>
        </div>

        <div class="c61-card">
            <div class="c61-card-body" style="padding: 0.9rem 1.25rem; display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: flex-end;">
                <div class="c61-field" style="flex: 1 1 260px; margin: 0;">
                    <label class="c61-label">Perusahaan</label>
                    <select wire:model.live="balanceId" class="c61-select">
                        @forelse ($cards as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @empty
                            <option value="">Belum ada paket Tagihan Bulanan</option>
                        @endforelse
                    </select>
                </div>
                <div class="c61-field" style="flex: 0 1 200px; margin: 0;">
                    <label class="c61-label">Periode (Tanggal Main)</label>
                    <select wire:model.live="month" class="c61-select">
                        @foreach ($months as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($recap)
                    <div style="display: flex; gap: 0.5rem;">
                        <button type="button" wire:click="exportExcel" wire:loading.attr="disabled" class="c61-btn c61-btn-ghost">Unduh Excel</button>
                        <button type="button" wire:click="exportPdf" wire:loading.attr="disabled" class="c61-btn c61-btn-primary">Unduh PDF</button>
                    </div>
                @endif
            </div>
        </div>

        @if ($recap)
            @php $t = $recap['recap']['totals']; @endphp
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem;">
                <div class="c61-card"><div class="c61-card-body" style="padding: 0.9rem 1.1rem;">
                    <div class="c61-label">Total Jam</div>
                    <div style="font-size: 1.35rem; font-weight: 800; color: var(--c-brown);">{{ $hours($t['hours']) }} jam</div>
                    <div style="font-size: 0.75rem; color: var(--c-muted);">{{ $t['bookings'] }} booking</div>
                </div></div>
                <div class="c61-card"><div class="c61-card-body" style="padding: 0.9rem 1.1rem;">
                    <div class="c61-label">Jam Reguler</div>
                    <div style="font-size: 1.35rem; font-weight: 800; color: var(--c-brown);">{{ $hours($t['regular_hours']) }} jam</div>
                    <div style="font-size: 0.75rem; color: var(--c-muted);">Di luar jam peak</div>
                </div></div>
                <div class="c61-card"><div class="c61-card-body" style="padding: 0.9rem 1.1rem;">
                    <div class="c61-label">Jam Peak</div>
                    <div style="font-size: 1.35rem; font-weight: 800; color: var(--c-brown);">{{ $hours($t['peak_hours']) }} jam</div>
                    <div style="font-size: 0.75rem; color: var(--c-muted);">Mengikuti Master Data</div>
                </div></div>
                <div class="c61-card"><div class="c61-card-body" style="padding: 0.9rem 1.1rem;">
                    <div class="c61-label">Status Pembayaran</div>
                    @if ($recap['payment'])
                        <div style="font-size: 1.35rem; font-weight: 800; color: #047857;">Lunas</div>
                        <div style="font-size: 0.75rem; color: var(--c-muted);">{{ $rupiah($recap['payment']['amount']) }}{{ $recap['payment']['reference'] ? ' · Ref. '.$recap['payment']['reference'] : '' }}</div>
                    @else
                        <div style="font-size: 1.35rem; font-weight: 800; color: #B45309;">Belum Dibayar</div>
                        <div style="font-size: 0.75rem; color: var(--c-muted);">Dicatat melalui Kelola Sponsor → Catat Transfer</div>
                    @endif
                </div></div>
            </div>

            <div class="c61-card">
                <div class="c61-card-head" style="padding: 0.9rem 1.25rem;">
                    <div class="c61-card-title">Rincian Jam Main · {{ $recap['company'] }} · {{ $recap['month'] }}</div>
                    <div style="font-size: 0.75rem; color: var(--c-muted);">
                        Sudah main {{ $hours($t['played_hours']) }} jam · tidak hadir {{ $hours($t['no_show_hours']) }} jam (tetap dihitung) · belum main {{ $hours($t['scheduled_hours']) }} jam
                    </div>
                </div>
                <div style="overflow-x: auto;">
                    <table class="c61-table" style="width: 100%;">
                        <thead>
                            <tr><th>Kode Booking</th><th>Tanggal</th><th>Jam</th><th>Lapangan</th><th style="text-align:right;">Durasi</th><th style="text-align:right;">Reguler</th><th style="text-align:right;">Peak</th><th>Status</th><th>Sumber</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($recap['recap']['rows'] as $r)
                                <tr wire:key="recap-{{ $r['code'] }}">
                                    <td class="code">{{ $r['code'] }}</td>
                                    <td>{{ $r['date'] }}</td>
                                    <td>{{ $r['time'] }}</td>
                                    <td>{{ $r['court'] }}</td>
                                    <td style="text-align:right;">{{ $hours($r['hours']) }}</td>
                                    <td style="text-align:right;">{{ $hours($r['regular_hours']) }}</td>
                                    <td style="text-align:right;">{{ $hours($r['peak_hours']) }}</td>
                                    <td>
                                        <span class="c61-pill {{ $r['status'] === 'EXPIRED' ? 'c61-pill-warn' : ($r['status'] === 'PAID' ? 'c61-pill-cream' : 'c61-pill-ok') }}">{{ $r['status_label'] }}</span>
                                    </td>
                                    <td>{{ $r['source'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="9" style="text-align: center; color: var(--c-muted); padding: 1.25rem;">Tidak ada booking pada periode ini.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div class="c61-card">
            <div class="c61-card-head" style="padding: 0.9rem 1.25rem;">
                <div class="c61-card-title">Riwayat Transfer Perusahaan</div>
                <div style="font-size: 0.75rem; color: var(--c-muted);">50 pencatatan terakhir (membership corporate &amp; Tagihan Bulanan)</div>
            </div>
            <div style="overflow-x: auto;">
                <table class="c61-table" style="width: 100%;">
                    <thead>
                        <tr><th>Tanggal Transfer</th><th>Perusahaan</th><th>Keterangan</th><th style="text-align:right;">Nominal</th><th>Bank · Referensi</th><th>Dicatat Oleh</th><th>Bukti</th><th></th></tr>
                    </thead>
                    <tbody>
                        @forelse ($transfers as $tr)
                            <tr wire:key="transfer-{{ $tr['id'] }}" @if ($tr['voided']) style="opacity: 0.6;" @endif>
                                <td>{{ $tr['transfer_date'] ? \Illuminate\Support\Carbon::parse($tr['transfer_date'])->locale('id')->translatedFormat('d M Y') : '-' }}</td>
                                <td>{{ $tr['company'] }}</td>
                                <td>
                                    {{ $tr['kind'] }}
                                    @if ($tr['voided'])
                                        <div style="font-size: 0.6875rem; color: #B42318;">Dibatalkan oleh {{ $tr['voided']['by_name'] ?? '-' }}: {{ $tr['voided']['reason'] ?? '' }}</div>
                                    @endif
                                </td>
                                <td style="text-align:right; font-weight: 700;">{{ $rupiah($tr['amount']) }}</td>
                                <td>{{ $tr['bank'] }} · {{ $tr['reference'] }}</td>
                                <td>{{ $tr['recorded_by'] }}</td>
                                <td>
                                    @if ($tr['has_proof'])
                                        <button type="button" wire:click="downloadProof('{{ $tr['id'] }}')" class="c61-btn c61-btn-ghost c61-btn-sm">Unduh</button>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td>
                                    @if ($tr['voidable'] && ! $tr['voided'] && $this->canVoid)
                                        <button type="button" wire:click="openVoid('{{ $tr['id'] }}')" class="c61-btn c61-btn-ghost c61-btn-sm" style="color: #B42318;">Batalkan Pencatatan</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" style="text-align: center; color: var(--c-muted); padding: 1.25rem;">Belum ada transfer perusahaan yang dicatat.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if ($voidPaymentId)
        <div class="c61-modal-backdrop" style="z-index: 99999;">
            <div class="c61-modal is-danger" style="max-width: 480px;">
                <div class="c61-modal-head">
                    <div>
                        <div class="c61-modal-eyebrow">Transfer Perusahaan</div>
                        <div class="c61-modal-title">Batalkan Pencatatan Pembayaran</div>
                    </div>
                    <button type="button" wire:click="$set('voidPaymentId', null)" class="c61-modal-close">&times;</button>
                </div>
                <div class="c61-modal-body">
                    <div class="c61-note c61-note-orange">
                        Gunakan hanya untuk pencatatan yang salah input. Nominalnya dikeluarkan dari laporan pendapatan melalui baris pembalik,
                        lalu dapat dicatat ulang melalui Kelola Sponsor → Catat Transfer.
                    </div>
                    <div class="c61-field">
                        <label class="c61-label">Alasan (wajib):</label>
                        <textarea wire:model="voidReason" rows="2" maxlength="255" placeholder="Contoh: nominal salah ketik" class="c61-textarea"></textarea>
                    </div>
                </div>
                <div class="c61-modal-foot">
                    <button type="button" wire:click="$set('voidPaymentId', null)" class="c61-btn c61-btn-ghost">Tutup</button>
                    <button type="button" wire:click="confirmVoid" wire:loading.attr="disabled" wire:target="confirmVoid" class="c61-btn c61-btn-danger">Batalkan Pencatatan</button>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
