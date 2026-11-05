<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hak Akses PIC per perusahaan (Kelola Sponsor > Edit). Hanya menambah 1 kolom nullable: daftar aksi PIC yang
 * DIMATIKAN admin. NULL / kosong = semua aksi boleh, jadi perusahaan yang sudah ada tidak berubah apa pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sponsor_organizations', 'pic_restricted_actions')) {
            return;
        }

        Schema::table('sponsor_organizations', function (Blueprint $table) {
            $table->json('pic_restricted_actions')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('sponsor_organizations', 'pic_restricted_actions')) {
            Schema::table('sponsor_organizations', function (Blueprint $table) {
                $table->dropColumn('pic_restricted_actions');
            });
        }
    }
};
