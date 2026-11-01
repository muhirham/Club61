<?php

namespace App\Services\Permission;

use Spatie\Permission\Models\Permission;

class Club61PermissionMatrix
{
    /**
     * Izin "pintu belakang" (override finansial, refund, eskalasi hak akses) — hanya untuk
     * super_admin (lewat Gate::before) dan tidak pernah masuk preset role lain. Role "admin"
     * yang mendapat salah satunya bisa memberi dirinya akses penuh (update_roles) atau
     * memindahkan uang tanpa jejak persetujuan owner (refund/tarif/pajak). Halaman Customer
     * (data pribadi & membership seluruh pelanggan) juga khusus super_admin.
     */
    public const BACKDOOR_PERMISSIONS = [
        'View:Kustomer',
        'grant_corporate_membership',
        'cancel_refund_padel',
        'reschedule_padel_booking',
        'View:PengaturanBiayaPajak',
        'manage_tax_and_fees',
        // Menentukan metode bayar online yang bisa dipilih customer (salah atur = customer gagal bayar).
        'View:MetodePembayaranOnline',
        'manage_online_payment_methods',
        // Lama tahan slot & batas bayar online (salah atur = slot ditahan terlalu lama / customer kehabisan waktu bayar).
        'manage_booking_time_limits',
        // Gateway untuk tagihan baru (Midtrans / DOKU): salah pilih = uang masuk ke akun gateway yang berbeda.
        'manage_payment_gateway',
        'manage_court_pricing',
        // Tarif setoran coaching & biaya coach tambahan (Modul 24 Opsi B) — harga, sama seperti tarif lapangan.
        'manage_coaching_rates',
        // Mengubah aturan akses (hari / jam / peak) kartu member yang sudah terjual sekaligus (Modul 28).
        'apply_membership_plan_rules',
        // Mengubah kuota & tanggal berakhir kartu member yang sudah terjual sekaligus (selisih kuota paket).
        'apply_membership_plan_quota',
        'View:RoleResource',
        'view_roles',
        'create_roles',
        'update_roles',
        'delete_roles',
        'delete_users',
        'delete_staff',
        // Log aktivitas berisi siapa melakukan refund, perubahan izin, dan data pelanggan.
        'View:LogAktivitas',
        'export_activity_logs',
        // Buku Transaksi (Modul 17): seluruh omzet, data customer & bukti bayar.
        // process_refund_queue TIDAK di sini (audit G11): admin / manager menyetujui refund, tapi tidak pernah refund
        // yang ia ajukan sendiri — hanya super_admin yang boleh mengajukan sekaligus menyetujui (RefundQueueService).
        'View:BukuTransaksi',
        'export_ledger',
        'view_ledger_invoice',
        // Daftar Voucher: saldo voucher customer = uang customer yang masih disimpan klub.
        'View:DaftarVoucher',
        // Import penjualan soft opening: menulis order, booking, akun customer & Buku Transaksi langsung tanpa kasir.
        'View:ImportPenjualan',
        'import_sales_data',
    ];

