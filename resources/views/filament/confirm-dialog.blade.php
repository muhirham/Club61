{{--
    Dialog konfirmasi Club61 — pengganti popup bawaan browser (wire:confirm / window.confirm) di seluruh panel admin.
    Memakai komponen modal & tombol Filament sendiri, jadi tampilannya SAMA PERSIS dengan modal konfirmasi bawaan
    Filament (mis. "Delete user" di Kelola Pengguna). Dipasang sekali lewat render hook panels::body.end.

    Pemakaian dari tombol mana pun di dalam komponen Livewire:

    <button type="button" x-on:click="$dispatch('club61-confirm', {
        title: 'Hapus Item?',
        message: 'Item akan dihapus permanen.',
        confirmLabel: 'Ya, Hapus',
        tone: 'danger',            // 'danger' (ikon tempat sampah merah) | 'default' (ikon tanda tanya emas)
        onConfirm: () => $wire.deleteItem('id'),
    })">Hapus</button>
--}}
<style>
    /* Konfirmasi selalu paling depan — juga saat dibuka dari modal custom .c61-modal-backdrop (z-index 9999),
       mis. "Terima Semua" di detail Peminjaman Alat. Bawaan Filament hanya 40/50 → tertutup modalnya. */
    .club61-confirm-host .fi-modal > .fi-modal-close-overlay,
    .club61-confirm-host .fi-modal > .fi-modal-window-ctn { z-index: 10000; }
</style>
<div class="club61-confirm-host" x-data="{
        busy: false,
        title: '',
        message: '',
        confirmLabel: 'Ya, Lanjutkan',
        cancelLabel: 'Batal',
        modalId: 'club61-confirm-default',
        onConfirm: null,
        show(detail) {
            this.title = detail.title || 'Konfirmasi';
            this.message = detail.message || '';
            this.confirmLabel = detail.confirmLabel || 'Ya, Lanjutkan';
            this.cancelLabel = detail.cancelLabel || 'Batal';
            this.modalId = detail.tone === 'danger' ? 'club61-confirm-danger' : 'club61-confirm-default';
            this.onConfirm = typeof detail.onConfirm === 'function' ? detail.onConfirm : null;
            this.busy = false;
            this.$dispatch('open-modal', { id: this.modalId });
        },
        cancelConfirm() {
            if (! this.busy) this.$dispatch('close-modal', { id: this.modalId });
        },
        async runConfirm() {
            if (! this.onConfirm || this.busy) return;
            this.busy = true;
            try { await this.onConfirm(); } finally {
                this.busy = false;
                this.$dispatch('close-modal', { id: this.modalId });
            }
        },
    }"
    x-on:club61-confirm.window="show($event.detail)">
    @foreach ([
        'danger' => ['icon' => 'heroicon-o-trash', 'color' => 'danger'],
        'default' => ['icon' => 'heroicon-o-question-mark-circle', 'color' => 'primary'],
    ] as $tone => $style)
        <x-filament::modal
            :id="'club61-confirm-'.$tone"
            :icon="$style['icon']"
            :icon-color="$style['color']"
            alignment="center"
            footer-actions-alignment="center"
            width="md"
        >
            <x-slot name="heading"><span x-text="title"></span></x-slot>
            <x-slot name="description"><span x-text="message"></span></x-slot>

            <x-slot name="footerActions">
                <x-filament::button color="gray" x-on:click="cancelConfirm()" x-bind:disabled="busy">
                    <span x-text="cancelLabel"></span>
                </x-filament::button>
                <x-filament::button :color="$style['color']" x-on:click="runConfirm()" x-bind:disabled="busy">
                    <span x-text="busy ? 'Memproses…' : confirmLabel"></span>
                </x-filament::button>
            </x-slot>
        </x-filament::modal>
    @endforeach
</div>
