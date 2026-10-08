{{-- Pilihan QRIS di layar kasir: "Bayar Otomatis" (popup QR / VA yang sama dengan checkout online) = utama, QRIS manual
     (input RRN) = cadangan. Metode otomatis = yang dicentang "Tampil di Kasir" di menu Metode Pembayaran Online.
     Butuh properti Livewire $qrisMode & $posOnlineMethod; pemanggil menyembunyikan form manual lewat
     PosMidtransQrisService::resolveMethod() (null = tidak ada metode otomatis → hanya manual). --}}
@php($posMethods = app(\App\Services\Payment\OnlinePaymentMethodService::class)->forPos(isset($grandTotal) ? (float) $grandTotal : null))
@if($posMethods !== [])
    @php($activeAuto = \App\Services\Pos\PosMidtransQrisService::resolveMethod($posOnlineMethod, isset($grandTotal) ? (float) $grandTotal : null))
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem; margin-bottom:0.85rem;">
        @foreach(['MIDTRANS' => ['Bayar Otomatis', 'QR / VA tampil di layar, lunas terkonfirmasi otomatis'], 'MANUAL' => ['QRIS Manual', 'Cadangan: QRIS statis + input RRN']] as $mode => [$modeLabel, $modeHint])
            <button type="button" wire:click="$set('qrisMode', '{{ $mode }}')"
                style="text-align:left; padding:0.6rem 0.75rem; border-radius:10px; cursor:pointer; border:1.5px solid {{ $qrisMode === $mode ? '#662721' : '#E6DAC0' }}; background:{{ $qrisMode === $mode ? '#EFE6D2' : '#FFFFFF' }};">
                <div style="font-size:0.8125rem; font-weight:900; color:#4F2F2A;">{{ $modeLabel }}</div>
                <div style="font-size:0.6875rem; color:#7A5A52;">{{ $modeHint }}</div>
            </button>
        @endforeach
    </div>

    @if($qrisMode === 'MIDTRANS')
        @if(count($posMethods) > 1)
            <div style="display:flex; flex-wrap:wrap; gap:0.4rem; margin-bottom:0.65rem;">
                @foreach($posMethods as $pm)
                    <button type="button" wire:click="$set('posOnlineMethod', '{{ $pm['code'] }}')"
                        style="padding:0.4rem 0.75rem; border-radius:999px; font-size:0.75rem; font-weight:800; cursor:pointer; white-space:nowrap; border:1.5px solid {{ $activeAuto === $pm['code'] ? '#662721' : '#E6DAC0' }}; background:{{ $activeAuto === $pm['code'] ? '#EFE6D2' : '#FFFFFF' }}; color:#4F2F2A;">
                        {{ $pm['label'] }}
                    </button>
                @endforeach
            </div>
        @endif
        <div style="background:#FCF8EE; border:1px dashed #E6DAC0; border-radius:10px; padding:0.85rem 1rem; font-size:0.8125rem; line-height:1.55; color:#662721;">
            Klik tombol bayar → popup <strong>{{ collect($posMethods)->firstWhere('code', $activeAuto)['label'] ?? $activeAuto }}</strong> muncul di layar
            (sama seperti checkout online). Struk keluar otomatis setelah pembayaran diterima — tidak perlu input RRN.
        </div>
    @endif
@endif
