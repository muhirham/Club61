<?php

use App\Services\Permission\Club61PermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Izin baru `view_server_health` (dashboard Monitoring Sistem / Laravel Pulse). Hanya menambah baris izin; bawaan hanya
 * super_admin (termasuk BACKDOOR_PERMISSIONS, jadi role admin tidak otomatis mendapatkannya). Aman diulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('permissions') && Schema::hasTable('roles')) {
            Club61PermissionMatrix::grantNewPermissionsToPresetRoles('web');
        }
    }

    public function down(): void
    {
        // Sengaja kosong: izin yang sudah diberikan ke role tidak dicabut otomatis.
    }
};
