{{--
    Cari Akun di kasir POS Walk-In (dipakai tab Cari Akun dan tab Coaching): akun terpilih, atau kotak pencarian + hasil.
    Butuh properti Livewire: selectedCustomerId, selectedCustomerName, selectedCustomerPhone, customerSearch, searchResults, posStep.
    Param: $benefitQuote (null = badge benefit tidak ditampilkan, mis. coaching), $notFoundHint (teks saat tidak ketemu).
--}}
@if ($selectedCustomerId)
    <div
        style="background:#FFFFFF; border:1.5px solid #662721; border-radius:8px; padding:0.5rem 0.65rem; display:flex; justify-content:space-between; align-items:center;">
        <div>
            <div style="font-weight:900; color:#4F2F2A; font-size:0.8125rem;">
                {{ $selectedCustomerName }}</div>
            <div style="font-size:0.6875rem; color:#662721;">
                {{ $selectedCustomerPhone ?? '-' }}</div>
            @if ($benefitQuote !== null)
                @include('filament.partials.walkin-benefit-badges', ['benefitQuote' => $benefitQuote])
            @endif
        </div>
        @if ($posStep === 'selection')
            <button type="button" wire:click="clearSelectedCustomer" class="pos-touch"
                style="background:#FEF2F2; border:1px solid #FECACA; color:#DC2626; font-size:0.625rem; font-weight:800; padding:0.2rem 0.45rem; border-radius:5px; cursor:pointer;">
                Ganti
            </button>
        @endif
    </div>
    @include('filament.partials.pos-add-customer-phone')
@else
    <div style="position:relative;">
        <input type="text" wire:model.live.debounce.300ms="customerSearch"
            placeholder="Cari nama, nomor HP, atau email..." class="pos-input" autocomplete="off"
            {{ $posStep !== 'selection' ? 'disabled' : '' }}>
        @if (count($searchResults) > 0)
            <div data-pos-customer-results
                style="position:absolute; top:100%; left:0; right:0; z-index:50; background:#FFFFFF; border:1.5px solid #E6DAC0; border-radius:8px; box-shadow:0 8px 20px rgba(0,0,0,0.1); margin-top:0.2rem; max-height:260px; overflow-y:auto;">
                @foreach ($searchResults as $res)
                    <button type="button" wire:key="search-result-{{ $res->id }}"
                        wire:click="selectCustomer('{{ $res->id }}')" class="pos-touch"
                        style="width:100%; text-align:left; padding:0.4rem 0.65rem; border:none; border-bottom:1px solid #EFE6D2; background:#FFFFFF; cursor:pointer; font-size:0.75rem;"
                        onmouseover="this.style.background='#FCF8EE'"
                        onmouseout="this.style.background='#FFFFFF'">
                        <div style="font-weight:800; color:#4F2F2A;">{{ $res->name }}
                        </div>
                        <div style="font-size:0.625rem; color:#662721;">
                            {{ $res->phone ?: 'Belum ada nomor HP' }}
                            @unless (\App\Support\PlaceholderEmail::is($res->email))
                                · {{ $res->email }}
                            @endunless
                        </div>
                    </button>
                @endforeach
            </div>
        @elseif (mb_strlen(trim($customerSearch)) >= 2)
            <div
                style="font-size:0.625rem; color:#9CA3AF; margin-top:0.25rem; font-style:italic;">
                {{ $notFoundHint }}</div>
        @endif
    </div>
@endif
