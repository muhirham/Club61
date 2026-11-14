{{--
    Slip tes printer stasiun (Menu F&B > Stasiun & Printer > Tes Cetak). Garis |---| selebar {{ \App\Support\ReceiptPaper::COLUMNS }}
    kolom untuk memeriksa posisi kertas: kedua ujungnya harus utuh. Param dari KelolaMenuFnb::testPrintStation().
--}}
@php($ruler = '|'.str_repeat('-', \App\Support\ReceiptPaper::COLUMNS - 2).'|')
<div data-print-slip class="w-full bg-white p-4 font-mono text-xs space-y-1.5">
    <div class="text-center font-black text-lg border-b border-dashed border-gray-400 pb-1">TES PRINTER</div>
    <div style="display: flex; gap: 0.5rem;" data-print-pair="9"><span style="width: 4rem; flex-shrink: 0;">Stasiun</span><span>{{ $stationName }}</span></div>
    <div style="display: flex; gap: 0.5rem;" data-print-pair="9"><span style="width: 4rem; flex-shrink: 0;">Printer</span><span>{{ $printer }}</span></div>
    <div style="display: flex; gap: 0.5rem;" data-print-pair="9"><span style="width: 4rem; flex-shrink: 0;">Geser</span><span>{{ $margin }}</span></div>
    <div style="display: flex; gap: 0.5rem;" data-print-pair="9"><span style="width: 4rem; flex-shrink: 0;">Waktu</span><span>{{ $printedAt }}</span></div>
    <div style="display: flex; gap: 0.5rem;" data-print-pair="9"><span style="width: 4rem; flex-shrink: 0;">Oleh</span><span>{{ $printedBy }}</span></div>
    <div class="border-t border-dashed border-gray-400 my-2"></div>
    <div>{{ $ruler }}</div>
    <div>Garis | kiri dan kanan harus terlihat utuh. Jika terpotong, sesuaikan pengaturan geser cetak.</div>
</div>