    /**
     * Preset izin bawaan per role — satu-satunya sumber untuk DatabaseSeeder, UserFactory,
     * dan role yang dibuat otomatis lewat User::$role. Setelah itu tetap bisa diubah dari
     * menu "Roles & Hak Akses".
     *
     * @return array<int, string>
     */
    public static function defaultRolePermissions(string $role): array
    {
        return match (strtolower($role)) {
            'super_admin' => self::getAllPermissionSlugs(),
            'admin' => array_values(array_diff(self::getAllPermissionSlugs(), self::BACKDOOR_PERMISSIONS)),
            'cashier' => [
                'access_pos_terminal',
                'pos_cash_payment',
                'pos_qris_payment',
                'settle_unpaid_booking',
                'apply_pos_voucher',
                'view_padel_bookings',
                'checkin_padel_ticket',
                'print_padel_invoice',
                'View:BookOfflineCourt',
                'process_walkin_booking',
                'process_fnb_order',
                'open_pos_shift',
                'close_pos_shift',
                'print_sales_recap',
                'View:KelolaPemesanan',
                'request_refund_padel',
            ],
            'receptionist' => [
                'View:BookOfflineCourt',
                'View:BookingSystem',
                'process_walkin_booking',
                'open_pos_shift',
                'close_pos_shift',
                'print_sales_recap',
                'pos_qris_payment',
                'apply_pos_voucher',
                'settle_unpaid_booking',
                'View:KelolaPemesanan',
                'view_padel_bookings',
                'checkin_padel_ticket',
                'print_padel_invoice',
                'request_refund_padel',
                'View:JadwalCoaching',
                'book_coaching',
                'settle_coaching_bill',
            ],
            'kitchen' => [
                'view_kitchen_kds',
                'update_kitchen_order_status',
                'view_fnb_menu',
            ],
            'customer' => [
                'cancel_padel_booking',
            ],
            default => [],
        };
    }

