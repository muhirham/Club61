{{-- Pembungkus invoice salinan admin di Buku Transaksi: isi struk + tombol cetak.
     Dicetak di printer thermal 58mm yang sama dengan struk kasir lewat club61PrintReceipt() (App\Support\ReceiptPaper).
     Dulu dibuka di jendela baru dan print() bisa terpanggil dua kali (onload + timeout). --}}
<div x-data="{
        print() {
            if (window.club61PrintReceipt) { window.club61PrintReceipt(this.$refs.doc); } else { window.print(); }
        }
    }" style="display:flex; flex-direction:column; align-items:center; gap:0.75rem;">
    <div x-ref="doc" style="width:100%; display:flex; justify-content:center;">
        @include($view, $data)
    </div>
    <button type="button" x-on:click="print()"
        style="padding:0.5rem 1.1rem; border-radius:10px; background:#662721; color:#F7F0DB; font-weight:800; font-size:0.8rem; border:1.5px solid #B8932A;">
        Cetak Salinan
    </button>
</div>
