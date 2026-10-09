<div class="c61">
    @include('filament.partials.c61-admin-style')
    <style>
        .kus-quota { display: grid; grid-template-columns: auto auto; justify-content: start; gap: 0.2rem 0.6rem; font-size: 0.75rem; align-items: baseline; }
        .kus-quota > span:nth-child(odd) { font-weight: 600; color: var(--c-muted); }
        .kus-quota > span:nth-child(even) { font-weight: 700; color: var(--c-brown); white-space: nowrap; }
        /* Tabel member: judul kolom boleh 2 baris supaya kolom Status & Aksi tidak terpotong. */
        .kus-members th { white-space: normal; line-height: 1.35; }
        .kus-members td, .kus-members th { padding-left: 0.75rem; padding-right: 0.75rem; }
        .kus-quota .ok { color: #047857; }
        .kus-two { display: grid; grid-template-columns: 1fr 1.3fr; gap: 1rem; }
        .kus-stats { display: flex; gap: 1.75rem; flex-wrap: wrap; }
        .kus-stats .k { font-size: 0.6875rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--c-muted); }
        .kus-stats .v { font-family: var(--font-serif); font-size: 1.375rem; font-weight: 600; color: var(--c-brown); margin-top: 0.1rem; }
        .kus-info { display: flex; flex-direction: column; gap: 0.45rem; font-size: 0.8125rem; color: var(--c-muted); }
        .kus-info strong { color: var(--c-brown); }
        .kus-box-title { font-size: 0.6875rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: var(--c-muted); padding-bottom: 0.6rem; margin-bottom: 0.75rem; border-bottom: 1px solid var(--c-line); }
        .kus-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #10B981; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2); }
        @media (max-width: 900px) { .kus-two { grid-template-columns: 1fr; } }
    </style>

    @if($this->selectedMembership)
        @php
            $m = $this->selectedMembership;
            $habit = $this->memberHabitAnalysis;
            $bookings = $this->memberBookings;
            $checkins = $this->memberCheckins;
            $roster = $this->corporateRoster;
            $padelBal = $m->balances->firstWhere('facility', 'PADEL');
            $gymBal = $m->balances->firstWhere('facility', 'GYM');
            $saunaBal = $m->balances->firstWhere('facility', 'SAUNA');
        @endphp

        <!-- ========================================================
             HALAMAN DETAIL MEMBER: HABIT, JADWAL & ROSTER (halaman penuh, bukan popup)
             ======================================================== -->
        <div class="c61-hero" style="align-items: flex-start;">
            <div>
                <button type="button" wire:click="closeDetail" class="c61-btn c61-btn-outline-cream c61-btn-sm">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
                    <span>Kembali ke Daftar Kustomer</span>
                </button>

                <div class="c61-row" style="margin-top: 1rem; gap: 0.4rem;">
                    <span class="c61-pill" style="background: rgba(247,240,219,0.12); border-color: rgba(247,240,219,0.3); color: var(--c-cream);">
                        {{ $m->owner_type === 'ORGANIZATIONAL' ? 'Akun Sponsor Corporate Pool' : 'Keanggotaan Individual' }}
                    </span>
                    <span class="c61-pill {{ $m->status === 'ACTIVE' ? 'c61-pill-ok' : 'c61-pill-danger' }}">
                        STATUS: {{ $m->status }}
                    </span>
                </div>

                <div class="c61-hero-title">{{ $m->user->name ?? 'Tamu Walk-In' }}</div>

                <div class="c61-hero-sub c61-row" style="gap: 0.35rem 0.75rem; max-width: none;">
                    <span class="c61-mono" style="font-weight: 700; color: var(--c-cream);">KARTU: {{ $m->membership_code }}</span>
                    <span>&bull;</span>
                    <span style="font-weight: 700; color: var(--c-cream);">Paket: {{ $m->plan->name ?? '-' }}</span>
                    <span>&bull;</span>
                    <span>
                        Masa Aktif: <strong style="color: var(--c-cream);">{{ $m->start_date ? $m->start_date->format('d M Y') : '-' }}</strong> s/d <strong style="color: var(--c-cream);">{{ $m->end_date ? $m->end_date->format('d M Y') : '-' }}</strong>
                        @if($m->end_date)
                            ({{ $m->end_date->isPast() ? 'Sudah Expired' : 'Sisa ' . (int) ceil(now()->diffInDays($m->end_date, false)) . ' hari lagi' }})
                        @endif
                    </span>
                </div>
            </div>
        </div>

        <!-- Profil + 3 saldo fasilitas -->
        <div class="c61-kpis">
            <div class="c61-kpi">
                <div class="c61-kpi-label">Profil Kontak Member</div>
                <div style="font-weight: 700; font-size: 0.9375rem; margin-top: 0.2rem; word-break: break-all;">{{ $m->user->email ?? '-' }}</div>
                <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $m->user->phone ?? '') }}" target="_blank" style="font-size: 0.8125rem; color: #047857; font-weight: 700; text-decoration: none;">
                    WA: {{ $m->user->phone ?? '-' }} &rarr;
                </a>
                <div class="c61-kpi-foot"><span>Daftar sejak: {{ $m->created_at->format('d M Y') }}</span></div>
            </div>

            <div class="c61-kpi">
                <div class="c61-kpi-label">Padel Court Quota</div>
                <div class="c61-kpi-value is-ok">
                    @if($padelBal && $padelBal->quota_type === 'HOURS')
                        {{ (float) $padelBal->remaining_quota }} <small>/ {{ (float) $padelBal->initial_quota }} Jam</small>
                    @elseif($padelBal && $padelBal->quota_type === 'NONE')
                        Diskon {{ $padelBal->discount_percent }}%
                    @else
                        -
                    @endif
                </div>
                <div class="c61-kpi-foot"><span>Prioritas Booking: H-{{ $padelBal->booking_priority_days ?? 0 }} hari</span></div>
            </div>

            <div class="c61-kpi">
                <div class="c61-kpi-label">Fitness &amp; Gym Quota</div>
                <div class="c61-kpi-value is-terra">
                    @if($gymBal && $gymBal->initial_quota)
                        {{ (float) $gymBal->remaining_quota }} <small>/ {{ (float) $gymBal->initial_quota }} Sesi</small>
                    @elseif($gymBal)
                        Unlimited <small>Akses</small>
                    @else
                        -
                    @endif
                </div>
                <div class="c61-kpi-foot"><span>Turnstile Gate Access Active</span></div>
            </div>

            <div class="c61-kpi">
                <div class="c61-kpi-label">Finnish Sauna</div>
                <div class="c61-kpi-value">
                    @if($saunaBal && $saunaBal->initial_quota)
                        {{ (float) $saunaBal->remaining_quota }} <small>/ {{ (float) $saunaBal->initial_quota }} Sesi</small>
                    @elseif($saunaBal)
                        Unlimited
                    @else
                        -
                    @endif
                </div>
                <div class="c61-kpi-foot"><span>Diskon Sesi Tambahan: {{ $saunaBal->discount_percent ?? 0 }}%</span></div>
            </div>
        </div>

        <!-- Tab detail -->
        <div>
            <div class="c61-seg">
                <button type="button" wire:click="selectDetailTab('habit_schedule')" class="c61-seg-btn {{ $detailTab === 'habit_schedule' ? 'is-active' : '' }}">
                    Habit &amp; Jadwal Main Padel / Check-in
                </button>

                @if($m->owner_type === 'ORGANIZATIONAL')
                    <button type="button" wire:click="selectDetailTab('corporate_roster')" class="c61-seg-btn {{ $detailTab === 'corporate_roster' ? 'is-active' : '' }}">
                        <span>Anggota Tim Sponsor &amp; Jadwal</span>
                        <span class="count">{{ count($roster) }} Karyawan</span>
                    </button>
                @endif

                <button type="button" wire:click="selectDetailTab('card_info')" class="c61-seg-btn {{ $detailTab === 'card_info' ? 'is-active' : '' }}">
                    Kartu VIP Digital &amp; Tagihan Order
                </button>

                <button type="button" wire:click="selectDetailTab('audit_logs')" class="c61-seg-btn {{ $detailTab === 'audit_logs' ? 'is-active' : '' }}">
                    Audit Log Mutasi Kuota
                </button>
            </div>
        </div>

        <!-- SUB-TAB 1: HABIT & JADWAL MAIN -->
        @if($detailTab === 'habit_schedule')
            <div class="c61-card">
                <div class="c61-card-head">
                    <div>
                        <span class="c61-pill c61-pill-terra">Analisis Kebiasaan &amp; Pola Bermain (Member Habit Tracker)</span>
                        <div class="c61-card-title" style="font-size: 1.375rem; margin-top: 0.5rem; color: {{ $habit['habit_color'] }};">
                            {{ $habit['habit_label'] }}
                        </div>
                    </div>

                    <div class="kus-stats">
                        <div>
                            <div class="k">Total Match Tanding</div>
                            <div class="v">{{ $habit['total_matches'] }} Sesi</div>
                        </div>
                        <div>
                            <div class="k">Kunjungan Gym / Sauna</div>
                            <div class="v">{{ $habit['total_checkins'] }} Check-in</div>
                        </div>
                        <div>
                            <div class="k">Terakhir Bermain</div>
                            <div class="v" style="font-size: 1.0625rem; color: var(--c-terra);">{{ $habit['last_played'] }}</div>
                        </div>
                    </div>
                </div>

                <div class="c61-card-body kus-two">
                    <div class="c61-note">
                        <div class="kus-box-title" style="border: none; padding: 0; margin-bottom: 0.4rem;">Preferensi Bermain</div>
                        <div style="font-weight: 700;">Lapangan Favorit: <span style="color: var(--c-terra);">{{ $habit['favorite_court'] }}</span></div>
                        <div style="font-weight: 700; margin-top: 0.2rem;">Waktu Favorit: <span style="color: var(--c-terra);">{{ $habit['favorite_time'] }}</span></div>
                    </div>

                    <div class="c61-note" style="background: #FFFFFF; border-style: dashed; border-color: #D8C6A4;">
                        <div class="kus-box-title" style="border: none; padding: 0; margin-bottom: 0.4rem; color: var(--c-terra);">Catatan &amp; Insight Aktivitas</div>
                        <p style="font-size: 0.8125rem; line-height: 1.55;">{{ $habit['recommendation'] }}</p>
                    </div>
                </div>
            </div>

            <!-- Jadwal reservasi padel -->
            <div class="c61-card">
                <div class="c61-card-head">
                    <div>
                        <div class="c61-card-title">Jadwal &amp; Riwayat Reservasi Lapangan Padel ({{ $bookings->count() }})</div>
                        <div class="c61-card-sub">Daftar seluruh sesi tanding yang pernah atau akan dimainkan oleh member ini di Club 61.</div>
                    </div>
                </div>

                <div class="c61-table-wrap">
                    <table class="c61-table">
                        <thead>
                            <tr>
                                <th>Kode &amp; Tanggal</th>
                                <th>Jam Bermain</th>
                                <th>Lapangan</th>
                                <th>Status Main</th>
                                <th>Penggunaan Kuota</th>
                                <th>Waktu Check-in Kasir</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($bookings as $b)
                                <tr>
                                    <td>
                                        <div class="strong">{{ $b->booking_date->format('d M Y') }}</div>
                                        <div class="sub c61-mono" style="color: var(--c-terra);">{{ $b->booking_code }}</div>
                                    </td>
                                    <td>
                                        <div class="strong">{{ $b->start_time->format('H:i') }} - {{ $b->end_time->format('H:i') }} WIB</div>
                                        <div class="sub">Durasi: {{ $b->start_time->diffInHours($b->end_time) }} Jam</div>
                                    </td>
                                    <td class="strong">{{ $b->court->name ?? 'Lapangan Padel' }}</td>
                                    <td>
                                        @if($b->status === 'CHECKED_IN')
                                            <span class="c61-pill c61-pill-ok">CHECKED IN</span>
                                        @elseif($b->status === 'CONFIRMED')
                                            <span class="c61-pill c61-pill-info">JADWAL MENDATANG</span>
                                        @elseif($b->status === 'COMPLETED')
                                            <span class="c61-pill c61-pill-terra">SELESAI MAIN</span>
                                        @else
                                            <span class="c61-pill c61-pill-danger">{{ $b->status }}</span>
                                        @endif
                                    </td>
                                    <td style="font-weight: 700;">
                                        @if($b->member_hours_consumed > 0)
                                            <span style="color: #047857;">-{{ (float)$b->member_hours_consumed }} Jam Kuota</span>
                                        @else
                                            <span style="color: var(--c-muted);">Tarif Normal / Diskon</span>
                                        @endif
                                    </td>
                                    <td style="color: var(--c-muted);">
                                        {{ $b->checked_in_at ? $b->checked_in_at->format('d M Y, H:i') . ' WIB' : 'Belum Check-in' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="c61-empty">Belum ada jadwal atau riwayat reservasi lapangan padel untuk member ini.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Check-in gym & sauna -->
            <div class="c61-card">
                <div class="c61-card-head">
                    <div class="c61-card-title">Riwayat Kunjungan Fasilitas Fisik (Gym &amp; Sauna Turnstile)</div>
                </div>

                <div class="c61-table-wrap">
                    <table class="c61-table">
                        <thead>
                            <tr>
                                <th>Waktu Check-in</th>
                                <th>Fasilitas</th>
                                <th>Status Gate Turnstile</th>
                                <th>Pintu / Pemroses</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($checkins as $c)
                                <tr>
                                    <td>
                                        <span class="strong">{{ $c->checkin_at->format('d M Y, H:i') }} WIB</span>
                                        <span class="sub" style="display: inline;">({{ $c->checkin_at->diffForHumans() }})</span>
                                    </td>
                                    <td class="strong">{{ app(\App\Services\Membership\MembershipFacilityService::class)->name($c->facility) }}</td>
                                    <td><span class="c61-pill c61-pill-ok">AKSES TERVERIFIKASI</span></td>
                                    <td style="color: var(--c-muted);">Auto Gate Turnstile Scanner</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="c61-empty">Belum ada catatan check-in fasilitas gym atau sauna.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <!-- SUB-TAB 2: ANGGOTA TIM SPONSOR (CORPORATE) -->
        @if($detailTab === 'corporate_roster' && $m->owner_type === 'ORGANIZATIONAL')
            <div class="c61-card">
                <div class="c61-card-head">
                    <div style="max-width: 720px;">
                        <span class="c61-pill c61-pill-terra">Corporate Sponsor Pool System</span>
                        <div class="c61-card-title" style="font-size: 1.375rem; margin-top: 0.5rem;">Daftar Anggota Karyawan &amp; Pemantauan Kuota Tim</div>
                        <div class="c61-card-sub">
                            Admin Sponsor (PIC HR) mendaftarkan personil untuk memanfaatkan kuota blok jam perusahaan. Sistem memantau anggota yang aktif bermain dan yang pasif/kosong.
                        </div>
                    </div>

                    <div class="kus-stats c61-note">
                        <div>
                            <div class="k">Total Kuota Pool</div>
                            <div class="v" style="color: var(--c-terra);">120.0 Jam</div>
                        </div>
                        <div>
                            <div class="k">Terpakai Tim</div>
                            <div class="v" style="color: #B42318;">12.0 Jam</div>
                        </div>
                        <div>
                            <div class="k">Sisa Kuota Bersama</div>
                            <div class="v" style="color: #047857;">108.0 Jam</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="c61-card">
                <div class="c61-card-head">
                    <div class="c61-card-title">Daftar Karyawan Terdaftar ({{ count($roster) }} Orang)</div>
                    <div class="c61-card-sub" style="margin: 0;">Status: 3 Aktif Bermain &bull; 3 Kosong / Pasif Gak Ada Kabar</div>
                </div>

                <div class="c61-table-wrap">
                    <table class="c61-table kus-members">
                        <thead>
                            <tr>
                                <th>Nama Karyawan</th>
                                <th>Kontak WhatsApp</th>
                                <th>Jatah &amp; Terpakai</th>
                                <th>Status Aktivitas</th>
                                <th>Jadwal Terakhir Main</th>
                                <th>Jadwal Selanjutnya</th>
                                <th>Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($roster as $r)
                                <tr style="{{ $r['status'] === 'IDLE' ? 'background: #FEF6F5;' : '' }}">
                                    <td>
                                        <div class="strong">{{ $r['name'] }}</div>
                                        <div class="sub">{{ $r['role'] }}</div>
                                    </td>
                                    <td>
                                        <div>{{ $r['email'] }}</div>
                                        <div class="sub" style="color: #047857; font-weight: 700;">{{ $r['phone'] }}</div>
                                    </td>
                                    <td>
                                        <div class="strong"><span style="color: #B42318;">{{ $r['used_hours'] }} Jam</span> / {{ $r['allocated_hours'] }} Jam</div>
                                        <div class="sub" style="color: #047857; font-weight: 700;">Sisa Jatah: {{ $r['remaining_hours'] }} Jam</div>
                                    </td>
                                    <td>
                                        @if($r['status'] === 'ACTIVE')
                                            <span class="c61-pill c61-pill-ok">AKTIF BERMAIN</span>
                                        @else
                                            <span class="c61-pill c61-pill-danger">KOSONG / PASIF</span>
                                        @endif
                                    </td>
                                    <td>{{ $r['last_played'] ?? 'Belum pernah main' }}</td>
                                    <td style="font-weight: 700; color: var(--c-terra);">{{ $r['next_schedule'] ?? 'Tidak ada jadwal' }}</td>
                                    <td style="font-size: 0.75rem; color: var(--c-muted); min-width: 200px;">{{ $r['notes'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="c61-card" style="border-left: 4px solid #10B981;">
                <div class="c61-card-body" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                    <div style="max-width: 720px;">
                        <div style="font-weight: 800; font-size: 0.9375rem;">Ada 3 Anggota Tim Belum Memakai Jatah Kuota</div>
                        <div class="c61-card-sub">
                            Kirim pesan pengingat ke Admin Sponsor / PIC HR agar jatah kuota dimanfaatkan oleh karyawan sebelum masa aktif 90 hari berakhir.
                        </div>
                    </div>

                    <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $m->user->phone ?? '081311223344') }}?text=Halo%20Admin%20Sponsor%20Club%2061%2C%20mengingatkan%20bahwa%20masih%20ada%20108%20Jam%20kuota%20Padel%20PT%20Sinar%20Harapan%20yang%20siap%20digunakan%20karyawan.%20Silakan%20reservasi%20lapangan%20sekarang."
                       target="_blank" class="c61-btn c61-btn-lg" style="background: #047857; border-color: #047857; color: #FFFFFF;">
                        <span>Blast Pengingat via WhatsApp &rarr;</span>
                    </a>
                </div>
            </div>
        @endif

        <!-- SUB-TAB 3: KARTU DIGITAL & INFO ORDER -->
        @if($detailTab === 'card_info')
            <div class="c61-card">
                <div class="c61-card-head">
                    <div class="c61-card-title">Informasi Detail Kartu &amp; Transaksi Pembelian</div>
                </div>

                <div class="c61-card-body kus-two" style="grid-template-columns: 1fr 1fr;">
                    <div class="c61-note" style="padding: 1.1rem 1.25rem;">
                        <div class="kus-box-title">Data Akun Member</div>
                        <div class="kus-info">
                            <div>Nama Lengkap: <strong>{{ $m->user->name ?? '-' }}</strong></div>
                            <div>Email: <strong>{{ $m->user->email ?? '-' }}</strong></div>
                            <div>WhatsApp: <strong>{{ $m->user->phone ?? '-' }}</strong></div>
                            <div>Tipe Akun: <strong>{{ $m->owner_type }}</strong></div>
                            <div>Didaftarkan Oleh: <strong>{{ $m->soldByAdmin->name ?? 'Online Self-Service' }}</strong></div>
                        </div>
                    </div>

                    <div class="c61-note" style="padding: 1.1rem 1.25rem;">
                        <div class="kus-box-title">Rincian Finansial &amp; Masa Berlaku</div>
                        <div class="kus-info">
                            <div>Harga Paket Snapshot: <strong>Rp {{ number_format($m->purchase_price_snapshot, 0, ',', '.') }}</strong></div>
                            <div>Masa Aktif: <strong>{{ $m->start_date ? $m->start_date->format('d M Y') : '-' }} s/d {{ $m->end_date ? $m->end_date->format('d M Y') : '-' }}</strong></div>
                            <div>Status Saat Ini: <strong style="color: #047857;">{{ $m->status }}</strong></div>
                            <div>QR Pass Barcode: <span class="c61-mono" style="font-size: 0.75rem; color: var(--c-brown);">{{ substr($m->qr_pass_hash ?? '-', 0, 24) }}...</span></div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <!-- SUB-TAB 4: AUDIT LOGS -->
        @if($detailTab === 'audit_logs')
            <div class="c61-card">
                <div class="c61-card-head">
                    <div class="c61-card-title">Riwayat Mutasi &amp; Pemakaian Kuota (Audit Log Ledger)</div>
                </div>

                <div class="c61-table-wrap">
                    <table class="c61-table">
                        <thead>
                            <tr>
                                <th>Waktu Mutasi</th>
                                <th>Fasilitas</th>
                                <th>Tipe Aksi</th>
                                <th>Besaran Perubahan</th>
                                <th>Keterangan &amp; Petugas</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php
                                $allLogs = collect();
                                foreach($m->balances as $bal) {
                                    $allLogs = $allLogs->concat($bal->usageLogs);
                                }
                                $allLogs = $allLogs->sortByDesc('created_at');
                            @endphp
                            @forelse($allLogs as $l)
                                <tr>
                                    <td style="color: var(--c-muted);">{{ $l->created_at->format('d/m/Y H:i') }} WIB</td>
                                    <td class="strong">{{ $l->balance->facility ?? '-' }}</td>
                                    <td>
                                        <span class="c61-pill {{ $l->change_type === 'DECREMENT' ? 'c61-pill-danger' : 'c61-pill-ok' }}">{{ $l->change_type }}</span>
                                    </td>
                                    <td class="c61-mono" style="font-weight: 800; color: {{ $l->quantity < 0 ? '#B42318' : '#047857' }};">
                                        {{ $l->quantity > 0 ? '+' : '' }}{{ (float) $l->quantity }}
                                    </td>
                                    <td>{{ $l->notes ?? '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="c61-empty">Belum ada riwayat mutasi kuota untuk member ini.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

    @else
        <!-- ========================================================
             DAFTAR MEMBER UTAMA & METRIK LIVE MONITORING
             ======================================================== -->
        <div class="c61-hero">
            <div>
                <div class="c61-eyebrow"><span class="dot"></span>Modul 05 &bull; Customer Directory &amp; Live Monitoring</div>
                <div class="c61-hero-title">Data Customer &amp; Live Monitoring Membership</div>
                <div class="c61-hero-sub">
                    Pantau seluruh pelanggan terdaftar, kepemilikan kartu keanggotaan aktif, dan stream mutasi kuota fasilitas secara real-time.
                </div>
            </div>

            <div class="c61-hero-actions">
                <a href="/admin/jual-membership" class="c61-btn c61-btn-cream c61-btn-lg">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" /></svg>
                    <span>POS Jual Membership</span>
                </a>
            </div>
        </div>

        <div class="c61-kpis">
            <div class="c61-kpi">
                <div class="c61-kpi-top">
                    <div class="c61-kpi-label">Member Aktif Terdaftar</div>
                    <span class="c61-kpi-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg></span>
                </div>
                <div class="c61-kpi-value">{{ $this->metrics['total_active'] }} <small>Member</small></div>
                <div class="c61-kpi-foot"><span><span class="ok">+{{ $this->metrics['new_this_month'] }} member baru</span> bulan ini</span></div>
            </div>
            <div class="c61-kpi">
                <div class="c61-kpi-top">
                    <div class="c61-kpi-label">Total Saldo Jam Padel Aktif</div>
                    <span class="c61-kpi-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></span>
                </div>
                <div class="c61-kpi-value is-terra">{{ number_format($this->metrics['total_padel_hours'], 1) }} <small>Jam</small></div>
                <div class="c61-kpi-foot"><span>Siap digunakan untuk reservasi lapangan</span></div>
            </div>
            <div class="c61-kpi">
                <div class="c61-kpi-top">
                    <div class="c61-kpi-label">Pilihan Paket Membership</div>
                    <span class="c61-kpi-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0"/></svg></span>
                </div>
                <div class="c61-kpi-value">4 <small>Tier</small></div>
                <div class="c61-kpi-foot"><span>Bronze, Silver, Gold, Corporate</span></div>
            </div>
            <div class="c61-kpi">
                <div class="c61-kpi-top">
                    <div class="c61-kpi-label">Aktivitas Pemakaian Hari Ini</div>
                    <span class="c61-kpi-icon is-ok"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg></span>
                </div>
                <div class="c61-kpi-value is-ok">{{ $this->metrics['today_usage_count'] }} <small>Log</small></div>
                <div class="c61-kpi-foot"><span>Padel, Gym turnstile &amp; Finnish sauna</span></div>
            </div>
        </div>

        <div>
            <div class="c61-seg">
                <button type="button" wire:click="selectTab('members')" class="c61-seg-btn {{ $activeTab === 'members' ? 'is-active' : '' }}">
                    Daftar Member &amp; Keanggotaan <span class="count">{{ $this->members->count() }}</span>
                </button>
                <button type="button" wire:click="selectTab('live_monitoring')" class="c61-seg-btn {{ $activeTab === 'live_monitoring' ? 'is-active' : '' }}">
                    <span class="kus-dot"></span>
                    <span>Live Monitoring Log Kuota Real-Time</span>
                </button>
            </div>
        </div>

        @if($activeTab === 'members')
            <!-- TAB 1: DAFTAR MEMBER -->
            <div class="c61-card">
                <div class="c61-card-head">
                    <div class="c61-row" style="gap: 0.6rem; flex: 1; min-width: 280px;">
                        <div class="c61-search" style="max-width: 420px; flex: 1;">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                            <input type="text" wire:model.live.debounce.300ms="search"
                                   placeholder="Cari nama pelanggan, email, nomor WhatsApp, kode kartu..." class="c61-input" />
                        </div>

                        <select wire:model.live="statusFilter" class="c61-select" style="width: auto;">
                            <option value="ALL">Semua Status</option>
                            <option value="ACTIVE">Hanya Status ACTIVE</option>
                            <option value="PENDING_PAYMENT">Hanya PENDING PAYMENT</option>
                            <option value="EXPIRED">Hanya EXPIRED</option>
                        </select>
                    </div>

                    <span class="c61-pill c61-pill-cream">Menampilkan {{ $this->members->count() }} Data Member</span>
                </div>

                <div class="c61-table-wrap">
                    <table class="c61-table kus-members">
                        <thead>
                            <tr>
                                <th>Pelanggan</th>
                                <th>Kartu Member</th>
                                <th>Paket Terpilih</th>
                                <th>Saluran Beli</th>
                                <th>Sisa Saldo Kuota (Padel / Gym / Sauna)</th>
                                <th>Masa Berlaku</th>
                                <th>Status</th>
                                <th style="text-align: right;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($this->members as $mbr)
                                @php
                                    $pBal = $mbr->balances->firstWhere('facility', 'PADEL');
                                    $gBal = $mbr->balances->firstWhere('facility', 'GYM');
                                    $sBal = $mbr->balances->firstWhere('facility', 'SAUNA');
                                @endphp
                                <tr>
                                    <td>
                                        <div class="strong">{{ $mbr->user->name ?? 'Tamu Walk-In' }}</div>
                                        <div class="sub">
                                            <span>{{ $mbr->user->email ?? '-' }}</span>
                                            <span>&bull;</span>
                                            <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $mbr->user->phone ?? '') }}" target="_blank" style="color: #047857; font-weight: 700; text-decoration: none;">
                                                {{ $mbr->user->phone ?? '-' }}
                                            </a>
                                        </div>
                                    </td>

                                    <td>
                                        <div class="c61-mono" style="font-weight: 700; color: var(--c-terra); font-size: 0.75rem; white-space: nowrap;">{{ $mbr->membership_code }}</div>
                                        <div class="sub">Daftar: {{ $mbr->created_at->format('d M Y') }}</div>
                                    </td>

                                    <td>
                                        <span class="c61-pill c61-pill-terra" style="white-space: normal; height: auto; padding: 0.25rem 0.6rem; line-height: 1.3;">{{ $mbr->plan->name ?? '-' }}</span>
                                        <div class="sub">{{ $mbr->owner_type === 'ORGANIZATIONAL' ? 'Corporate / Sponsor' : 'Individual' }}</div>
                                    </td>

                                    <td>
                                        @if($mbr->sold_by_admin_id)
                                            <span class="c61-pill c61-pill-cream">POS Frontdesk</span>
                                            <div class="sub">Kasir: {{ $mbr->soldByAdmin->name ?? 'Frontdesk' }}</div>
                                        @else
                                            <span class="c61-pill c61-pill-info">Online Midtrans</span>
                                            <div class="sub">Web Customer</div>
                                        @endif
                                    </td>

                                    <td>
                                        <div class="kus-quota">
                                            <span>Padel</span>
                                            <span>
                                                @if($pBal && $pBal->quota_type === 'HOURS')
                                                    <span class="ok">{{ (float) $pBal->remaining_quota }}</span> / {{ (float) $pBal->initial_quota }} Jam
                                                @elseif($pBal && $pBal->quota_type === 'NONE')
                                                    <span style="color: var(--c-terra);">Diskon {{ $pBal->discount_percent }}%</span>
                                                @else
                                                    -
                                                @endif
                                            </span>
                                            <span>Gym</span>
                                            <span>
                                                @if($gBal && $gBal->initial_quota)
                                                    <span class="ok">{{ (float) $gBal->remaining_quota }}</span> / {{ (float) $gBal->initial_quota }} Sesi
                                                @elseif($gBal)
                                                    <span class="ok">Unlimited</span>
                                                @else
                                                    -
                                                @endif
                                            </span>
                                            <span>Sauna</span>
                                            <span>
                                                @if($sBal && $sBal->initial_quota)
                                                    <span class="ok">{{ (float) $sBal->remaining_quota }}</span> / {{ (float) $sBal->initial_quota }} Sesi
                                                @elseif($sBal)
                                                    <span class="ok">Unlimited</span>
                                                @else
                                                    -
                                                @endif
                                            </span>
                                        </div>
                                    </td>

                                    <td>
                                        <div style="font-size: 0.75rem; font-weight: 700;">
                                            {{ $mbr->start_date ? $mbr->start_date->format('d M Y') : '-' }} s/d {{ $mbr->end_date ? $mbr->end_date->format('d M Y') : '-' }}
                                        </div>
                                        <div class="sub">
                                            @if($mbr->end_date)
                                                @if($mbr->end_date->isPast())
                                                    <span style="color: #B42318; font-weight: 700;">Lewat {{ $mbr->end_date->diffForHumans() }}</span>
                                                @else
                                                    <span style="color: #047857; font-weight: 700;">Sisa {{ (int) ceil(now()->diffInDays($mbr->end_date, false)) }} hari lagi</span>
                                                @endif
                                            @endif
                                        </div>
                                    </td>

                                    <td>
                                        @if($mbr->status === 'ACTIVE')
                                            <span class="c61-pill c61-pill-ok">ACTIVE</span>
                                        @elseif($mbr->status === 'PENDING_PAYMENT')
                                            <span class="c61-pill c61-pill-warn">PENDING BAYAR</span>
                                        @elseif($mbr->status === 'EXPIRED')
                                            <span class="c61-pill c61-pill-danger">EXPIRED</span>
                                        @else
                                            <span class="c61-pill c61-pill-gray">{{ $mbr->status }}</span>
                                        @endif
                                    </td>

                                    <td style="text-align: right;">
                                        <button type="button" wire:click="openDetail('{{ $mbr->id }}')" class="c61-btn c61-btn-ghost c61-btn-sm" style="white-space: normal; height: auto; min-height: 32px; padding-top: 0.35rem; padding-bottom: 0.35rem; text-align: center; line-height: 1.25;">
                                            Monitoring &amp; Track Record &rarr;
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="c61-empty">Tidak ada data member yang sesuai dengan pencarian atau filter status.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @else
            <!-- TAB 2: LIVE MONITORING LOG KUOTA -->
            <div class="c61-card">
                <div class="c61-card-head">
                    <div style="max-width: 820px;">
                        <div class="c61-card-title c61-row" style="gap: 0.6rem;">
                            <span class="kus-dot"></span>
                            <span>Stream Mutasi &amp; Pemakaian Kuota (Live Audit Feed)</span>
                        </div>
                        <div class="c61-card-sub">
                            Seluruh aktivitas pemotongan jam lapangan, check-in gym turnstile, sauna, top-up kuota, dan reversal pembatalan tercatat secara permanen tanpa dapat diubah.
                        </div>
                    </div>

                    <span class="c61-pill c61-pill-cream">50 Log Terakhir</span>
                </div>

                <div class="c61-table-wrap">
                    <table class="c61-table">
                        <thead>
                            <tr>
                                <th>Waktu Kejadian</th>
                                <th>Pelanggan &amp; Kartu</th>
                                <th>Fasilitas</th>
                                <th>Aksi / Tipe Mutasi</th>
                                <th>Perubahan Kuota</th>
                                <th>Sisa Kuota Terkini</th>
                                <th>Keterangan &amp; Petugas</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($this->liveLogs as $log)
                                @php
                                    $mLog = $log->balance->membership ?? null;
                                    $uLog = $mLog->user ?? null;
                                @endphp
                                <tr>
                                    <td>
                                        <div class="strong" style="font-size: 0.75rem;">{{ $log->created_at->format('d M Y, H:i') }} WIB</div>
                                        <div class="sub">{{ $log->created_at->diffForHumans() }}</div>
                                    </td>

                                    <td>
                                        <div class="strong">{{ $uLog->name ?? 'Tamu Walk-In' }}</div>
                                        <div class="sub c61-mono" style="color: var(--c-terra);">{{ $mLog->membership_code ?? '-' }}</div>
                                    </td>

                                    <td>
                                        <div class="strong" style="font-size: 0.75rem;">{{ $log->balance->facility ?? '-' }}</div>
                                        <div class="sub">{{ $log->balance->quota_type ?? '-' }}</div>
                                    </td>

                                    <td>
                                        @if($log->change_type === 'DECREMENT')
                                            <span class="c61-pill c61-pill-danger">DECREMENT (PAKAI)</span>
                                        @elseif($log->change_type === 'TOPUP')
                                            <span class="c61-pill c61-pill-ok">TOPUP (ISI)</span>
                                        @elseif($log->change_type === 'REVERSAL')
                                            <span class="c61-pill c61-pill-info">REVERSAL (REFUND)</span>
                                        @elseif(str_contains($log->change_type, 'ROLLOVER'))
                                            <span class="c61-pill c61-pill-terra">{{ $log->change_type }}</span>
                                        @else
                                            <span class="c61-pill c61-pill-cream">{{ $log->change_type }}</span>
                                        @endif
                                    </td>

                                    <td>
                                        <span class="c61-mono" style="font-weight: 800; color: {{ $log->quantity < 0 ? '#B42318' : '#047857' }};">
                                            {{ $log->quantity > 0 ? '+' : '' }}{{ (float) $log->quantity }}
                                        </span>
                                        <span class="sub" style="display: inline;">{{ $log->balance->quota_type === 'HOURS' ? 'Jam' : 'Sesi' }}</span>
                                    </td>

                                    <td>
                                        <span class="strong">{{ (float) ($log->balance->remaining_quota ?? 0) }}</span>
                                        <span class="sub" style="display: inline;">{{ $log->balance->quota_type === 'HOURS' ? 'Jam' : 'Sesi' }}</span>
                                    </td>

                                    <td style="font-size: 0.75rem;">
                                        <div style="font-weight: 600;">{{ $log->notes ?? '-' }}</div>
                                        <div class="sub">Operator: {{ $log->performer->name ?? 'Sistem Otomatis / Turnstile Gate' }}</div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="c61-empty">Belum ada log aktivitas pemakaian kuota yang tercatat.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endif
</div>
