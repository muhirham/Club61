{{--
    Tutup shift kasir (POS Padel & POS F&B) — App\Livewire\Concerns\ClosesPosShift / ShiftClosingService.
    BLIND: sebelum disimpan, layar tidak menampilkan jumlah transaksi / nominal sistem. Setelah disimpan & ada selisih,
    angka yang diketik dikunci di server dan perbandingannya tampil di sini.
--}}
@use('App\Services\Pos\ShiftClosingService', 'SCS')
@php
    $shift = $this->activeShift;
    $rupiah = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
    $edcRows = collect($closingRows)->where('type', SCS::TYPE_EDC)->groupBy('terminal');
    $otherRows = collect($closingRows)->where('type', '!=', SCS::TYPE_EDC);
    $box = 'background:#FCF8EE; border:1px solid #E6DAC0; border-radius:10px; padding:0.7rem 0.8rem;';
    $input = 'width:100%; text-align:right; border:1.5px solid #E6DAC0; border-radius:8px; padding:0.45rem 0.6rem; font-size:0.8125rem; color:#4F2F2A; background:#FFFFFF; font-family:monospace;';
@endphp
@if ($showCloseShiftModal && $shift)
    <div style="position:fixed; inset:0; z-index:9999; background:rgba(15,23,42,0.65); backdrop-filter:blur(6px); display:flex; align-items:center; justify-content:center; padding:1rem;">
        <div style="background:#FFFFFF; border:1.5px solid #E6DAC0; border-radius:18px; box-shadow:0 25px 50px -12px rgba(102,39,33,0.4); width:100%; max-width:560px; max-height:92vh; display:flex; flex-direction:column; overflow:hidden;">
            <div style="background:#FCF8EE; border-bottom:1.5px solid #E6DAC0; padding:0.85rem 1.1rem; display:flex; justify-content:space-between; align-items:flex-start; gap:0.75rem;">
                <div>
                    <div style="font-size:0.625rem; font-weight:900; color:#BE123C; text-transform:uppercase; letter-spacing:0.06em;">Closing Register</div>
                    <div style="font-size:1rem; font-weight:900; color:#4F2F2A; margin-top:0.15rem;">Tutup Shift &amp; Cocokkan Settlement</div>
                    <div style="font-size:0.6875rem; color:#7A5A52; margin-top:0.15rem;">{{ $shift->shift_number }} &bull; dibuka {{ $shift->openedBy?->name ?? 'Kasir' }} ({{ $shift->opened_at->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB)</div>
                </div>
                <button type="button" wire:click="$set('showCloseShiftModal', false)" aria-label="Tutup" style="background:none; border:none; font-size:1.25rem; color:#78350F; cursor:pointer; line-height:1;">&times;</button>
            </div>

            <div style="padding:1rem 1.1rem; display:flex; flex-direction:column; gap:0.8rem; overflow-y:auto;">
                @if ($closingMismatch === null)
                    <div style="background:#ECFDF5; border:1.5px solid #6EE7B7; border-radius:8px; padding:0.6rem 0.8rem; font-size:0.75rem; color:#065F46; font-weight:700; line-height:1.45;">
                        Cetak <strong>struk settlement</strong> atau <strong>Audit Report</strong> di mesin EDC dan buka aplikasi merchant QRIS, lalu ketik angkanya di bawah.
                        Angka sistem baru tampil setelah disimpan — angka yang sudah disimpan tidak bisa diubah.
                    </div>

                    @forelse ($edcRows as $terminal => $rows)
                        <div wire:key="close-edc-{{ $terminal }}" style="{{ $box }}">
                            <div style="font-weight:900; color:#4F2F2A; font-size:0.8125rem;">{{ SCS::terminalLabel($terminal) }}</div>
                            <div style="display:flex; flex-wrap:wrap; gap:0.4rem 1rem; margin:0.4rem 0 0.55rem; font-size:0.75rem; color:#4F2F2A;">
                                <label style="display:flex; align-items:center; gap:0.35rem; cursor:pointer;">
                                    <input type="radio" wire:model="edcModes.{{ $terminal }}" value="{{ SCS::MODE_SETTLE }}"> Settle sekarang <span style="color:#9CA3AF;">(pakai struk settlement)</span>
                                </label>
                                <label style="display:flex; align-items:center; gap:0.35rem; cursor:pointer;">
                                    <input type="radio" wire:model="edcModes.{{ $terminal }}" value="{{ SCS::MODE_AUDIT }}"> Belum settle — ganti shift <span style="color:#9CA3AF;">(pakai Audit Report)</span>
                                </label>
                            </div>
                            @foreach ($rows as $row)
                                <div style="display:flex; align-items:center; justify-content:space-between; gap:0.75rem; margin-top:0.35rem;">
                                    <span style="font-size:0.75rem; font-weight:700; color:#4F2F2A;">{{ str_contains($row['key'], 'CREDIT') ? 'Kartu Kredit' : 'Kartu Debit' }}</span>
                                    <input type="text" inputmode="numeric" wire:model="settlementInputs.{{ $row['key'] }}" style="{{ $input }} max-width:190px;" placeholder="Total di struk (0 kalau tidak ada)">
                                </div>
                            @endforeach
                        </div>
                    @empty
                    @endforelse

                    @foreach ($otherRows as $row)
                        <div wire:key="close-row-{{ $row['key'] }}" style="{{ $box }} display:flex; align-items:center; justify-content:space-between; gap:0.75rem;">
                            <div>
                                <div style="font-weight:800; color:#4F2F2A; font-size:0.78rem;">{{ $row['label'] }}</div>
                                <div style="font-size:0.65rem; color:#9CA3AF;">
                                    @if ($row['type'] === SCS::TYPE_AUTO) Lunas dipastikan sistem pembayaran — tidak perlu diketik
                                    @elseif ($row['type'] === SCS::TYPE_TRANSFER) Dicek admin pada mutasi rekening (bukti terlampir) — tidak perlu diketik
                                    @elseif ($row['type'] === SCS::TYPE_INFO) Benefit (bukan uang) — tidak perlu dicek
                                    @else Cek: {{ $row['source'] }} (jam shift ini)
                                    @endif
                                </div>
                            </div>
                            @if ($row['type'] === SCS::TYPE_MANUAL)
                                <input type="text" inputmode="numeric" wire:model="settlementInputs.{{ $row['key'] }}" style="{{ $input }} max-width:190px;" placeholder="Total di aplikasi / mutasi">
                            @elseif ($row['type'] === SCS::TYPE_TRANSFER)
                                <span style="font-size:0.75rem; font-weight:900; color:#1E40AF; white-space:nowrap;">Dicek admin</span>
                            @else
                                <span style="font-size:0.75rem; font-weight:900; color:#15803D; white-space:nowrap;">&#10003; Terverifikasi</span>
                            @endif
                        </div>
                    @endforeach

                    @if (empty($closingRows))
                        <div style="font-size:0.75rem; text-align:center; padding:1.25rem 0; color:#9CA3AF;">Belum ada transaksi di shift ini — tidak ada yang perlu dicocokkan.</div>
                    @endif

                    <div>
                        <label style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">Catatan penutupan <span style="font-weight:500; color:#9CA3AF;">(opsional)</span></label>
                        <textarea wire:model="closingNotes" rows="2" style="width:100%; border:1.5px solid #E6DAC0; border-radius:8px; padding:0.5rem 0.75rem; font-size:0.75rem; color:#4F2F2A; background:#FCF8EE;" placeholder="Catatan serah terima, nomor batch settlement..."></textarea>
                    </div>
                @else
                    @php $diff = (float) $closingMismatch['difference']; @endphp
                    <div style="background:#FFF1F2; border:1.5px solid #FECDD3; border-radius:8px; padding:0.6rem 0.8rem; font-size:0.75rem; color:#9F1239; font-weight:700; line-height:1.45;">
                        Ada selisih antara POS dan struk / aplikasi. Angka yang Anda ketik sudah dikunci.
                        Tulis penjelasannya — shift ditutup dengan status <strong>Perlu Review</strong> dan diputuskan admin.
                    </div>
                    <div style="overflow-x:auto;">
                        <table style="width:100%; font-size:0.72rem; border-collapse:collapse;">
                            <thead>
                                <tr style="background:#F7F0DB; color:#4F2F2A; text-transform:uppercase; font-size:0.62rem; letter-spacing:0.04em;">
                                    <th style="text-align:left; padding:0.45rem;">Kategori</th>
                                    <th style="text-align:right; padding:0.45rem;">Sistem</th>
                                    <th style="text-align:right; padding:0.45rem;">Diketik</th>
                                    <th style="text-align:right; padding:0.45rem;">Selisih</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($closingMismatch['rows'] as $row)
                                    <tr style="border-bottom:1px solid #EFE6D2;">
                                        <td style="padding:0.45rem; font-weight:700; color:#4F2F2A;">{{ $row['label'] }}
                                            @if ($row['mode'] ?? null)<div style="font-size:0.6rem; color:#9CA3AF;">{{ $row['mode'] === SCS::MODE_SETTLE ? 'Settle' : 'Audit Report' }}</div>@endif
                                        </td>
                                        <td style="padding:0.45rem; text-align:right; font-family:monospace;">{{ $rupiah($row['system_amount']) }}</td>
                                        <td style="padding:0.45rem; text-align:right; font-family:monospace;">{{ in_array($row['type'], [SCS::TYPE_EDC, SCS::TYPE_MANUAL], true) ? $rupiah($row['settled_amount']) : '✔' }}</td>
                                        <td style="padding:0.45rem; text-align:right; font-family:monospace; font-weight:900; color:{{ abs($row['difference']) < 0.005 ? '#15803D' : '#BE123C' }};">
                                            {{ abs($row['difference']) < 0.005 ? 'COCOK' : ($row['difference'] > 0 ? '+' : '-').$rupiah(abs($row['difference'])) }}
                                        </td>
                                    </tr>
                                @endforeach
                                <tr style="background:#F7F0DB; font-weight:900;">
                                    <td style="padding:0.45rem;" colspan="3">TOTAL SELISIH</td>
                                    <td style="padding:0.45rem; text-align:right; font-family:monospace; color:#BE123C;">{{ ($diff > 0 ? '+' : '-').$rupiah(abs($diff)) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div>
                        <label style="display:block; font-size:0.75rem; font-weight:800; color:#4F2F2A; margin-bottom:0.3rem;">Penjelasan selisih <span style="color:#BE123C;">*wajib</span></label>
                        <textarea wire:model="closingNotes" rows="3" style="width:100%; border:1.5px solid #FECDD3; border-radius:8px; padding:0.5rem 0.75rem; font-size:0.75rem; color:#4F2F2A; background:#FFFFFF;" placeholder="Mis. BK-PAD-XXXX customer bayar QRIS, tercatat EDC (salah klik)."></textarea>
                    </div>
                @endif
            </div>

            <div style="background:#FCF8EE; border-top:1px solid #E6DAC0; padding:0.75rem 1.1rem; display:flex; justify-content:flex-end; gap:0.5rem;">
                <button type="button" wire:click="$set('showCloseShiftModal', false)" style="padding:0.45rem 0.9rem; font-size:0.8125rem; font-weight:700; border:1.5px solid #E6DAC0; background:#FFFFFF; border-radius:8px; cursor:pointer; color:#4F2F2A;">Batal</button>
                <button type="button" wire:click="executeCloseShift" wire:loading.attr="disabled" style="padding:0.45rem 1.1rem; font-size:0.8125rem; font-weight:900; background:#BE123C; color:#FFFFFF; border:none; border-radius:8px; cursor:pointer;">
                    {{ $closingMismatch === null ? 'Simpan & Cek' : 'Tutup Shift — Minta Review' }}
                </button>
            </div>
        </div>
    </div>
@endif
