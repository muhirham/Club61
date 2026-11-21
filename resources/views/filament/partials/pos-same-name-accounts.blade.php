{{--
    Walk-In Baru / Coaching "Isi Baru": nama yang diketik sudah dipakai akun lain dengan nomor HP berbeda. Kasir memastikan
    dulu apakah customer yang sama (pakai akun itu) supaya tidak terbentuk akun ganda; kalau orangnya berbeda, lanjut saja.
    Param: $accounts = [['id' => …, 'name' => …, 'phone' => '0856***8968'], …] (BookOfflineCourt::getSameNameAccountsProperty).
--}}
@if (! empty($accounts))
    <div data-same-name-accounts style="margin-top:0.3rem; background:#FFF7E6; border:1px solid #F5D9A6; border-radius:8px; padding:0.45rem 0.55rem; font-size:0.6875rem; color:#92400E; line-height:1.4;">
        <div style="font-weight:800; margin-bottom:0.25rem;">Nama ini sudah terdaftar. Apakah customer yang sama?</div>
        @foreach ($accounts as $acc)
            <div wire:key="same-name-{{ $acc['id'] }}" style="display:flex; justify-content:space-between; align-items:center; gap:0.5rem; padding:0.2rem 0; border-top:1px dashed #F5D9A6;">
                <span style="min-width:0; overflow-wrap:anywhere;"><strong>{{ $acc['name'] }}</strong> · {{ $acc['phone'] }}</span>
                <button type="button" wire:click="useSameNameAccount('{{ $acc['id'] }}')" class="pos-touch"
                    style="flex-shrink:0; background:#662721; color:#F7F0DB; border:none; font-size:0.625rem; font-weight:800; padding:0.25rem 0.55rem; border-radius:6px; cursor:pointer;">
                    Pakai akun ini
                </button>
            </div>
        @endforeach
        <div style="font-size:0.5625rem; color:#B45309; margin-top:0.2rem;">Orang yang berbeda? Lanjutkan saja dengan nomor HP-nya sendiri.</div>
    </div>
@endif
