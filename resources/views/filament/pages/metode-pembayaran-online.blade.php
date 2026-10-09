<x-filament-panels::page>
    <div class="c61 mp-root" style="padding: 0; gap: 1rem;">
        @include('filament.partials.c61-admin-style')

        <div class="c61-card" style="border-left: 4px solid var(--c-terra);">
            <div class="c61-card-body" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr)); gap: 1rem 1.5rem; font-size: 0.8125rem; line-height: 1.6; color: var(--c-muted);">
                <div>
                    <div class="c61-card-title" style="font-size: 1.0625rem; margin-bottom: 0.2rem;">Metode yang bisa dipilih customer saat bayar online</div>
                    Berlaku untuk checkout booking padel, bayar ulang / selisih reschedule di invoice, dan pembelian membership online.
                    Urutan di sini = urutan yang tampil ke customer. Metode di luar batas nominal otomatis disembunyikan.
                </div>
                <div class="c61-note" style="font-size: 0.75rem;">
                    Aktifkan hanya metode yang <b>sudah aktif di dashboard Midtrans</b>. QRIS mengikuti ketentuan BI: maksimal
                    <b>Rp10.000.000</b> per transaksi. Pembayaran di kasir (EDC / QRIS frontdesk) tidak diatur di sini.
                </div>
                @php $bookingTimes = app(\App\Services\Padel\BookingTimeService::class); @endphp
                <div style="grid-column: 1 / -1; padding-top: 0.75rem; border-top: 1px dashed var(--c-line); font-size: 0.75rem;">
                    Slot ditahan <b style="color: var(--c-terra);">{{ $bookingTimes->holdMinutes() }} menit</b> sebelum klik bayar, lalu customer punya
                    <b style="color: var(--c-terra);">{{ $bookingTimes->paymentWindowMinutes() }} menit</b> untuk membayar (batas yang sama dikirim ke Midtrans —
                    pengaturan "Payment Expiry" di dashboard Midtrans tidak dipakai). Ubah lewat tombol <b style="color: var(--c-brown);">Atur Batas Waktu</b>.
                </div>
            </div>
        </div>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
