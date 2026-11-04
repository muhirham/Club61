<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sifat alat di Master Data: SALE (Jual, habis pakai — boleh dijual tanpa booking) / RENTAL (Sewa, harus kembali —
 * wajib lewat booking). Hanya menambah kolom kosong: data lama tidak diubah. Selama kolomnya kosong, sifatnya mengikuti
 * aturan lama dari jenis alat (CourtEquipment::usage()): BALL = Jual, RACKET / TOWEL = Sewa, lainnya = stok tidak bergerak.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('court_equipments', 'usage')) {
            Schema::table('court_equipments', function (Blueprint $t) {
                $t->string('usage', 10)->nullable()->after('type'); // SALE, RENTAL
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('court_equipments', 'usage')) {
            Schema::table('court_equipments', function (Blueprint $t) {
                $t->dropColumn('usage');
            });
        }
    }
};
