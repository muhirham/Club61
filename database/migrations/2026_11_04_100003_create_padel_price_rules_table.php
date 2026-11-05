<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul 32 — Harga Lapangan Dinamis (docs/PRD_MODUL_32_HARGA_LAPANGAN_DINAMIS.md). Hanya menambah tabel baru:
 * Harga Khusus per Periode di atas Harga Dasar (padel_courts.hourly_rate_regular / prime + jam peak). Selama tabel ini
 * kosong, harga lapangan sama persis dengan sebelumnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('padel_price_rules')) {
            return;
        }

        Schema::create('padel_price_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 100);
            $table->json('court_ids')->nullable();          // null = semua lapangan
            $table->date('valid_from');                     // tanggal MAIN pertama
            $table->date('valid_until')->nullable();        // tanggal main terakhir; null = seterusnya
            $table->json('days');                           // 0 = Minggu ... 6 = Sabtu (sama dengan jam peak)
            $table->boolean('include_holidays')->default(false); // berlaku di tanggal merah
            $table->unsignedTinyInteger('start_hour');      // 0..23
            $table->unsignedTinyInteger('end_hour');        // 1..24 (eksklusif; 24 = tengah malam)
            $table->decimal('price_per_hour', 12, 2);
            $table->boolean('is_prime')->default(false);    // label jam: Reguler / Prime
            $table->unsignedSmallInteger('priority')->default(10);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'valid_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('padel_price_rules');
    }
};
