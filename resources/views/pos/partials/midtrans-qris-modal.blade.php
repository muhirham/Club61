{{--
    "Bayar Otomatis" di layar kasir (App\Services\Pos\PosMidtransQrisService). Dipakai POS Walk-In, Jual Membership & F&B.
    Variabel: $pendingQris (array dari PosMidtransQrisService::present), $pollAction, $cancelAction, $simulateAction.

    SATU kartu: tampilan pembayaran yang sama dengan checkout online (QRIS berbingkai QRIS/GPN atau nomor VA) DITANAM di
    dalam kartu (Snap embed) — tidak ada popup kedua dan tidak ada tombol tutup yang bisa tak sengaja ditekan. Kartu hanya
    hilang kalau lunas (struk tampil), kedaluwarsa, atau kasir menekan Batalkan + konfirmasi. Halaman di-refresh → kartu
    muncul lagi (tagihan yang masih menunggu dilanjutkan). Status dicek tiap 3 detik.
--}}
@if($pendingQris)
    @php($snapClientKey = trim((string) config('services.midtrans.client_key')))
    <div wire:poll.3s="{{ $pollAction }}" wire:key="pos-autopay-{{ $pendingQris['payment_id'] }}"
         style="position:fixed; inset:0; z-index:9999; background:rgba(15,23,42,0.65); backdrop-filter:blur(6px); display:flex; align-items:center; justify-content:center; padding:1rem;">
        <div x-data="{
                left: '',
                timer: null,
                confirming: false,
                loaded: false,
                failed: false,
                tick() {
                    const ms = new Date(@js($pendingQris['expires_at'])).getTime() - Date.now();
                    if (ms <= 0) { this.left = '00:00'; return; }
                    const s = Math.floor(ms / 1000);
                    this.left = String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
                },
                loadScript(done) {
                    if (window.snap && typeof window.snap.embed === 'function') { done(); return; }
                    const key = @js($snapClientKey);
                    if (! key) { this.failed = true; return; }
                    let s = document.querySelector('script[data-club61-snap]');
                    if (! s) {
                        s = document.createElement('script');
                        s.src = @js(config('services.midtrans.is_production') ? 'https://app.midtrans.com/snap/snap.js' : 'https://app.sandbox.midtrans.com/snap/snap.js');
                        s.setAttribute('data-client-key', key);
                        s.dataset.club61Snap = '1';
                        document.head.appendChild(s);
                    }
                    s.addEventListener('load', done);
                    s.addEventListener('error', () => { this.failed = true; });
                },
                show() {
                    if (@js((bool) $pendingQris['is_mock']) || ! @js($pendingQris['snap_token'])) { return; }
                    this.failed = false;
                    this.loadScript(() => {
                        const el = this.$refs.embed;
                        if (! el) { return; }
                        el.innerHTML = '';
                        // snap.js menyimpan tampilan sebelumnya (transaksi lalu di halaman yang sama) → lepas dulu, kalau tidak embed baru ditolak.
                        this.release();
                        try {
                            window.snap.embed(@js($pendingQris['snap_token']), {
                                embedId: el.id,
                                onSuccess: () => $wire.call(@js($pollAction)),
                                onPending: () => {},
                                onError: () => { this.failed = true; },
                                onClose: () => {},
                            });
                            this.loaded = true;
                        } catch (e) { this.failed = true; }
                    });
                },
                init() {
                    this.tick();
                    this.timer = setInterval(() => {
                        this.tick();
                        // Penjaga: tampilan pembayaran tertutup (tanda × di dalamnya tidak mengirim sinyal tutup) → muat ulang.
                        if (this.loaded && ! this.failed && this.$refs.embed && ! this.$refs.embed.querySelector('iframe')) {
                            this.show();
                        }
                    }, 1000);
                    this.show();
                },
                release() {
                    try { if (window.snap && typeof window.snap.hide === 'function') { window.snap.hide(); } } catch (e) {}
                },
                destroy() { clearInterval(this.timer); this.release(); },
            }"
             style="background:#FFFFFF; border:2px solid #662721; border-radius:20px; width:100%; max-width:440px; max-height:94vh; overflow-y:auto; padding:1rem; box-shadow:0 25px 50px -12px rgba(0,0,0,0.35);">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:0.75rem;">
                <div style="min-width:0;">
                    <div style="font-size:0.6875rem; font-weight:900; color:#662721; text-transform:uppercase; letter-spacing:0.06em;">Bayar Otomatis &bull; {{ $pendingQris['method'] === 'QRIS' ? 'QRIS' : $pendingQris['method_label'] }}</div>
                    <div style="font-size:0.75rem; color:#7A5A52; margin-top:0.15rem;">{{ $pendingQris['order_number'] }}</div>
                </div>
                <div style="text-align:right; flex-shrink:0;">
                    <div style="font-size:1.25rem; font-weight:900; color:#4F2F2A; line-height:1.15;">Rp {{ number_format((float) $pendingQris['amount'], 0, ',', '.') }}</div>
                    <div style="font-size:0.75rem; color:#662721;">Berlaku <strong x-text="left" style="font-variant-numeric:tabular-nums;"></strong></div>
                </div>
            </div>

            {{-- Satu-satunya cara menutup kartu tanpa lunas: Batalkan + konfirmasi (dua langkah, bukan dialog browser). --}}
            <div x-show="confirming" x-cloak style="margin-top:0.6rem; padding:0.75rem; border-radius:12px; background:#FEF2F2; border:1px solid #FCA5A5; font-size:0.8125rem; color:#991B1B; text-align:center;">
                Batalkan pembayaran ini? Pesanan dibatalkan. Kalau customer ternyata sudah membayar, transaksi tetap dilanjutkan.
                <div style="display:flex; gap:0.5rem; margin-top:0.6rem;">
                    <button type="button" x-on:click="confirming = false"
                            style="flex:1; padding:0.6rem; border-radius:10px; font-weight:800; font-size:0.8125rem; cursor:pointer; border:1.5px solid #E6DAC0; background:#FFFFFF; color:#662721;">Kembali</button>
                    <button type="button" wire:click="{{ $cancelAction }}" wire:loading.attr="disabled"
                            style="flex:1; padding:0.6rem; border-radius:10px; font-weight:800; font-size:0.8125rem; cursor:pointer; border:none; background:#B91C1C; color:#FFFFFF;">Ya, batalkan</button>
                </div>
            </div>

            <div x-show="! confirming" style="display:flex; gap:0.6rem; margin-top:0.6rem;">
                <button type="button" x-on:click="confirming = true"
                        style="flex:1; padding:0.7rem; border-radius:12px; font-weight:800; font-size:0.8125rem; cursor:pointer; border:1.5px solid #FCA5A5; background:#FFFFFF; color:#B91C1C;">
                    Batalkan Pembayaran
                </button>
                @if(($pendingQris['is_mock'] ?? false) && ! app()->environment('production'))
                    <button type="button" wire:click="{{ $simulateAction }}"
                            style="flex:1; padding:0.7rem; border-radius:12px; font-weight:800; font-size:0.8125rem; cursor:pointer; border:1.5px dashed #16A34A; background:#F0FDF4; color:#15803D;">
                        Simulasi Lunas (lokal)
                    </button>
                @endif
            </div>

            {{-- Tampilan pembayaran (QR / VA) tertanam di sini. wire:ignore: tidak disentuh render ulang tiap 3 detik. --}}
            <div wire:ignore style="margin-top:0.75rem;">
                <div style="position:relative;">
                    <div id="pos-autopay-embed-{{ $pendingQris['payment_id'] }}" x-ref="embed" style="width:100%; height:{{ ($pendingQris['is_mock'] ?? false) ? '0' : '640px' }}; overflow:hidden;"></div>
                    {{-- Menutup tanda × bawaan tampilan pembayaran: kartu ini hanya ditutup lewat Batalkan Pembayaran. --}}
                    @unless($pendingQris['is_mock'] ?? false)
                        <div aria-hidden="true" style="position:absolute; top:0; right:0; width:72px; height:76px; z-index:2; cursor:default;"></div>
                    @endunless
                </div>
            </div>

            @if($pendingQris['is_mock'] ?? false)
                <div style="margin-top:0.25rem; padding:1.5rem 1rem; border-radius:12px; border:1.5px dashed #E6DAC0; background:#FCF8EE; text-align:center; font-size:0.8125rem; color:#662721;">
                    Mode lokal (tanpa kunci pembayaran): QR tidak dibuat. Pakai <strong>Simulasi Lunas</strong> untuk menguji alur struk.
                </div>
            @endif

            <div x-show="failed" x-cloak style="margin-top:0.5rem; font-size:0.75rem; color:#B91C1C; text-align:center;">
                Tampilan pembayaran tidak bisa dimuat.
                <button type="button" x-on:click="show()" style="background:none; border:none; padding:0; color:#B91C1C; font-weight:800; text-decoration:underline; cursor:pointer;">Muat ulang</button>
                @if(! empty($pendingQris['redirect_url']))
                    &bull; <a href="{{ $pendingQris['redirect_url'] }}" target="_blank" rel="noopener" style="color:#B91C1C; text-decoration:underline;">buka halaman pembayaran</a>
                @endif
            </div>

            <div style="margin-top:0.5rem; text-align:center; font-size:0.75rem; color:#662721;">
                <span style="display:inline-block; width:8px; height:8px; border-radius:999px; background:#16A34A; margin-right:0.3rem;"></span>Menunggu pembayaran customer — struk keluar otomatis setelah lunas.
            </div>

            @if($pendingQris['sandbox'] ?? false)
                <div style="margin-top:0.5rem; padding:0.45rem 0.7rem; border-radius:10px; background:#FFFBEB; border:1px solid #FCD34D; font-size:0.6875rem; line-height:1.45; color:#92400E;">
                    Mode uji coba: QR / VA hanya bisa dibayar lewat
                    <a href="https://simulator.sandbox.midtrans.com/" target="_blank" rel="noopener" style="color:#92400E; text-decoration:underline;">simulator pembayaran</a>, bukan aplikasi bank / e-wallet asli.
                </div>
            @endif

        </div>
    </div>
@endif
