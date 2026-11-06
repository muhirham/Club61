{{--
    Modul 33 (Cari Akun di kasir POS): akun terpilih belum punya nomor HP (mis. daftar web lama) → kasir mengisinya,
    supaya kunjungan berikutnya dikenali lewat nomor HP dan tidak dibuatkan akun walk-in kedua.
    Butuh properti Livewire: selectedCustomerId, selectedCustomerPhone, selectedCustomerNewPhone, posStep.
--}}
@if ($selectedCustomerId && blank($selectedCustomerPhone) && $posStep === 'selection')
    <div data-pos-add-phone style="margin-top:0.4rem; background:#FFF7E6; border:1px solid #F5D9A6; border-radius:8px; padding:0.45rem 0.55rem;">
        <div style="font-size:0.625rem; font-weight:800; color:#92400E; margin-bottom:0.3rem;">
            Akun ini belum punya nomor HP. Isi supaya lain kali dikenali di kasir.
        </div>
        <div style="display:flex; gap:0.35rem;">
            <input type="tel" wire:model="selectedCustomerNewPhone" placeholder="No. WhatsApp customer"
                class="pos-input" autocomplete="off" style="flex:1; min-width:0;">
            <button type="button" wire:click="saveSelectedCustomerPhone" wire:loading.attr="disabled" class="pos-touch"
                style="background:#662721; color:#F7F0DB; border:none; font-size:0.6875rem; font-weight:800; padding:0 0.7rem; border-radius:6px; cursor:pointer; white-space:nowrap;">
                Simpan HP
            </button>
        </div>
    </div>
@endif
