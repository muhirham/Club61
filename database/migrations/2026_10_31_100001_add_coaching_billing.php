<?php

use App\Services\Permission\Club61PermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul 31 — Tagihan Coaching (docs/PRD_MODUL_31_TAGIHAN_COACHING.md). Hanya menambah kolom / tabel:
 *  - coaching_academies: cara bayar (PER_BOOKING = bayar langsung seperti sebelumnya / TAB = kumpul tagihan), periode,
 *    tanggal mulai siklus 2 mingguan, sakelar blok booking bila tagihan lewat jatuh tempo.
 *  - coaching_bookings: academy, cara bayar saat booking dibuat (snapshot), shift kasir & jatuh tempo, pelunasan.
 *  - coaching_settlements: satu pelunasan tagihan academy di kasir (banyak sesi sekaligus).
 * Data lama: academy & booking yang sudah ada tetap PER_BOOKING (perilaku tidak berubah).
 * Setiap langkah dicek dulu supaya `php artisan migrate` bisa diulang bila sempat berhenti di tengah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coaching_academies', function (Blueprint $t) {
            if (! Schema::hasColumn('coaching_academies', 'billing_mode')) {
                $t->string('billing_mode', 15)->default('PER_BOOKING')->after('phone'); // PER_BOOKING, TAB
            }
            if (! Schema::hasColumn('coaching_academies', 'billing_cycle')) {
                $t->string('billing_cycle', 10)->nullable()->after('billing_mode'); // SHIFT, DAILY, WEEKLY, BIWEEKLY
            }
            if (! Schema::hasColumn('coaching_academies', 'billing_anchor_date')) {
                $t->date('billing_anchor_date')->nullable()->after('billing_cycle'); // awal siklus 2 mingguan
            }
            if (! Schema::hasColumn('coaching_academies', 'block_when_overdue')) {
                $t->boolean('block_when_overdue')->default(false)->after('billing_anchor_date');
            }
        });

        if (! Schema::hasTable('coaching_settlements')) {
            Schema::create('coaching_settlements', function (Blueprint $t) {
                $t->ulid('id')->primary();
                $t->string('settlement_number', 30)->unique();
                $t->foreignUlid('academy_id')->nullable()->constrained('coaching_academies')->nullOnDelete();
                $t->string('academy_name', 100);
                $t->string('payment_method', 30);
                $t->decimal('total_amount', 14, 2);
                $t->unsignedInteger('session_count');
                $t->string('reference', 100)->nullable();   // RRN / approval code / no. referensi transfer
                $t->string('sender_name', 100)->nullable(); // nama pengirim transfer / QRIS
                $t->string('proof_path')->nullable();       // bukti transfer (disk privat)
                $t->foreignUlid('cashier_shift_id')->nullable()->constrained('pos_cashier_shifts')->nullOnDelete();
                $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();

                $t->index(['academy_id', 'created_at'], 'idx_coaching_settlement_academy');
            });
        }

        Schema::table('coaching_bookings', function (Blueprint $t) {
            if (! Schema::hasColumn('coaching_bookings', 'academy_id')) {
                $t->foreignUlid('academy_id')->nullable()->after('academy_name')->constrained('coaching_academies')->nullOnDelete();
            }
            if (! Schema::hasColumn('coaching_bookings', 'billing')) {
                $t->string('billing', 15)->default('PER_BOOKING')->after('academy_id'); // PER_BOOKING, TAB
            }
            if (! Schema::hasColumn('coaching_bookings', 'billing_cycle')) {
                $t->string('billing_cycle', 10)->nullable()->after('billing');
            }
            if (! Schema::hasColumn('coaching_bookings', 'cashier_shift_id')) {
                $t->foreignUlid('cashier_shift_id')->nullable()->after('billing_cycle')->constrained('pos_cashier_shifts')->nullOnDelete();
            }
            if (! Schema::hasColumn('coaching_bookings', 'due_at')) {
                $t->dateTime('due_at')->nullable()->after('cashier_shift_id');
            }
            if (! Schema::hasColumn('coaching_bookings', 'settlement_id')) {
                $t->foreignUlid('settlement_id')->nullable()->after('due_at')->constrained('coaching_settlements')->nullOnDelete();
            }
        });

        if (! Schema::hasIndex('coaching_bookings', 'idx_coaching_booking_tab')) {
            Schema::table('coaching_bookings', fn (Blueprint $t) => $t->index(['academy_id', 'billing', 'due_at'], 'idx_coaching_booking_tab'));
        }

        if (Schema::hasTable('permissions') && Schema::hasTable('roles')) {
            Club61PermissionMatrix::grantNewPermissionsToPresetRoles('web');
        }
    }

    /** JANGAN di-rollback di production setelah ada tagihan — riwayat pelunasan & penanda tagihan ikut terhapus. */
    public function down(): void
    {
        if (Schema::hasIndex('coaching_bookings', 'idx_coaching_booking_tab')) {
            Schema::table('coaching_bookings', fn (Blueprint $t) => $t->dropIndex('idx_coaching_booking_tab'));
        }
        Schema::table('coaching_bookings', function (Blueprint $t) {
            foreach (['settlement_id', 'cashier_shift_id', 'academy_id'] as $fk) {
                if (Schema::hasColumn('coaching_bookings', $fk)) {
                    $t->dropConstrainedForeignId($fk);
                }
            }
        });
        Schema::table('coaching_bookings', fn (Blueprint $t) => $t->dropColumn(array_values(array_filter(
            ['billing', 'billing_cycle', 'due_at'], fn ($c) => Schema::hasColumn('coaching_bookings', $c)
        ))));
        Schema::dropIfExists('coaching_settlements');
        Schema::table('coaching_academies', fn (Blueprint $t) => $t->dropColumn(array_values(array_filter(
            ['billing_mode', 'billing_cycle', 'billing_anchor_date', 'block_when_overdue'], fn ($c) => Schema::hasColumn('coaching_academies', $c)
        ))));
    }
};
