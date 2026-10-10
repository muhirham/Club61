<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Stasiun F&B dinamis (Kitchen, nanti Bar, dst.) + printer LAN-nya. Menu tanpa stasiun = dibuat langsung di kasir
 * (tanpa slip). Slip stasiun tercatat di kitchen_tickets begitu order lunas, lalu dikirim tablet kasir ke printer LAN
 * stasiunnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fnb_stations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 50);
            $table->string('printer_host', 100)->nullable(); // IP printer LAN, mis. 192.168.1.50
            $table->unsignedInteger('printer_port')->default(9100);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('fnb_menus', function (Blueprint $table) {
            $table->foreignUlid('station_id')->nullable()->after('base_price')->constrained('fnb_stations')->nullOnDelete();
        });

        // Database yang sudah berisi menu: stasiun "Kitchen" dibuat & menu KITCHEN dipindah ke sana. Menu BAR (belum ada
        // bar) → dibuat di kasir, tanpa slip. Database kosong (instalasi baru / test) tidak diberi stasiun bawaan.
        if (DB::table('fnb_menus')->exists()) {
            $kitchenId = (string) Str::ulid();
            DB::table('fnb_stations')->insert([
                'id' => $kitchenId, 'name' => 'Kitchen', 'printer_port' => 9100, 'is_active' => true, 'sort_order' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('fnb_menus')->where('station', 'KITCHEN')->update(['station_id' => $kitchenId]);
        }

        Schema::table('fnb_menus', function (Blueprint $table) {
            $table->dropColumn('station');
        });

        // Tabel lama belum pernah dipakai kode mana pun — kolom station (teks) diganti relasi + isi slip.
        Schema::table('kitchen_tickets', function (Blueprint $table) {
            $table->dropColumn('station');
        });
        Schema::table('kitchen_tickets', function (Blueprint $table) {
            $table->foreignUlid('station_id')->nullable()->after('order_id')->constrained('fnb_stations')->nullOnDelete();
            $table->string('station_name', 50)->after('station_id')->default('');
            $table->json('items')->nullable()->after('station_name');
            $table->dateTime('printed_at')->nullable()->after('created_at');
            $table->unsignedInteger('print_attempts')->default(0)->after('printed_at');
            $table->string('last_print_error', 255)->nullable()->after('print_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('kitchen_tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('station_id');
            $table->dropColumn(['station_name', 'items', 'printed_at', 'print_attempts', 'last_print_error']);
        });
        Schema::table('kitchen_tickets', function (Blueprint $table) {
            $table->string('station', 20)->default('BAR')->after('order_id');
        });

        Schema::table('fnb_menus', function (Blueprint $table) {
            $table->string('station', 20)->default('BAR')->after('base_price');
        });
        DB::table('fnb_menus')->whereNotNull('station_id')->update(['station' => 'KITCHEN']);
        Schema::table('fnb_menus', function (Blueprint $table) {
            $table->dropConstrainedForeignId('station_id');
        });

        Schema::dropIfExists('fnb_stations');
    }
};
