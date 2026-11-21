<div>
    @php
        $rp = fn ($v) => \App\Filament\Pages\BukuTransaksi::rupiah($v);
        $canManage = auth()->user()?->can('manage_fnb_cashbook');
    @endphp

    <div class="c61 bk-root" style="gap: 1rem;">
        @include('filament.partials.c61-admin-style')
        <style>
            .bk-root .bk-filters { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
            .bk-root .bk-filters .c61-select, .bk-root .bk-filters .c61-input { width: auto; min-width: 150px; }
            .bk-amount { font-family: var(--font-mono); font-weight: 800; white-space: nowrap; }
            .bk-amount.is-out { color: #B42318; }
            .bk-voided td { opacity: 0.55; }
            .bk-voided .bk-amount { text-decoration: line-through; }
            .bk-error { color: #B42318; font-size: 0.6875rem; font-weight: 700; margin-top: 0.3rem; display: block; }
            .bk-grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 0.85rem; }
            .bk-cat-row { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.55rem 0; border-bottom: 1px solid var(--c-line-soft); font-size: 0.8125rem; }
            .bk-modal-wide { max-width: 720px !important; }
            /* Form di dalam modal ikut tata letak modal: isi bisa digulir, tombol bawah selalu terlihat. */
            .c61-modal > form { display: flex; flex-direction: column; flex: 1; min-height: 0; }
            .c61-modal-body > * { flex-shrink: 0; }
            .bk-kpis-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            @media (max-width: 900px) { .bk-kpis-3 { grid-template-columns: 1fr; } }
            @media (max-width: 640px) { .bk-grid-2 { grid-template-columns: 1fr; } }
        </style>

        <div class="c61-hero">
            <div>
                <div class="c61-eyebrow">F&amp;B · Keuangan</div>
                <div class="c61-hero-title">Pengeluaran F&amp;B</div>
                <div class="c61-hero-sub">Pencatatan biaya F&amp;B: belanja bahan, gas, listrik, gaji, perlengkapan, dan lainnya. Pembelian Persediaan tercatat otomatis dari Penerimaan Barang. Pemasukan F&amp;B berasal dari penjualan kasir F&amp;B (Riwayat Transaksi).</div>
            </div>
            @if ($canManage)
                <div class="c61-hero-actions">
                    <button type="button" wire:click="openEntry" class="c61-btn c61-btn-cream c61-btn-lg">+ Catat Pengeluaran</button>
                    <button type="button" wire:click="openCategories" class="c61-btn c61-btn-outline-cream c61-btn-lg">Kelola Kategori</button>
                </div>
            @endif
        </div>

        <div class="c61-kpis bk-kpis-3">
            <div class="c61-kpi">
                <div class="c61-kpi-label">Total Pengeluaran</div>
                <div class="c61-kpi-value" style="color: #B42318; font-size: 1.4rem;">{{ $rp($totals['out']) }}</div>
                <div class="c61-kpi-foot"><span>{{ $periodLabel }}</span></div>
            </div>
            <div class="c61-kpi">
                <div class="c61-kpi-label">Pembelian Persediaan &amp; Bahan</div>
                <div class="c61-kpi-value" style="font-size: 1.4rem;">{{ $rp($totals['stock']) }}</div>
                <div class="c61-kpi-foot"><span>Mengurangi Arus Kas; pada Laba telah diwakili HPP</span></div>
            </div>
            <div class="c61-kpi">
                <div class="c61-kpi-label">Biaya Operasional</div>
                <div class="c61-kpi-value" style="font-size: 1.4rem;">{{ $rp($totals['operational']) }}</div>
                <div class="c61-kpi-foot"><span>Gas, listrik, gaji, perlengkapan, dst.</span></div>
            </div>
        </div>

        <div class="c61-toolbar">
            <div class="bk-filters">
                <select wire:model.live="preset" class="c61-select">
                    @foreach (\App\Services\Finance\LedgerReport::PRESETS as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
                @if ($preset === 'kustom')
                    <input type="date" wire:model.live="from" class="c61-input">
                    <input type="date" wire:model.live="until" class="c61-input">
                @endif
                <select wire:model.live="categoryFilter" class="c61-select">
                    <option value="ALL">Semua Kategori</option>
                    @foreach ($allCategories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="statusFilter" class="c61-select">
                    <option value="ACTIVE">Catatan aktif</option>
                    <option value="VOIDED">Dibatalkan</option>
                    <option value="ALL">Semua status</option>
                </select>
            </div>
            @if (\App\Filament\Pages\BukuKasFnb::canExport())
                {{-- File mengikuti filter yang sedang aktif (periode, kategori, status). --}}
                <x-filament::dropdown placement="bottom-end" teleport>
                    <x-slot name="trigger">
                        <button type="button" class="c61-btn c61-btn-ghost">
                            Unduh Laporan
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2.5" d="m6 9 6 6 6-6"/></svg>
                        </button>
                    </x-slot>
                    <x-filament::dropdown.list>
                        <x-filament::dropdown.list.item icon="heroicon-o-table-cells" wire:click="exportExcel" x-on:click="close()">Excel (rincian + per kategori)</x-filament::dropdown.list.item>
                        <x-filament::dropdown.list.item icon="heroicon-o-document-text" wire:click="exportPdf" x-on:click="close()">PDF (siap cetak)</x-filament::dropdown.list.item>
                    </x-filament::dropdown.list>
                </x-filament::dropdown>
            @endif
        </div>

        <div class="c61-card">
            <div class="c61-table-wrap">
                <table class="c61-table">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Kategori</th>
                            <th>Keterangan</th>
                            <th style="text-align: right;">Nominal</th>
                            <th>Nota</th>
                            <th>Dicatat Oleh</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($entries as $entry)
                            @php $voided = $entry->voided_at !== null; @endphp
                            <tr wire:key="bk-{{ $entry->id }}" class="{{ $voided ? 'bk-voided' : '' }}">
                                <td style="white-space: nowrap;">{{ $entry->entry_date->translatedFormat('d M Y') }}</td>
                                <td>
                                    <div class="strong">{{ $entry->category?->name }}</div>
                                    @if ($entry->category?->is_stock_purchase)
                                        <div class="sub">Pembelian persediaan / bahan</div>
                                    @endif
                                </td>
                                <td style="max-width: 320px;">
                                    <div class="sub" style="white-space: normal; color: var(--c-brown);">{{ $entry->notes ?: '-' }}</div>
                                    @if ($voided)
                                        <div class="sub" style="white-space: normal; color: #B42318;">Dibatalkan {{ $entry->voided_at->timezone('Asia/Jakarta')->format('d/m H:i') }} oleh {{ $entry->voider?->name ?? '-' }}: {{ $entry->void_reason }}</div>
                                    @endif
                                </td>
                                <td class="num"><span class="bk-amount is-out">{{ $rp($entry->amount) }}</span></td>
                                <td>
                                    @if ($entry->attachment_path)
                                        <button type="button" wire:click="downloadAttachment('{{ $entry->id }}')" class="c61-btn c61-btn-ghost c61-btn-sm">Unduh</button>
                                    @else
                                        <span class="sub">-</span>
                                    @endif
                                </td>
                                <td>{{ $entry->creator?->name ?? '-' }}</td>
                                <td>
                                    @if (! $voided && $canManage)
                                        @if ($entry->receipt_id)
                                            <span class="c61-pill c61-pill-gray" title="Batalkan melalui Stok F&B → Penerimaan Barang agar stoknya ikut dikembalikan">Dari Penerimaan Barang</span>
                                        @else
                                            <button type="button" wire:click="openVoid('{{ $entry->id }}')" class="c61-btn c61-btn-ghost c61-btn-sm">Batalkan</button>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="c61-empty">Belum ada catatan di periode ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($entries->hasPages())
                <div class="c61-card-foot">
                    <span class="c61-hint" style="margin: 0;">Halaman {{ $entries->currentPage() }} dari {{ $entries->lastPage() }} · {{ $entries->total() }} catatan</span>
                    <div class="c61-pages">
                        <button type="button" wire:click="previousPage" @disabled($entries->onFirstPage()) class="c61-page">&larr;</button>
                        <button type="button" wire:click="nextPage" @disabled(! $entries->hasMorePages()) class="c61-page">&rarr;</button>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- ===== Modal Catat Pengeluaran ===== --}}
    @if ($modal === 'entry')
        <div class="c61-modal-backdrop" wire:click.self="closeModal">
            <div class="c61-modal">
                <div class="c61-modal-head">
                    <div>
                        <div class="c61-modal-eyebrow">Pengeluaran F&amp;B</div>
                        <div class="c61-modal-title">Catat Pengeluaran</div>
                    </div>
                    <button type="button" wire:click="closeModal" class="c61-modal-close">&times;</button>
                </div>
                <form wire:submit="saveEntry">
                    <div class="c61-modal-body">
                        <div class="bk-grid-2">
                            <div class="c61-field">
                                <label class="c61-label">Kategori <small>*</small></label>
                                <select wire:model="entryCategoryId" class="c61-select">
                                    @forelse ($entryCategoryOptions as $option)
                                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                                    @empty
                                        <option value="">Belum ada kategori aktif</option>
                                    @endforelse
                                </select>
                                @error('entryCategoryId') <span class="bk-error">{{ $message }}</span> @enderror
                            </div>
                            <div class="c61-field">
                                <label class="c61-label">Tanggal <small>*</small></label>
                                <input type="date" wire:model="entryDate" max="{{ now('Asia/Jakarta')->toDateString() }}" class="c61-input">
                                @error('entryDate') <span class="bk-error">{{ $message }}</span> @enderror
                            </div>
                        </div>
                        <div class="c61-field" style="margin: 0;">
                            <label class="c61-label">Nominal (Rp) <small>*</small></label>
                            <input type="number" min="1" wire:model="entryAmount" class="c61-input" placeholder="300000">
                            @error('entryAmount') <span class="bk-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="c61-field" style="margin: 0;">
                            <label class="c61-label">Keterangan</label>
                            <textarea wire:model="entryNotes" rows="2" maxlength="500" class="c61-textarea" placeholder="Contoh: isi ulang gas 2 tabung"></textarea>
                        </div>
                        <div class="c61-field" style="margin: 0;">
                            <label class="c61-label">Foto Nota</label>
                            <input type="file" wire:model="entryAttachment" accept="image/jpeg,image/png,image/webp" class="c61-input" style="padding-top: 0.45rem;">
                            <div class="c61-hint" wire:loading wire:target="entryAttachment">Mengunggah foto…</div>
                            <div class="c61-hint">Opsional. JPG / PNG / WEBP maks. 4 MB, disimpan privat dan hanya bisa diunduh dari halaman ini.</div>
                            @error('entryAttachment') <span class="bk-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="c61-note">Pembelian barang untuk stok menu dicatat melalui <b>Stok F&amp;B → Penerimaan Barang</b> (stok bertambah dan pengeluaran tercatat otomatis), bukan di halaman ini.</div>
                    </div>
                    <div class="c61-modal-foot">
                        <button type="button" wire:click="closeModal" class="c61-btn c61-btn-ghost">Batal</button>
                        <button type="submit" class="c61-btn c61-btn-primary" wire:loading.attr="disabled" wire:target="saveEntry,entryAttachment">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- ===== Modal Kelola Kategori ===== --}}
    @if ($modal === 'categories')
        <div class="c61-modal-backdrop" wire:click.self="closeModal">
            <div class="c61-modal bk-modal-wide">
                <div class="c61-modal-head">
                    <div>
                        <div class="c61-modal-eyebrow">Pengeluaran F&amp;B</div>
                        <div class="c61-modal-title">Kelola Kategori</div>
                    </div>
                    <button type="button" wire:click="closeModal" class="c61-modal-close">&times;</button>
                </div>
                <div class="c61-modal-body">
                    <form wire:submit="saveCategory" class="c61-note" style="display: flex; flex-direction: column; gap: 0.6rem;">
                        <div class="strong">{{ $editingCategoryId ? 'Ubah Kategori' : 'Tambah Kategori' }}</div>
                        <div>
                            <label class="c61-label">Nama Kategori <small>*</small></label>
                            <input type="text" wire:model="categoryName" maxlength="60" class="c61-input" placeholder="Contoh: Sewa Mesin Kopi">
                            @error('categoryName') <span class="bk-error">{{ $message }}</span> @enderror
                        </div>
                            <label style="display: flex; align-items: flex-start; gap: 0.5rem; font-size: 0.75rem;">
                                <input type="checkbox" wire:model="categoryIsStockPurchase" @disabled($categoryTypeLocked) style="margin-top: 0.15rem;">
                                <span><b>Pembelian persediaan / bahan</b> — dihitung pada Arus Kas, tetapi tidak dipotong kembali pada Laba karena biayanya telah diperhitungkan melalui HPP menu.</span>
                            </label>
                            @if ($categoryTypeLocked)
                                <span class="c61-hint">Jenis kategori tidak dapat diubah karena sudah memiliki catatan, supaya laporan periode lalu tidak berubah. Untuk jenis lain, buat kategori baru lalu nonaktifkan kategori ini.</span>
                            @endif
                        <div class="c61-row" style="justify-content: flex-end;">
                            @if ($editingCategoryId)
                                <button type="button" wire:click="resetCategoryForm" class="c61-btn c61-btn-ghost c61-btn-sm">Batal Ubah</button>
                            @endif
                            <button type="submit" class="c61-btn c61-btn-primary c61-btn-sm">{{ $editingCategoryId ? 'Simpan Perubahan' : 'Tambah Kategori' }}</button>
                        </div>
                    </form>

                    <div>
                        @foreach ($allCategories as $category)
                            <div class="bk-cat-row" wire:key="cat-{{ $category->id }}">
                                <div style="min-width: 0;">
                                    <div class="strong" style="{{ $category->is_active ? '' : 'opacity: 0.5;' }}">{{ $category->name }}</div>
                                    <div class="c61-hint" style="margin: 0;">
                                        {{ $category->is_stock_purchase ? 'Pembelian persediaan / bahan' : 'Biaya operasional' }}{{ $category->isSystem() ? ' · otomatis dari Penerimaan Barang' : '' }} · {{ $category->entries_count }} catatan
                                    </div>
                                </div>
                                <div class="c61-actions">
                                    <span class="c61-pill {{ $category->is_active ? 'c61-pill-ok' : 'c61-pill-gray' }}">{{ $category->is_active ? 'Aktif' : 'Nonaktif' }}</span>
                                    <button type="button" wire:click="editCategory('{{ $category->id }}')" class="c61-btn c61-btn-ghost c61-btn-sm">Ubah</button>
                                    @unless ($category->isSystem())
                                        <button type="button" wire:click="toggleCategory('{{ $category->id }}')" class="c61-btn c61-btn-ghost c61-btn-sm">{{ $category->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                                    @endunless
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="c61-modal-foot">
                    <button type="button" wire:click="closeModal" class="c61-btn c61-btn-ghost">Tutup</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ===== Modal Batalkan Catatan ===== --}}
    @if ($voidTarget)
        <div class="c61-modal-backdrop" wire:click.self="cancelVoid" style="z-index: 10000;">
            <div class="c61-modal is-danger">
                <div class="c61-modal-head">
                    <div>
                        <div class="c61-modal-eyebrow">Batalkan Catatan</div>
                        <div class="c61-modal-title">{{ $voidTarget->category?->name }} {{ $rp($voidTarget->amount) }}</div>
                    </div>
                    <button type="button" wire:click="cancelVoid" class="c61-modal-close">&times;</button>
                </div>
                <form wire:submit="confirmVoid">
                    <div class="c61-modal-body">
                        <div class="c61-note c61-note-warn">Catatan tanggal {{ $voidTarget->entry_date->translatedFormat('d M Y') }} tidak dihitung lagi di laporan, tetapi tetap terlihat dengan tanda batal.</div>
                        <div class="c61-field" style="margin: 0;">
                            <label class="c61-label">Alasan Pembatalan <small>*</small></label>
                            <textarea wire:model="voidReason" rows="2" maxlength="255" class="c61-textarea" placeholder="Contoh: nominal salah ketik, seharusnya Rp250.000"></textarea>
                            @error('voidReason') <span class="bk-error">{{ $message }}</span> @enderror
                        </div>
                    </div>
                    <div class="c61-modal-foot">
                        <button type="button" wire:click="cancelVoid" class="c61-btn c61-btn-ghost">Kembali</button>
                        <button type="submit" class="c61-btn c61-btn-danger" wire:loading.attr="disabled">Ya, Batalkan Catatan</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
