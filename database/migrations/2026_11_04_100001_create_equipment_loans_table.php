<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Peminjaman Alat: satu baris per alat Sewa (raket / handuk) yang diserahkan ke peminjam — dibuat saat stoknya dipotong
 * (check-in booking), ditutup saat diterima kembali beserta kondisinya; denda rusak / hilang diputuskan management lalu
 * dibayar di kasir. Hanya menambah tabel & kolom kosong: data lama tidak diubah. Alat sewa dari sebelum tabel ini ada
 * tetap dikembalikan lewat Kelola Pemesanan seperti sebelumnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Nominal denda per alat (Master Data). Kosong = tanpa denda.
        Schema::table('court_equipments', function (Blueprint $t) {
            if (! Schema::hasColumn('court_equipments', 'fine_minor')) {
                $t->decimal('fine_minor', 12, 2)->nullable()->after('stock_quantity');   // lecet / rusak ringan karena disengaja
            }
            if (! Schema::hasColumn('court_equipments', 'fine_major')) {
                $t->decimal('fine_major', 12, 2)->nullable()->after('fine_minor');       // patah / tidak bisa dipakai karena disengaja
            }
            if (! Schema::hasColumn('court_equipments', 'fine_lost')) {
                $t->decimal('fine_lost', 12, 2)->nullable()->after('fine_major');        // hilang / tidak dikembalikan
            }
        });

        if (Schema::hasTable('equipment_loans')) {
            return;
        }
        Schema::create('equipment_loans', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('equipment_id')->nullable()->constrained('court_equipments')->nullOnDelete();
            $t->string('equipment_name', 100);
            // Baris alat booking asalnya (satu peminjaman per baris) & bookingnya.
            $t->foreignUlid('booking_equipment_id')->nullable()->unique()->constrained('padel_booking_equipments')->nullOnDelete();
            $t->foreignUlid('booking_id')->nullable()->constrained('padel_bookings')->nullOnDelete();
            $t->foreignUlid('borrower_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('borrower_name', 100);
            $t->string('borrower_phone', 30)->nullable();
            $t->unsignedInteger('quantity');

            $t->foreignUlid('handed_out_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('handed_out_at');
            $t->dateTime('due_at')->index();

            $t->dateTime('returned_at')->nullable()->index();
            $t->foreignUlid('returned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedInteger('qty_good')->default(0);    // baik / lecet pemakaian wajar
            $t->unsignedInteger('qty_minor')->default(0);   // lecet ringan karena disengaja
            $t->unsignedInteger('qty_major')->default(0);   // patah / tidak bisa dipakai karena disengaja
            $t->unsignedInteger('qty_lost')->default(0);    // hilang
            $t->string('return_notes', 500)->nullable();

            $t->string('fine_status', 15)->default('NONE')->index(); // NONE, PENDING, APPROVED, WAIVED
            $t->decimal('fine_proposed', 12, 2)->default(0);         // dihitung dari daftar denda saat diterima
            $t->decimal('fine_amount', 12, 2)->default(0);           // yang ditagih setelah diputuskan
            $t->foreignUlid('fine_decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('fine_decided_at')->nullable();
            $t->string('fine_decision_note', 500)->nullable();
            $t->ulid('fine_order_id')->nullable()->index();          // tagihan denda di kasir

            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_loans');
        Schema::table('court_equipments', function (Blueprint $t) {
            foreach (['fine_minor', 'fine_major', 'fine_lost'] as $column) {
                if (Schema::hasColumn('court_equipments', $column)) {
                    $t->dropColumn($column);
                }
            }
        });
    }
};