    /**
     * Definisi lengkap 13 kategori modul beserta sub-modul dan aksi izin.
     * Semua teks murni tanpa emoji maupun ikon.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getMatrix(): array
    {
        return [
            'padel_arena' => [
                'title' => '1. Padel Arena & Bookings',
                'submodules' => [
                    'booking_system' => [
                        'label' => 'Booking System (Kalender & Slot)',
                        'actions' => [
                            'View:BookingSystem' => 'Akses Halaman Kalender & Slot',
                            'View:BookOfflineCourt' => 'Akses Halaman Walk-In Booking',
                            'manage_court_slots' => 'Kelola Jadwal & Ketersediaan Slot',
                            'process_walkin_booking' => 'Proses Pemesanan & Pembayaran Walk-In',
                        ],
                    ],
                    'padel_bookings' => [
                        'label' => 'Kelola Pemesanan & Tiket',
                        'actions' => [
                            'View:KelolaPemesanan' => 'Akses Halaman Kelola Pemesanan',
                            'view_padel_bookings' => 'Lihat Daftar Pemesanan Lapangan',
                            'checkin_padel_ticket' => 'Check-in Tiket Pemesan Lapangan',
                            'reschedule_padel_booking' => 'Reschedule Jadwal Pemesanan',
                            'request_refund_padel' => 'Ajukan Pembatalan & Refund (masuk Antrian Refund)',
                            'cancel_refund_padel' => 'Batalkan & Refund Pemesanan (Admin / Staf)',
                            'cancel_padel_booking' => 'Batalkan Pesanan Lapangan (Tombol Batal)',
                            'print_padel_invoice' => 'Cetak Invoice & Bukti Pembayaran',
                        ],
                    ],
                    'recurring_bookings' => [
                        'label' => 'Jadwal Tetap Mingguan (Corporate / Member)',
                        'actions' => [
                            'manage_recurring_bookings' => 'Kelola Jadwal Tetap Mingguan (buat booking rutin dari kuota jam, atur batas reschedule)',
                        ],
                    ],
                ],
            ],

            'pos_cashier' => [
                'title' => '2. Point of Sale (POS) & Kasir',
                'submodules' => [
                    'pos_terminal' => [
                        'label' => 'Terminal Kasir Frontdesk',
                        'actions' => [
                            'access_pos_terminal' => 'Akses Layar Kasir Frontdesk (/pos)',
                            'pos_cash_payment' => 'Proses Pembayaran Tunai (Cash)',
                            'pos_qris_payment' => 'Generate & Konfirmasi QRIS Dinamis',
                            'settle_unpaid_booking' => 'Pelunasan Pesanan Pending di Kasir',
                            'apply_pos_voucher' => 'Terapkan Voucher Diskon & Promosi',
                            'open_pos_shift' => 'Buka Sesi Shift Kasir Baru',
                            'close_pos_shift' => 'Tutup Sesi Shift & Rekonsiliasi Kas',
                            // Angka sistem per metode bayar (yang di form Tutup Shift disembunyikan) — tiap kali dibuka tercatat di log.
                            'print_sales_recap' => 'Cetak Rekap Penjualan Harian (X-Report) per Metode Bayar',
                            // Admin / manager: shift yang selisih (toleransi Rp0) & koreksi metode bayar salah klik kasir.
                            'approve_shift_closing' => 'Review Shift Kasir: Setujui / Tolak Selisih & Koreksi Metode Bayar',
                            'View:JualMembership' => 'Akses Halaman POS Jual Membership',
                            'sell_membership' => 'Jual & Aktivasi Membership Kasir',
                            'process_fnb_order' => 'Proses Transaksi & Pembayaran Menu F&B di Kasir',
                        ],
                    ],
                ],
            ],

            'coaching' => [
                'title' => '2b. Coaching (Academy & Coach)',
                'submodules' => [
                    'coaching_sessions' => [
                        'label' => 'Booking & Jadwal Coaching',
                        'actions' => [
                            'View:JadwalCoaching' => 'Akses Halaman Jadwal Coaching',
                            'book_coaching' => 'Buat Booking Coaching di POS Walk-In (Tarif Setoran)',
                        ],
                    ],
                    'coaching_billing' => [
                        'label' => 'Tagihan Berkala Academy Coaching',
                        'actions' => [
                            'settle_coaching_bill' => 'Pelunasan Tagihan Coaching di POS Walk-In (QRIS / EDC / Transfer Bank)',
                            'View:TagihanCoaching' => 'Akses Halaman Tagihan Coaching & Unduh Invoice',
                        ],
                    ],
                    'coaching_data' => [
                        'label' => 'Data & Tarif Coaching',
                        'actions' => [
                            'manage_coaching' => 'Kelola Academy & Coach',
                            'manage_coaching_rates' => 'Kelola Tarif Setoran & Pengaturan Coaching',
                        ],
                    ],
                ],
            ],

            'fnb_kitchen' => [
                'title' => '3. F&B Cafe & Kitchen (KDS)',
                'submodules' => [
                    'kitchen_kds' => [
                        'label' => 'Kitchen Display System (KDS)',
                        'actions' => [
                            'view_kitchen_kds' => 'Akses Layar Monitor Dapur (/kitchen)',
                            'update_kitchen_order_status' => 'Update Status Pesanan Dapur (Proses/Siap)',
                        ],
                    ],
                    'fnb_menu' => [
                        'label' => 'Menu & Resep Dapur',
                        'actions' => [
                            'View:KelolaMenuFnb' => 'Akses Halaman Kelola Menu F&B',
                            'view_fnb_menu' => 'Lihat Daftar Menu Makanan & Minuman',
                            'manage_fnb_menu' => 'Kelola Menu, Varian & Harga Jual',
                            'manage_recipe_bom' => 'Kelola Formula Resep & Bill of Materials',
                            'manage_raw_materials' => 'Kelola Stok Bahan Baku & Inventaris Dapur',
                            // Modul 29: HPP = dasar perhitungan laba F&B.
                            'manage_fnb_hpp' => 'Kelola HPP, Lacak Stok & Stok Minimum Menu',
                        ],
                    ],
                    'fnb_stock' => [
                        'label' => 'Stok F&B (Mini Inventory)',
                        'actions' => [
                            'View:StokFnb' => 'Akses Halaman Stok F&B & Kartu Stok',
                            'restock_fnb_stock' => 'Catat Penerimaan Barang F&B (Input Manual & Impor Excel)',
                            'adjust_fnb_stock' => 'Penyesuaian Stok F&B & Batalkan Penerimaan Barang',
                            'opname_fnb_stock' => 'Simpan Hasil Stok Opname F&B',
                        ],
                    ],
                    'fnb_finance' => [
                        'label' => 'Pengeluaran & Laporan Keuangan F&B',
                        'actions' => [
                            'View:BukuKasFnb' => 'Akses Halaman Pengeluaran F&B',
                            'manage_fnb_cashbook' => 'Catat & Batalkan Pengeluaran F&B, Kelola Kategori',
                            'View:LaporanFnb' => 'Akses Halaman Laporan Keuangan F&B (Laba, Arus Kas, Dashboard)',
                        ],
                    ],
                ],
            ],

            'gym_fitness' => [
                'title' => '4. Gym & Fitness Center',
                'submodules' => [
                    'gym_passes' => [
                        'label' => 'Paket & Tiket Gym',
                        'actions' => [
                            'view_gym_passes' => 'Lihat Data Paket & Tiket Masuk Gym',
                            'create_gym_pass' => 'Buat Tiket Masuk / Member Baru Gym',
                            'manage_gym_packages' => 'Kelola Paket Langganan & Tarif Gym',
                        ],
                    ],
                    'gym_checkin' => [
                        'label' => 'Check-In Member Gym',
                        'actions' => [
                            'checkin_gym_member' => 'Check-in Akses Masuk Fasilitas Gym',
                        ],
                    ],
                ],
            ],

            'wellness_suite' => [
                'title' => '5. Wellness & Recovery Suite',
                'submodules' => [
                    'sauna_icebath' => [
                        'label' => 'Wellness',
                        'actions' => [
                            'view_wellness_slots' => 'Lihat Jadwal & Slot Sesi Wellness',
                            'book_wellness_session' => 'Reservasi Sesi Wellness',
                            'checkin_wellness' => 'Check-in Akses Ruang Recovery',
                        ],
                    ],
                    'wellness_maintenance' => [
                        'label' => 'Pemeliharaan Fasilitas',
                        'actions' => [
                            'View:KelolaClub' => 'Akses Halaman Fasilitas Club',
                            'manage_wellness_maintenance' => 'Kelola Pemeliharaan Ruang Wellness',
                        ],
                    ],
                ],
            ],

            'salon_treatment' => [
                'title' => '6. Salon & Hair Treatment',
                'submodules' => [
                    'salon_bookings' => [
                        'label' => 'Booking & Layanan Salon',
                        'actions' => [
                            'view_salon_bookings' => 'Lihat Jadwal Booking Layanan Salon',
                            'book_salon_service' => 'Reservasi Jadwal Hair Treatment & Stylist',
                            'checkin_salon' => 'Check-in Tamu & Konfirmasi Layanan Selesai',
                        ],
                    ],
                    'salon_services' => [
                        'label' => 'Daftar Tarif & Layanan',
                        'actions' => [
                            'manage_salon_services' => 'Kelola Master Layanan & Tarif Salon',
                        ],
                    ],
                ],
            ],

            'merchandise' => [
                'title' => '7. Merchandise & Pro Shop',
                'submodules' => [
                    'merch_catalog' => [
                        'label' => 'Produk & Stok Apparel',
                        'actions' => [
                            'view_merch_products' => 'Lihat Katalog Produk Merchandise',
                            'manage_merch_products' => 'Kelola Produk, Varian & Harga Retail',
                            'manage_merch_stock' => 'Kelola Mutasi Stok Masuk / Keluar',
                        ],
                    ],
                ],
            ],

            'staff_management' => [
                'title' => '8. Karyawan, Pelatih & Staf',
                'submodules' => [
                    'staff_records' => [
                        'label' => 'Manajemen Karyawan',
                        'actions' => [
                            'View:KelolaKaryawan' => 'Akses Halaman Kelola Karyawan',
                            'view_staff' => 'Lihat Daftar Staf, Pelatih & Resepsionis',
                            'create_staff' => 'Tambah Data Karyawan Baru',
                            'update_staff' => 'Ubah Data Karyawan & Jabatan',
                            'delete_staff' => 'Hapus / Nonaktifkan Data Karyawan',
                        ],
                    ],
                    'guides' => [
                        'label' => 'Panduan Karyawan',
                        'actions' => [
                            'manage_guides' => 'Kelola Panduan (unggah PDF, aktif / nonaktif & buat ulang link baca, lihat log akses)',
                        ],
                    ],
                ],
            ],

            'tournament_marketing' => [
                'title' => '9. Turnamen, Marketing & Voucher',
                'submodules' => [
                    'tournaments' => [
                        'label' => 'Turnamen & Pertandingan',
                        'actions' => [
                            'View:KelolaTurnamen' => 'Akses Halaman Kelola Turnamen',
                            'view_tournaments' => 'Lihat Data Turnamen & Pertandingan',
                            'manage_tournaments' => 'Kelola Bracket, Peserta & Skor Turnamen',
                        ],
                    ],
                    'marketing' => [
                        'label' => 'Marketing Promo & Kupon',
                        'actions' => [
                            'View:Marketing' => 'Akses Halaman Marketing',
                            'view_marketing' => 'Lihat Informasi Promo & Kampanye',
                            'manage_vouchers' => 'Buat / Ubah / Nonaktifkan Kode Promo (di Daftar Voucher — butuh akses Daftar Voucher)',
                        ],
                    ],
                ],
            ],

            'analytics_reports' => [
                'title' => '10. Analytics & Laporan Keuangan',
                'submodules' => [
                    'revenue_reports' => [
                        'label' => 'Laporan Omset & Revenue',
                        'actions' => [
                            'View:Analytics' => 'Akses Halaman Analytics Keuangan',
                            'view_financial_reports' => 'Lihat Rincian Laporan Omset Venue',
                            'export_reports' => 'Export Laporan Keuangan (Excel / PDF)',
                        ],
                    ],
                    'ledger_book' => [
                        'label' => 'Buku Transaksi & Antrian Refund',
                        'actions' => [
                            'View:BukuTransaksi' => 'Akses Halaman Buku Transaksi (semua uang masuk & refund)',
                            'export_ledger' => 'Export Buku Transaksi ke Excel / CSV',
                            'view_ledger_invoice' => 'Lihat Invoice / Struk Salinan Admin dari Buku Transaksi',
                            'process_refund_queue' => 'Proses / Tolak Refund yang Menunggu (Antrian Refund)',
                            'View:DaftarVoucher' => 'Akses Halaman Daftar Voucher (kode promo & voucher saldo customer)',
                            'View:ImportPenjualan' => 'Akses Halaman Import Penjualan (Excel)',
                            'import_sales_data' => 'Import Data Penjualan Booking Padel & F&B dari Excel',
                        ],
                    ],
                    'invoice_template' => [
                        'label' => 'Template Invoice (PDF Email Invoice Lunas)',
                        'actions' => [
                            'View:TemplateInvoice' => 'Akses Halaman Template Invoice & Unduh Contoh PDF',
                            'manage_invoice_template' => 'Ubah Logo, Identitas Usaha & Kalimat Template Invoice',
                        ],
                    ],
                ],
            ],

            'master_data' => [
                'title' => '11. Master Data & Hak Akses',
                'submodules' => [
                    'dashboard_access' => [
                        'label' => 'Dashboard & Portal Member',
                        'actions' => [
                            'View:Dashboard' => 'Akses Halaman Utama Dashboard Admin',
                            // Slug WAJIB "View:{NamaClassPage}" (HasPageShield) — class-nya tetap
                            // App\Filament\Pages\Kustomer, jadi yang diganti cukup label-nya saja.
                            'View:Kustomer' => 'Akses Halaman Customer & Member VIP',
                        ],
                    ],
                    'user_accounts' => [
                        'label' => 'Akun Pengguna (Users)',
                        'actions' => [
                            'view_users' => 'Lihat Daftar Seluruh Akun Pengguna',
                            'create_users' => 'Tambah Akun Pengguna Baru',
                            'update_users' => 'Ubah Akun, Data Diri & Reset Sandi',
                            'delete_users' => 'Hapus / Blokir Akun Pengguna',
                        ],
                    ],
                    'role_matrix' => [
                        'label' => 'Roles & Hak Akses (Matrix)',
                        'actions' => [
                            'View:RoleResource' => 'Akses Menu Role & Hak Akses',
                            'view_roles' => 'Lihat Daftar Peran & Home Route',
                            'create_roles' => 'Tambah Peran Pengguna Baru',
                            'update_roles' => 'Ubah Konfigurasi Matriks Izin Peran',
                            'delete_roles' => 'Hapus Peran Pengguna',
                        ],
                    ],
                    'activity_log' => [
                        'label' => 'Log Aktivitas & Jejak Audit',
                        'actions' => [
                            'View:LogAktivitas' => 'Akses Menu Log Aktivitas (Read-only)',
                            'export_activity_logs' => 'Export Log Aktivitas ke CSV',
                        ],
                    ],
                    'pricing_equipment' => [
                        'label' => 'Tarif Lapangan & Fasilitas',
                        'actions' => [
                            'View:MasterData' => 'Akses Halaman Master Data',
                            'View:PengaturanBiayaPajak' => 'Akses Halaman Pengaturan Biaya & Pajak',
                            'manage_court_pricing' => 'Kelola Tarif Sewa Lapangan Per Jam',
                            'manage_court_equipment' => 'Kelola Tarif Sewa Raket & Bola Padel',
                            'manage_tax_and_fees' => 'Kelola Pengaturan Biaya Layanan & Pajak',
                            'View:MetodePembayaranOnline' => 'Akses Halaman Metode Pembayaran Online',
                            'manage_online_payment_methods' => 'Kelola Metode Pembayaran Online (aktif, nama, urutan, batas nominal)',
                            'manage_booking_time_limits' => 'Atur Waktu Tahan Slot & Batas Waktu Bayar Online',
                            'manage_payment_gateway' => 'Pilih Gateway Pembayaran (Midtrans / DOKU) untuk Online & QR Kasir',
                        ],
                    ],
                    'membership_plans' => [
                        'label' => 'Paket Membership',
                        'actions' => [
                            'view_membership_plans' => 'Lihat Daftar Paket Membership',
                            'manage_membership_plans' => 'Kelola Paket, Harga & Benefit Membership',
                            'apply_membership_plan_rules' => 'Terapkan Aturan Akses Paket (Hari / Jam / Peak) ke Membership yang Sudah Aktif',
                            'apply_membership_plan_quota' => 'Terapkan Kuota & Masa Aktif Paket ke Kartu Member yang Sudah Terjual',
                            'manage_membership_facilities' => 'Kelola Master Fasilitas Membership (tambah fasilitas, nama & deskripsi benefit)',
                        ],
                    ],
                ],
            ],

            'sponsor_corporate' => [
                'title' => '12. Sponsor / Corporate Account',
                'submodules' => [
                    'sponsor_organizations' => [
                        'label' => 'Kelola Sponsor Korporat',
                        'actions' => [
                            'View:SponsorDashboard' => 'Akses Menu Dashboard Sponsor (Lihat Dashboard PIC, Read-only)',
                            'view_sponsor_organizations' => 'Lihat Daftar Akun Sponsor Corporate',
                            'manage_sponsor_organizations' => 'Kelola (Tambah/Ubah/Hapus) Akun Sponsor Corporate',
                            'grant_corporate_membership' => 'Berikan Paket Membership Corporate Langsung (Tanpa Pembelian)',
                        ],
                    ],
                    'sponsor_access_schedules' => [
                        'label' => 'Jadwal Akses Sponsor',
                        'actions' => [
                            'view_sponsor_access_schedules' => 'Lihat Jadwal Akses Lapangan Sponsor',
                            'manage_sponsor_access_schedules' => 'Kelola (Tambah/Ubah/Hapus) Jadwal Akses Sponsor',
                        ],
                    ],
                ],
            ],

            'website_content' => [
                'title' => '13. Konten Website (Company Profile)',
                'submodules' => [
                    'company_profile' => [
                        'label' => 'Konten Landing Page & Profil Perusahaan',
                        'actions' => [
                            'View:KelolaKontenWebsite' => 'Akses Halaman Konten Website',
                            'manage_company_profile_content' => 'Ubah & Simpan Konten Landing Page',
                        ],
                    ],
                    'link_bio' => [
                        'label' => 'Halaman Link Bio Instagram',
                        'actions' => [
                            'manage_link_bio' => 'Kelola Tombol Halaman Link Bio',
                        ],
                    ],
                ],
            ],

            'signature' => [
                'title' => '14. Digital Signage',
                'submodules' => [
                    'signature_screen' => [
                        'label' => 'Konten & Monitoring Layar TV',
                        'actions' => [
                            'View:Signature' => 'Akses Halaman Signature & Preview',
                            'manage_signature' => 'Ubah Template, Konten, Running Text & Sponsor (draft)',
                            'publish_signature' => 'Tayangkan ke TV, Kembalikan Versi & Buat Ulang Link Layar',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Pasang preset izin ke role yang BARU dibuat. Sengaja tidak dipanggil untuk role yang
     * sudah ada — kalau super_admin sengaja mengosongkan izin sebuah role dari menu Roles &
     * Hak Akses, izin itu tidak boleh "tumbuh kembali" sendiri saat ada user baru dibuat.
     */
    public static function applyDefaultPermissionsTo(\Spatie\Permission\Models\Role $role): void
    {
        $preset = self::defaultRolePermissions($role->name);
        if ($preset === []) {
            return;
        }

        self::syncAllPermissions($role->guard_name);
        $role->syncPermissions($preset);
    }

