{{--
    Detail satu transaksi Buku Transaksi (Modul 17 FR-04). Data: LedgerTransactionPresenter::detail().
    Semua teks dari input user (nama, catatan refund) dicetak lewat {{ }} (ter-escape).
--}}
@php
    use App\Models\Finance\LedgerEntry;
    $rp = fn ($v) => \App\Filament\Pages\BukuTransaksi::rupiah($v);
    $tz = \App\Services\Finance\LedgerReport::TIMEZONE;
    $isRefund = $head->entry_type === LedgerEntry::TYPE_REFUND;
    $box = 'border:1.5px solid #E6DAC0; border-radius:12px; padding:0.85rem 1rem; background:#FFFFFF;';
    $h = 'font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:0.08em; color:#7A5A52; margin-bottom:0.5rem;';
    $th = 'text-align:left; padding:0.35rem 0.5rem; font-size:0.68rem; color:#7A5A52; font-weight:800; border-bottom:1px solid #EFE4C8;';
    $td = 'padding:0.35rem 0.5rem; border-bottom:1px solid #EFE6D2; font-size:0.78rem; color:#4F2F2A;';
    $num = $td.' text-align:right; font-variant-numeric:tabular-nums;';
@endphp

<div style="display:flex; flex-direction:column; gap:0.85rem;">
    {{-- Ringkasan --}}
    <div style="{{ $box }} display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:0.6rem; font-size:0.78rem;">
        <div><div style="color:#7A5A52; font-size:0.68rem;">Jenis</div><b>{{ LedgerEntry::ENTRY_TYPES[$head->entry_type] ?? $head->entry_type }}</b></div>
        <div><div style="color:#7A5A52; font-size:0.68rem;">Sumber</div><b>{{ LedgerEntry::sourceLabel($head->source) }}</b></div>
        <div><div style="color:#7A5A52; font-size:0.68rem;">Customer</div><b>{{ $head->customer_name ?? '-' }}</b></div>
        <div><div style="color:#7A5A52; font-size:0.68rem;">Kasir / Shift</div><b>{{ \App\Services\Finance\LedgerReport::cashierLabel($head) }}</b>{{ $shift_number ? ' · '.$shift_number : '' }}</div>
        <div><div style="color:#7A5A52; font-size:0.68rem;">Metode</div><b>{{ $head->payment_method_label ?? $head->payment_method ?? '-' }}</b></div>
        <div><div style="color:#7A5A52; font-size:0.68rem;">{{ $isRefund ? 'Uang Keluar' : 'Uang Diterima' }}</div><b style="font-size:0.95rem; color:{{ $isRefund ? '#B42318' : '#4F2F2A' }};">{{ $rp($totals['total_amount']) }}</b></div>
    </div>

    @if ($isRefund && $refund)
        <div style="{{ $box }} background:#FFF8F6; border-color:#F3C4BA;">
            <div style="{{ $h }}">Refund</div>
            <div style="font-size:0.78rem; line-height:1.6; color:#4F2F2A;">
                <div>Alasan: {{ $refund->reason }}</div>
                @if ($refund->refund_method)<div>Dikembalikan via: <b>{{ $refund->refund_method }}</b>{{ $refund->refund_reference ? ' · Ref '.$refund->refund_reference : '' }}</div>@endif
                @if ($refund->processedBy)<div>Diproses oleh: {{ $refund->processedBy->name }}</div>@endif
                @if ($refund->admin_notes)<div>Catatan: {{ $refund->admin_notes }}</div>@endif
            </div>
        </div>
    @endif

    {{-- Pembagian per kategori --}}
    <div style="{{ $box }}">
        <div style="{{ $h }}">Pembagian per kategori</div>
        <table style="width:100%; border-collapse:collapse;">
            <thead><tr>
                <th style="{{ $th }}">Kategori</th>
                <th style="{{ $th }} text-align:right;">Harga Item</th>
                <th style="{{ $th }} text-align:right;">Diskon</th>
                <th style="{{ $th }} text-align:right;">Bersih</th>
                <th style="{{ $th }} text-align:right;">Layanan</th>
                <th style="{{ $th }} text-align:right;">Pajak</th>
                <th style="{{ $th }} text-align:right;">Total Dibayar</th>
            </tr></thead>
            <tbody>
                @foreach ($rows as $r)
                    <tr>
                        <td style="{{ $td }}">
                            {{ LedgerEntry::categoryLabel($r->category) }}
                            @if (! empty($r->meta['items']))
                                <div style="font-size:0.68rem; color:#7A5A52;">{{ implode(', ', array_slice((array) $r->meta['items'], 0, 6)) }}</div>
                            @endif
                        </td>
                        <td style="{{ $num }}">{{ $rp($r->gross_amount) }}</td>
                        <td style="{{ $num }}">{{ $rp($r->discount_amount) }}</td>
                        <td style="{{ $num }} font-weight:800;">{{ $rp($r->net_amount) }}</td>
                        <td style="{{ $num }}">{{ $rp($r->service_amount) }}</td>
                        <td style="{{ $num }}">{{ $rp($r->tax_amount) }}</td>
                        <td style="{{ $num }} font-weight:800;">{{ $rp($r->total_amount) }}</td>
                    </tr>
                @endforeach
                @if ($rows->count() > 1)
                    <tr>
                        <td style="{{ $td }} font-weight:900;">Total</td>
                        <td style="{{ $num }}">{{ $rp($totals['gross_amount']) }}</td>
                        <td style="{{ $num }}">{{ $rp($totals['discount_amount']) }}</td>
                        <td style="{{ $num }} font-weight:900;">{{ $rp($totals['net_amount']) }}</td>
                        <td style="{{ $num }}">{{ $rp($totals['service_amount']) }}</td>
                        <td style="{{ $num }}">{{ $rp($totals['tax_amount']) }}</td>
                        <td style="{{ $num }} font-weight:900;">{{ $rp($totals['total_amount']) }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
        @if ($totals['benefit_amount'] > 0)
            <div style="font-size:0.72rem; color:#7A5A52; margin-top:0.45rem;">Ditanggung kuota member / voucher sponsor (non-tunai): <b>{{ $rp($totals['benefit_amount']) }}</b></div>
        @endif
    </div>

    {{-- Bukti bayar --}}
    @if (! empty($proof))
        <div style="{{ $box }}">
            <div style="{{ $h }}">Bukti bayar</div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:0.35rem 1rem; font-size:0.76rem;">
                @foreach ($proof as $label => $value)
                    <div><span style="color:#7A5A52;">{{ $label }}:</span> <span style="font-family:monospace;">{{ $value }}</span></div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Item order --}}
    @if ($items->isNotEmpty())
        <div style="{{ $box }}">
            <div style="{{ $h }}">Isi order {{ $order?->order_number }}</div>
            <table style="width:100%; border-collapse:collapse;">
                <tbody>
                    @foreach ($items as $item)
                        <tr>
                            <td style="{{ $td }}">{{ $item->item_name }} <span style="color:#7A5A52; font-size:0.68rem;">· {{ $item->item_type }}</span></td>
                            <td style="{{ $num }}">{{ $item->quantity }} × {{ $rp($item->unit_price) }}</td>
                            <td style="{{ $num }}">{{ $rp($item->subtotal) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if ($order)
                <div style="font-size:0.72rem; color:#7A5A52; margin-top:0.45rem;">
                    Total order saat ini {{ $rp($order->grand_total) }} · status {{ $order->payment_status }}
                    (total order bisa berubah setelah reschedule — angka buku di atas tidak ikut berubah).
                </div>
            @endif
        </div>
    @endif

    {{-- Semua pembayaran & refund order --}}
    <div style="{{ $box }}">
        <div style="{{ $h }}">Riwayat pembayaran & refund order</div>
        <table style="width:100%; border-collapse:collapse;">
            <thead><tr>
                <th style="{{ $th }}">Waktu</th>
                <th style="{{ $th }}">Jenis</th>
                <th style="{{ $th }}">Metode</th>
                <th style="{{ $th }}">Status</th>
                <th style="{{ $th }} text-align:right;">Nominal</th>
            </tr></thead>
            <tbody>
                @foreach ($payments as $p)
                    <tr>
                        <td style="{{ $td }}">{{ ($p['paid_at'] ?? $p['created_at'])?->timezone($tz)->format('d/m/Y H:i') }}</td>
                        <td style="{{ $td }}">{{ $p['kind'] }}<div style="font-size:0.66rem; color:#7A5A52; font-family:monospace;">{{ $p['transaction_id'] }}</div></td>
                        <td style="{{ $td }}">{{ $p['method'] }}</td>
                        <td style="{{ $td }}">{{ $p['status'] }}</td>
                        <td style="{{ $num }}">{{ $rp($p['amount']) }}</td>
                    </tr>
                @endforeach
                @foreach ($refunds as $rf)
                    <tr style="background:#FFF8F6;">
                        <td style="{{ $td }}">{{ ($rf->processed_at ?? $rf->created_at)?->timezone($tz)->format('d/m/Y H:i') }}</td>
                        <td style="{{ $td }}">Refund<div style="font-size:0.66rem; color:#7A5A52;">{{ \Illuminate\Support\Str::limit($rf->reason, 80) }}</div></td>
                        <td style="{{ $td }}">{{ $rf->refund_method ?? '-' }}</td>
                        <td style="{{ $td }}">{{ $rf->status }}</td>
                        <td style="{{ $num }} color:#B42318;">-{{ $rp($rf->refund_amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Booking terkait --}}
    @if ($bookings->isNotEmpty())
        <div style="{{ $box }}">
            <div style="{{ $h }}">Booking terkait</div>
            @foreach ($bookings as $b)
                <div style="font-size:0.76rem; padding:0.3rem 0; border-bottom:1px solid #EFE6D2;">
                    <b style="font-family:monospace;">{{ $b->booking_code }}</b> · {{ $b->court?->name ?? 'Lapangan' }} ·
                    {{ $b->start_time?->translatedFormat('d M Y H:i') }}–{{ $b->end_time?->format('H:i') }} · {{ $b->status }}
                    @if ((int) $b->reschedule_count > 0)
                        · direschedule {{ $b->reschedule_count }}x
                    @endif
                    @if ((float) $b->reschedule_forfeited_amount > 0)
                        · selisih hangus {{ $rp($b->reschedule_forfeited_amount) }}
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    {{-- Log aktivitas order (hanya untuk yang punya izin Log Aktivitas) --}}
    @if ($activity !== null)
        <div style="{{ $box }}">
            <div style="{{ $h }}">Log aktivitas order ini</div>
            @forelse ($activity as $log)
                <div style="font-size:0.74rem; padding:0.3rem 0; border-bottom:1px solid #EFE6D2;">
                    <span style="color:#7A5A52;">{{ $log->created_at?->timezone($tz)->format('d/m/Y H:i') }}</span>
                    · {{ $log->causer_name ?? 'Sistem' }} · {{ $log->description }}
                </div>
            @empty
                <div style="font-size:0.74rem; color:#7A5A52;">Belum ada log untuk order ini.</div>
            @endforelse
            @if ($order)
                <a href="{{ \App\Filament\Pages\LogAktivitas::getUrl(['cari' => $order->order_number]) }}" style="display:inline-block; margin-top:0.5rem; font-size:0.74rem; color:#662721; font-weight:800; text-decoration:underline;">Buka di Log Aktivitas</a>
            @endif
        </div>
    @endif
</div>
