<div class="c61">
    @include('filament.partials.c61-admin-style')
    <style>
        .imp-steps { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.75rem; }
        @media (max-width: 900px) { .imp-steps { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 520px) { .imp-steps { grid-template-columns: 1fr; } }
        .imp-step { display: flex; gap: 0.7rem; align-items: flex-start; padding: 0.85rem 1rem; border: 1px solid var(--c-line); border-radius: 14px; background: #FFFFFF; min-width: 0; }
        .imp-step-n { flex-shrink: 0; width: 26px; height: 26px; border-radius: 999px; background: var(--c-terra); color: var(--c-cream); font-family: var(--font-mono); font-weight: 700; font-size: 0.75rem; display: inline-flex; align-items: center; justify-content: center; }
        .imp-step-t { font-size: 0.8125rem; font-weight: 800; color: var(--c-brown); }
        .imp-step-d { font-size: 0.75rem; color: var(--c-muted); line-height: 1.45; margin-top: 0.15rem; }
        .imp-drop { display: flex; flex-direction: column; gap: 0.6rem; padding: 1.25rem; border: 1.5px dashed var(--c-line); border-radius: 14px; background: var(--c-paper); }
        .imp-file { height: auto; padding: 0.45rem 0.6rem; background: #FFFFFF; }
        .imp-file::file-selector-button { margin-right: 0.75rem; height: 30px; padding: 0 0.85rem; border-radius: 8px; border: 1px solid var(--c-line); background: #FFFFFF; color: var(--c-terra); font-size: 0.75rem; font-weight: 700; cursor: pointer; }
        .imp-error { font-size: 0.75rem; font-weight: 600; color: #B42318; }
        .imp-list { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 0.2rem; }
        .imp-list li { font-size: 0.75rem; color: var(--c-brown); white-space: nowrap; }
        .imp-actions { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; }
        .imp-actions .c61-hint { margin-top: 0; }
        .imp-table-wide { min-width: 760px; }
        .imp-confirm { display: inline-flex; align-items: center; gap: 0.55rem; font-size: 0.8125rem; font-weight: 700; color: var(--c-brown); cursor: pointer; }
        .imp-confirm input { width: 16px; height: 16px; accent-color: var(--c-terra); }
    </style>

    <div class="c61-hero">
        <div>
            <div class="c61-eyebrow"><span class="dot"></span><span>Keuangan &bull; Data Soft Opening</span></div>
            <div class="c61-hero-title">Import Penjualan</div>
            <div class="c61-hero-sub">Masukkan transaksi yang terjadi di luar sistem — booking padel walk-in (jadwal, akun customer, pembayaran) dan penjualan F&amp;B — dari file Excel. Semua pembayaran dicatat QRIS dan langsung masuk Riwayat Transaksi sesuai tanggal bayarnya.</div>
        </div>
        <div class="c61-hero-actions">
            <button type="button" wire:click="downloadTemplate" wire:loading.attr="disabled" wire:target="downloadTemplate" class="c61-btn c61-btn-cream c61-btn-lg">Unduh Template Excel</button>
        </div>
    </div>

    <div class="imp-steps">
        <div class="imp-step"><span class="imp-step-n">1</span><div><div class="imp-step-t">Unduh template</div><div class="imp-step-d">Sheet Petunjuk berisi aturan & contoh. Sheet Lapangan & Menu berisi nama yang dikenali sistem (referensi, tidak perlu diisi).</div></div></div>
        <div class="imp-step"><span class="imp-step-n">2</span><div><div class="imp-step-t">Isi data</div><div class="imp-step-d">No. Transaksi boleh kosong (dibuat otomatis). Booking 3 jam cukup 1 baris: Jam Mulai + Durasi 3.</div></div></div>
        <div class="imp-step"><span class="imp-step-n">3</span><div><div class="imp-step-t">Upload &amp; periksa</div><div class="imp-step-d">Sistem mengecek semua baris dulu: jadwal bentrok, nama lapangan / menu, dan total pembayaran.</div></div></div>
        <div class="imp-step"><span class="imp-step-n">4</span><div><div class="imp-step-t">Import</div><div class="imp-step-d">Hanya bisa kalau tidak ada error. Semua masuk sekaligus, atau tidak sama sekali.</div></div></div>
    </div>

    @if($result)
        <div class="c61-note c61-note-ok">
            <strong>Import berhasil ({{ $result['batch'] }}).</strong>
            {{ $result['padel_transactions'] }} transaksi padel ({{ $result['padel_bookings'] }} booking, Rp {{ number_format($result['padel_total'], 0, ',', '.') }}) dan
            {{ $result['fnb_transactions'] }} transaksi F&amp;B (Rp {{ number_format($result['fnb_total'], 0, ',', '.') }}) sudah masuk.
            @if($result['skipped'] > 0) {{ $result['skipped'] }} transaksi dilewati karena sudah pernah di-import. @endif
            <a href="{{ \App\Filament\Pages\BukuTransaksi::getUrl() }}" class="c61-link">Buka Riwayat Transaksi</a>
        </div>
    @endif

    <div class="c61-card">
        <div class="c61-card-head">
            <div>
                <div class="c61-card-title">Upload File Excel</div>
                <div class="c61-card-sub">Format .xlsx dari template, maksimal 10 MB. File langsung diperiksa setelah dipilih.</div>
            </div>
            @if($preview || $file)
                <button type="button" wire:click="resetImport" class="c61-btn c61-btn-ghost c61-btn-sm">Ganti File</button>
            @endif
        </div>
        <div class="c61-card-body">
            <div class="imp-drop">
                <label class="c61-label" for="import-file">File penjualan (.xlsx)</label>
                <input id="import-file" type="file" wire:model="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="c61-input imp-file">
                <div wire:loading wire:target="file" class="c61-hint">Mengunggah &amp; memeriksa file...</div>
                @error('file') <span class="imp-error">{{ $message }}</span> @enderror
            </div>
        </div>
    </div>

    @if($preview)
        @php $s = $preview['summary']; @endphp
        <div class="c61-kpis">
            <div class="c61-kpi">
                <div class="c61-kpi-label">Booking Padel</div>
                <div class="c61-kpi-value">{{ $s['padel_transactions'] }} <small>transaksi</small></div>
                <div class="c61-kpi-foot">{{ $s['padel_bookings'] }} booking &bull; {{ $s['new_customers'] }} akun customer baru</div>
            </div>
            <div class="c61-kpi">
                <div class="c61-kpi-label">Total Padel</div>
                <div class="c61-kpi-value">Rp {{ number_format($s['padel_total'], 0, ',', '.') }}</div>
                <div class="c61-kpi-foot">QRIS &bull; masuk Sewa Lapangan</div>
            </div>
            <div class="c61-kpi">
                <div class="c61-kpi-label">Penjualan F&amp;B</div>
                <div class="c61-kpi-value">{{ $s['fnb_transactions'] }} <small>transaksi</small></div>
                <div class="c61-kpi-foot">{{ $s['fnb_items'] }} item &bull; Rp {{ number_format($s['fnb_total'], 0, ',', '.') }}</div>
            </div>
            <div class="c61-kpi">
                <div class="c61-kpi-label">Hasil Pemeriksaan</div>
                <div class="c61-kpi-value" style="color: {{ $preview['error_count'] ? '#B42318' : '#047857' }};">{{ $preview['error_count'] }} <small>error</small></div>
                <div class="c61-kpi-foot">{{ $s['skipped'] }} transaksi dilewati (sudah pernah di-import)</div>
            </div>
        </div>

        @if($preview['error_count'] > 0)
            <div class="c61-card">
                <div class="c61-card-head">
                    <div>
                        <div class="c61-card-title">Perbaiki Dulu di Excel</div>
                        <div class="c61-card-sub">Import tidak bisa dijalankan selama masih ada error. Perbaiki baris di bawah, simpan, lalu upload ulang.</div>
                    </div>
                    <span class="c61-pill c61-pill-danger">{{ $preview['error_count'] }} error</span>
                </div>
                <div class="c61-table-wrap">
                    <table class="c61-table imp-table-wide">
                        <thead><tr><th>Sheet</th><th>No. Baris</th><th>No. Transaksi</th><th>Masalah</th></tr></thead>
                        <tbody>
                            @foreach($preview['errors'] as $i => $error)
                                <tr wire:key="imp-error-{{ $i }}">
                                    <td class="strong">{{ $error['sheet'] }}</td>
                                    {{-- No. = kolom nomor urut di template (baris Excel dikurangi baris judul). --}}
                                    <td class="code">
                                        @if($error['row'] === null) — @elseif($error['row'] === 1) Judul kolom @else No. {{ $error['row'] - 1 }} @endif
                                    </td>
                                    <td>{{ $error['ref'] ?? '—' }}</td>
                                    <td>{{ $error['message'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($preview['error_count'] > count($preview['errors']))
                    <div class="c61-card-foot c61-hint">Menampilkan {{ count($preview['errors']) }} dari {{ $preview['error_count'] }} error.</div>
                @endif
            </div>
        @endif

        @if($preview['warning_count'] > 0)
            <div class="c61-card">
                <div class="c61-card-head">
                    <div>
                        <div class="c61-card-title">Perlu Dicek Sebelum Import</div>
                        <div class="c61-card-sub">Bukan error, tapi biasanya tanda salah ketik No. HP. Akun customer dicari dari No. HP — kalau nomornya salah, booking masuk ke akun orang lain.</div>
                    </div>
                    <span class="c61-pill c61-pill-warn">{{ $preview['warning_count'] }} peringatan</span>
                </div>
                <div class="c61-table-wrap">
                    <table class="c61-table imp-table-wide">
                        <thead><tr><th>Sheet</th><th>No. Baris</th><th>No. Transaksi</th><th>Yang perlu dicek</th></tr></thead>
                        <tbody>
                            @foreach($preview['warnings'] as $i => $warning)
                                <tr wire:key="imp-warning-{{ $i }}">
                                    <td class="strong">{{ $warning['sheet'] }}</td>
                                    <td class="code">No. {{ $warning['row'] - 1 }}</td>
                                    <td>{{ $warning['ref'] ?? 'Otomatis' }}</td>
                                    <td>{{ $warning['message'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="c61-card-foot">
                    <label class="imp-confirm" for="imp-confirm-warnings">
                        <input id="imp-confirm-warnings" type="checkbox" wire:model.live="confirmWarnings">
                        <span>Sudah saya cek — No. HP-nya memang benar, lanjutkan import.</span>
                    </label>
                </div>
            </div>
        @endif

        @if(! empty($preview['skipped']))
            <div class="c61-note c61-note-warn">
                <strong>{{ count($preview['skipped']) }} transaksi dilewati</strong> karena No. Transaksi-nya sudah pernah di-import:
                {{ collect($preview['skipped'])->map(fn ($x) => $x['ref'] ?? ($x['sheet'].' No. '.($x['row'] - 1)))->implode(', ') }}.
            </div>
        @endif

        @if(! empty($preview['padel']))
            <div class="c61-card">
                <div class="c61-card-head">
                    <div>
                        <div class="c61-card-title">Pratinjau Booking Padel</div>
                        <div class="c61-card-sub">"Selesai" = jam main sudah lewat. "Aktif" = slot dikunci di jadwal & customer dapat QR tiket.</div>
                    </div>
                </div>
                <div class="c61-table-wrap">
                    <table class="c61-table imp-table-wide">
                        <thead><tr><th>No. Transaksi</th><th>Tanggal Bayar</th><th>Customer</th><th>Jadwal</th><th>Kasir</th><th style="text-align:right;">Total</th></tr></thead>
                        <tbody>
                            @foreach($preview['padel'] as $tx)
                                <tr wire:key="imp-padel-{{ $tx['row'] }}">
                                    <td class="code">{{ $tx['ref'] ?? 'Otomatis' }}</td>
                                    <td>{{ $tx['paid_at'] }}</td>
                                    <td>
                                        <div class="strong">{{ $tx['customer'] }}</div>
                                        <div class="sub">{{ $tx['phone'] }} &bull; {{ $tx['customer_exists'] ? 'akun lama' : 'akun baru' }}</div>
                                    </td>
                                    <td>
                                        <ul class="imp-list">
                                            @foreach($tx['bookings'] as $b)
                                                <li>{{ $b['label'] }} <span class="c61-pill {{ $b['status'] === 'PAID' ? 'c61-pill-ok' : 'c61-pill-gray' }}">{{ $b['status'] === 'PAID' ? 'Aktif' : 'Selesai' }}</span></li>
                                            @endforeach
                                        </ul>
                                    </td>
                                    <td>{{ $tx['cashier'] ?? 'Anda (admin import)' }}</td>
                                    <td class="num">Rp {{ number_format($tx['total'], 0, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if(! empty($preview['fnb']))
            <div class="c61-card">
                <div class="c61-card-head">
                    <div>
                        <div class="c61-card-title">Pratinjau Penjualan F&amp;B</div>
                        <div class="c61-card-sub">Tidak mengirim slip ke dapur — hanya dicatat sebagai penjualan.</div>
                    </div>
                </div>
                <div class="c61-table-wrap">
                    <table class="c61-table imp-table-wide">
                        <thead><tr><th>No. Transaksi</th><th>Tanggal Bayar</th><th>Pesanan</th><th>Item</th><th>Kasir</th><th style="text-align:right;">Total</th></tr></thead>
                        <tbody>
                            @foreach($preview['fnb'] as $tx)
                                <tr wire:key="imp-fnb-{{ $tx['row'] }}">
                                    <td class="code">{{ $tx['ref'] ?? 'Otomatis' }}</td>
                                    <td>{{ $tx['paid_at'] }}</td>
                                    <td>
                                        <div class="strong">{{ $tx['type'] }}</div>
                                        @if($tx['customer']) <div class="sub">{{ $tx['customer'] }}</div> @endif
                                    </td>
                                    <td style="white-space: normal; max-width: 320px;">{{ $tx['items'] }}</td>
                                    <td>{{ $tx['cashier'] ?? 'Anda (admin import)' }}</td>
                                    <td class="num">Rp {{ number_format($tx['total'], 0, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @php
            $hasData = ($s['padel_transactions'] + $s['fnb_transactions']) > 0;
            $waitingCheck = $preview['warning_count'] > 0 && ! $confirmWarnings;
            $canImport = $preview['error_count'] === 0 && $hasData && ! $waitingCheck;
        @endphp
        <div class="c61-card">
            <div class="c61-card-body imp-actions">
                <span class="c61-hint">
                    @if($preview['error_count'] > 0)
                        Perbaiki {{ $preview['error_count'] }} error dulu, lalu upload ulang file-nya.
                    @elseif(! $hasData)
                        Tidak ada transaksi baru untuk di-import.
                    @elseif($waitingCheck)
                        Cek {{ $preview['warning_count'] }} peringatan di atas lalu centang "Sudah saya cek".
                    @else
                        Siap import {{ $s['padel_transactions'] + $s['fnb_transactions'] }} transaksi (Rp {{ number_format($s['padel_total'] + $s['fnb_total'], 0, ',', '.') }}).
                    @endif
                </span>
                <button type="button" @disabled(! $canImport)
                    x-on:click="$dispatch('club61-confirm', { title: 'Import {{ $s['padel_transactions'] + $s['fnb_transactions'] }} transaksi?', message: @js('Data langsung masuk ke jadwal, akun customer, dan Riwayat Transaksi sesuai tanggal bayarnya. Kalau ternyata salah, bisa dibatalkan dari Riwayat Import selama belum dipakai (check-in, reschedule, refund).'), confirmLabel: 'Ya, Import', onConfirm: () => $wire.import() })"
                    wire:loading.attr="disabled" wire:target="import"
                    class="c61-btn c61-btn-primary c61-btn-lg">
                    <span wire:loading.remove wire:target="import">Import Sekarang</span>
                    <span wire:loading wire:target="import">Mengimport...</span>
                </button>
            </div>
        </div>
    @endif

    <div class="c61-card">
        <div class="c61-card-head">
            <div>
                <div class="c61-card-title">Riwayat Import</div>
                <div class="c61-card-sub">File asli tiap import disimpan. Import yang salah bisa dibatalkan selama transaksinya belum dipakai (check-in, reschedule, refund) — semua data dari import itu dihapus dan slot jadwalnya kosong lagi.</div>
            </div>
        </div>
        <div class="c61-table-wrap">
            <table class="c61-table imp-table-wide">
                <thead><tr><th>Waktu Import</th><th>Kode</th><th>Oleh</th><th>Padel</th><th>F&amp;B</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                    @forelse($this->batches as $batch)
                        <tr wire:key="imp-batch-{{ $batch->id }}">
                            <td>{{ $batch->created_at?->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') }}</td>
                            <td>
                                <div class="code">{{ $batch->batch_code }}</div>
                                @if($batch->file_name) <div class="sub">{{ $batch->file_name }}</div> @endif
                            </td>
                            <td>{{ $batch->importedBy?->name ?? '-' }}</td>
                            <td>
                                <div class="strong">{{ $batch->padel_transactions }} trx · {{ $batch->padel_bookings }} booking</div>
                                <div class="sub">Rp {{ number_format((float) $batch->padel_total, 0, ',', '.') }}</div>
                            </td>
                            <td>
                                <div class="strong">{{ $batch->fnb_transactions }} trx</div>
                                <div class="sub">Rp {{ number_format((float) $batch->fnb_total, 0, ',', '.') }}</div>
                            </td>
                            <td>
                                @if($batch->status === \App\Models\Finance\SalesImportBatch::STATUS_CANCELLED)
                                    <span class="c61-pill c61-pill-gray">Dibatalkan</span>
                                    <div class="sub">{{ $batch->cancelledBy?->name ?? '-' }} · {{ $batch->cancelled_at?->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') }}</div>
                                @else
                                    <span class="c61-pill c61-pill-ok">Masuk</span>
                                @endif
                            </td>
                            <td>
                                <div class="c61-actions">
                                    @if($batch->file_path)
                                        <button type="button" wire:click="downloadBatchFile('{{ $batch->id }}')" class="c61-btn c61-btn-ghost c61-btn-sm">Unduh File</button>
                                    @endif
                                    @if($batch->status !== \App\Models\Finance\SalesImportBatch::STATUS_CANCELLED)
                                        <button type="button" class="c61-btn c61-btn-danger c61-btn-sm"
                                            x-on:click="$dispatch('club61-confirm', { title: @js('Batalkan import '.$batch->batch_code.'?'), message: @js($batch->padel_transactions.' transaksi padel ('.$batch->padel_bookings.' booking) dan '.$batch->fnb_transactions.' transaksi F&B dari import ini akan dihapus dari jadwal, akun customer, dan Riwayat Transaksi. Akun customer baru yang dibuat import ini ikut dihapus kalau belum dipakai.'), confirmLabel: 'Ya, Batalkan Import', tone: 'danger', onConfirm: () => $wire.cancelBatch(@js($batch->id)) })">
                                            Batalkan
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="c61-empty">Belum ada import.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
