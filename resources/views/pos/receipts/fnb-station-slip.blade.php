{{--
    Slip pesanan satu stasiun F&B (Kitchen, Bar, ...) — tanpa harga, gaya slip dapur: meja & antrian besar di atas, item
    tebal, catatan menjorok, jumlah item untuk dicocokkan. Dikirim ke printer LAN stasiunnya begitu order lunas, dan bisa
    dikirim ulang / dicetak di printer kasir dari modal struk.
    Param: $slip (App\Services\Fnb\StationTicketService::slipData()). Baris besar (text-sm ke atas) = dobel tinggi di printer.
    Penanda cetak (club61ReceiptLines): data-print-pair="N" = label selebar N kolom lalu nilainya, data-print-hang="N" =
    baris sambungan menjorok N kolom, data-print-indent="N" = semua baris menjorok N kolom.
--}}
@php
    $queue = $slip['queue_number'] ? str_pad((string) $slip['queue_number'], 3, '0', STR_PAD_LEFT) : null;
    $place = ($slip['order_type'] ?? null) === 'DINE_IN' ? 'MEJA '.($slip['table_number'] ?: '-') : 'BAWA PULANG';
@endphp
<div data-print-slip class="w-full bg-white p-4 font-mono text-xs space-y-1.5">
    <div class="text-center font-black text-lg border-b border-dashed border-gray-400 pb-1">PESANAN {{ strtoupper($slip['station_name']) }}</div>
    <div class="flex justify-between font-black text-sm border-b border-dashed border-gray-400 pb-1">
        <span>{{ $place }}</span>
        @if($queue)
            <span>ANTRIAN {{ $queue }}</span>
        @endif
    </div>
    <div style="display: flex; gap: 0.5rem;" data-print-pair="7"><span style="width: 3rem; flex-shrink: 0;">Waktu</span><span>{{ $slip['created_at'] }}</span></div>
    <div style="display: flex; gap: 0.5rem;" data-print-pair="7"><span style="width: 3rem; flex-shrink: 0;">Order</span><span>{{ $slip['order_number'] }}</span></div>
    @if(! empty($slip['customer_name']))
        <div style="display: flex; gap: 0.5rem;" data-print-pair="7"><span style="width: 3rem; flex-shrink: 0;">Nama</span><span>{{ $slip['customer_name'] }}</span></div>
    @endif
    <div class="border-t border-dashed border-gray-400 my-2"></div>
    @foreach($slip['items'] as $item)
        @php($hang = strlen($item['quantity'].'x '))
        <div class="font-black text-sm" data-print-hang="{{ $hang }}">{{ $item['quantity'] }}x {{ $item['name'] }}</div>
        @if(trim((string) ($item['notes'] ?? '')) !== '')
            <div class="pl-3" data-print-indent="{{ $hang }}" data-print-hang="2">* {{ $item['notes'] }}</div>
        @endif
    @endforeach
    <div class="border-t border-dashed border-gray-400 my-2"></div>
    <div style="display: flex; gap: 0.35rem;" data-print-pair><span>Jumlah item:</span><span>{{ collect($slip['items'])->sum('quantity') }}</span></div>
    <div style="display: flex; gap: 0.35rem;" data-print-pair><span>Oleh:</span><span>{{ $slip['cashier_name'] ?? '-' }}</span></div>
</div>
