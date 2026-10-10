{{--
    Slip pesanan satu stasiun F&B (Kitchen, Bar, ...) — tanpa harga. Dikirim tablet kasir ke printer LAN stasiunnya begitu
    order lunas, dan bisa dikirim ulang / dicetak di printer kasir dari modal struk.
    Param: $slip (App\Services\Fnb\StationTicketService::slipData()). Baris besar (text-sm ke atas) = dobel tinggi di printer.
--}}
<div data-print-slip class="w-full bg-white p-4 font-mono text-xs space-y-1.5">
    <div class="text-center font-black text-lg border-b border-dashed border-gray-400 pb-1">PESANAN {{ strtoupper($slip['station_name']) }}</div>
    <div class="text-center pb-2 border-b border-dashed border-gray-400">
        <div class="text-[10px] uppercase tracking-widest font-bold">Nomor Antrian</div>
        <div class="text-4xl font-black leading-tight">{{ $slip['queue_number'] ? str_pad((string) $slip['queue_number'], 3, '0', STR_PAD_LEFT) : '—' }}</div>
    </div>
    @if(($slip['order_type'] ?? null) === 'DINE_IN')
        <div class="flex justify-between font-black text-sm"><span>MEJA</span><span>{{ $slip['table_number'] ?: '—' }}</span></div>
    @else
        <div class="text-center font-black text-sm">BAWA PULANG</div>
    @endif
    @if(! empty($slip['customer_name']))
        <div class="flex justify-between"><span>Nama</span><span>{{ $slip['customer_name'] }}</span></div>
    @endif
    <div class="flex justify-between"><span>No. Order</span><span>{{ $slip['order_number'] }}</span></div>
    <div class="flex justify-between"><span>Waktu</span><span>{{ $slip['created_at'] }}</span></div>
    <div class="border-t border-dashed border-gray-400 my-2"></div>
    @foreach($slip['items'] as $item)
        <div class="font-black text-sm">{{ $item['quantity'] }}x {{ $item['name'] }}</div>
        @if(trim((string) ($item['notes'] ?? '')) !== '')
            <div class="pl-3">- {{ $item['notes'] }}</div>
        @endif
    @endforeach
    <div class="border-t border-dashed border-gray-400 my-2"></div>
    <div class="text-center">Kasir {{ $slip['cashier_name'] ?? '-' }}</div>
</div>