    /**
     * Dapatkan daftar seluruh slug permission unik dari matrix.
     *
     * @return array<int, string>
     */
    public static function getAllPermissionSlugs(): array
    {
        $matrix = self::getMatrix();
        $slugs = [];

        foreach ($matrix as $cat) {
            foreach ($cat['submodules'] as $sub) {
                foreach ($sub['actions'] as $slug => $label) {
                    $slugs[] = $slug;
                }
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * Sinkronisasikan seluruh permission dari matrix ke database Spatie.
     *
     * @param string $guardName
     * @return int Jumlah permission yang terdaftar
     */
    /**
     * Untuk deploy fitur baru: buat izin di matriks yang BELUM ada di database, lalu berikan HANYA izin baru itu ke
     * role yang preset-nya memuatnya (mis. super_admin). Izin yang sudah ada tidak disentuh sama sekali, jadi
     * centangan yang diubah manual lewat menu "Roles & Hak Akses" tetap aman.
     *
     * @return array<int, string> izin yang baru dibuat
     */
    public static function grantNewPermissionsToPresetRoles(string $guardName = 'web'): array
    {
        $existing = Permission::where('guard_name', $guardName)->pluck('name')->all();
        $new = array_values(array_diff(self::getAllPermissionSlugs(), $existing));

        if ($new === []) {
            return [];
        }

        foreach ($new as $slug) {
            Permission::create(['name' => $slug, 'guard_name' => $guardName]);
        }

        foreach (\App\Models\Role::where('guard_name', $guardName)->get() as $role) {
            $grant = array_values(array_intersect($new, self::defaultRolePermissions($role->name)));
            if ($grant !== []) {
                $role->givePermissionTo($grant);
            }
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $new;
    }

    public static function syncAllPermissions(string $guardName = 'web'): int
    {
        $slugs = self::getAllPermissionSlugs();

        foreach ($slugs as $slug) {
            Permission::firstOrCreate([
                'name' => $slug,
                'guard_name' => $guardName,
            ]);
        }

        // Reset cache permission Spatie
        app('cache')
            ->store(config('permission.cache.store') !== 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));

        return count($slugs);
    }
}
