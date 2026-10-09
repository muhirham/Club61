<x-filament-panels::page>
    @php $organizations = $this->organizations; @endphp

    <div class="c61 sd-root" style="padding: 0; gap: 1rem;">
        @include('filament.partials.c61-admin-style')

        @if($organizations->isEmpty())
            <div class="c61-card">
                <div class="c61-empty">
                    <div style="font-family: var(--font-serif); font-size: 1.1875rem; font-weight: 600; color: var(--c-brown);">Belum ada akun sponsor / corporate.</div>
                    <div style="margin-top: 0.35rem;">Buat lewat menu Kelola Sponsor (beli paket corporate, atau "Berikan Membership Corporate" oleh super admin).</div>
                </div>
            </div>
        @else
            <div class="c61-toolbar">
                <div class="c61-row" style="gap: 0.75rem;">
                    <label for="sponsor-org-select" class="c61-kpi-label">Pilih Sponsor</label>
                    <select id="sponsor-org-select" wire:model.live="organizationId" class="c61-select" style="width: auto; min-width: 320px;">
                        @foreach($organizations as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <span class="c61-pill c61-pill-cream" style="white-space: normal; height: auto; padding: 0.35rem 0.75rem; line-height: 1.4;">Tampilan hanya-lihat — aksi rilis voucher & roster tetap dilakukan PIC dari portalnya sendiri.</span>
            </div>

            @if($organizationId)
                <iframe wire:key="sponsor-frame-{{ $organizationId }}"
                    src="{{ route('corporate.preview', ['organization' => $organizationId]) }}"
                    title="Dashboard Sponsor Team"
                    style="width:100%; height:calc(100vh - 300px); min-height:640px; border:1px solid var(--c-line); border-radius:18px; background:#F7F0DB;"></iframe>
            @endif
        @endif
    </div>
</x-filament-panels::page>
