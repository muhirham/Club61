{{--
    Tabel Riwayat Transaksi POS (Walk-In Padel & Jual Membership).
    Param: $rows (Collection of array: payment_id|order_id, time, order_number, type, customer, detail,
    cashier, method, amount, status opsional — default LUNAS, mis. DIREFUND / DIBATALKAN), $title, $receiptAction (nama method Livewire), $idKey ('payment_id' / 'order_id').
--}}
@php
    $th = 'text-align:left; padding:0.55rem 0.6rem; font-size:0.625rem; font-weight:900; color:#662721; text-transform:uppercase; letter-spacing:0.05em; border-bottom:1.5px solid #E6DAC0; white-space:nowrap;';
    $td = 'padding:0.55rem 0.6rem; font-size:0.75rem; color:#4F2F2A; border-bottom:1px solid #EFE6D2; vertical-align:top;';
    $total = $rows->sum('amount');
@endphp
<div style="background:#FFFFFF; border:1.5px solid #E6DAC0; border-radius:14px; padding:1rem; box-shadow:0 2px 10px rgba(0,0,0,0.04);">
    <div style="display:flex; justify-content:space-between; align-items:flex-end; gap:0.75rem; flex-wrap:wrap; margin-bottom:0.75rem;">
        <div>
            <div style="font-size:0.9375rem; font-weight:900; color:#4F2F2A;">{{ $title }}</div>
            <div style="font-size:0.6875rem; color:#7A5A52;">
                {{ $rows->count() }} transaksi &bull; total Rp {{ number_format($total, 0, ',', '.') }}
                @if ($rows->count() >= 100) &bull; menampilkan 100 terbaru, persempit dengan pencarian @endif
            </div>
        </div>
        <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
            <input type="date" wire:model.live="historyDate"
                style="border:1.5px solid #E6DAC0; border-radius:8px; padding:0.35rem 0.6rem; font-size:0.75rem; background:#FCF8EE; font-weight:700;">
            <input type="text" wire:model.live.debounce.400ms="historySearch" placeholder="Cari no. order / kode booking / nama / HP"
                style="min-width:260px; border:1.5px solid #E6DAC0; border-radius:8px; padding:0.35rem 0.6rem; font-size:0.75rem; background:#FFFFFF;">
        </div>
    </div>

    <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse;">
            <thead>
                <tr>
                    <th style="{{ $th }}">Jam</th>
                    <th style="{{ $th }}">No. Order</th>
                    <th style="{{ $th }}">Jenis</th>
                    <th style="{{ $th }}">Customer</th>
                    <th style="{{ $th }}">Detail</th>
                    <th style="{{ $th }}">Kasir</th>
                    <th style="{{ $th }}">Metode</th>
                    <th style="{{ $th }} text-align:right;">Nominal</th>
                    <th style="{{ $th }}">Status</th>
                    <th style="{{ $th }}"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr wire:key="hist-{{ $row[$idKey] }}">
                        <td style="{{ $td }} font-weight:800;">{{ $row['time'] }}</td>
                        <td style="{{ $td }} font-family:var(--font-mono, monospace); color:#662721; white-space:nowrap;">{{ $row['order_number'] }}</td>
                        <td style="{{ $td }}">{{ $row['type'] }}</td>
                        <td style="{{ $td }} font-weight:700;">{{ $row['customer'] }}</td>
                        <td style="{{ $td }} color:#662721; font-size:0.6875rem;">{{ $row['detail'] }}</td>
                        <td style="{{ $td }}">{{ $row['cashier'] }}</td>
                        <td style="{{ $td }}">{{ $row['method'] }}</td>
                        <td style="{{ $td }} text-align:right; font-family:var(--font-mono, monospace); font-weight:900; white-space:nowrap;">Rp {{ number_format($row['amount'], 0, ',', '.') }}</td>
                        <td style="{{ $td }}">
                            @php
                                $status = strtoupper((string) ($row['status'] ?? 'LUNAS'));
                                $badge = match (true) {
                                    $status === 'LUNAS' => 'background:#ECFDF5; color:#065F46; border:1px solid #A7F3D0;',
                                    str_contains($status, 'REFUND') => 'background:#FEF2F2; color:#991B1B; border:1px solid #FECACA;',
                                    str_contains($status, 'BATAL') => 'background:#F3F4F6; color:#374151; border:1px solid #D1D5DB;',
                                    default => 'background:#FFFBEB; color:#92400E; border:1px solid #FDE68A;',
                                };
                            @endphp
                            <span style="{{ $badge }} border-radius:999px; padding:0.1rem 0.5rem; font-size:0.625rem; font-weight:800; white-space:nowrap;">{{ $status }}</span>
                        </td>
                        <td style="{{ $td }} text-align:right;">
                            <button type="button" wire:click="{{ $receiptAction }}('{{ $row[$idKey] }}')" wire:loading.attr="disabled"
                                style="padding:0.3rem 0.7rem; border-radius:7px; border:1.5px solid #E6DAC0; background:#FCF8EE; color:#4F2F2A; font-size:0.6875rem; font-weight:800; cursor:pointer; white-space:nowrap;">
                                Lihat &amp; Cetak Struk
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" style="{{ $td }} text-align:center; color:#7A5A52; padding:1.5rem;">Belum ada transaksi pada tanggal ini.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
