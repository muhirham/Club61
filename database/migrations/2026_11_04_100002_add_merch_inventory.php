<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merchandise di Kasir Pro Shop (Tahap 2A): katalog + kartu stok + penjualan tanpa akun customer.
 *  - merch_products: kategori, HPP per produk (snapshot ke order_items.unit_cost saat terjual), foto.
 *  - merch_variants: stok minimum & aktif / nonaktif per ukuran / warna.
 *  - merch_stock_movements: kartu stok (penjualan, batal, barang masuk, penyesuaian) — tidak diedit / dihapus.
 *  - orders.customer_phone: nomor HP pembeli Kasir Pro Shop (pembeli tidak dibuatkan akun; nama di customer_name).
 * Hanya menambah kolom (boleh kosong / ada default) & tabel baru: data lama tidak diubah. Setiap langkah dicek dulu,
 * jadi `php artisan migrate` berikutnya cukup melanjutkan sisanya bila berhenti di tengah.
 * customer_phone ditaruh di ujung tabel orders (tanpa after) supaya MySQL 8 menambahkannya tanpa menyalin ulang tabel.
 * JANGAN di-rollback di production — down() menghapus kartu stok, HPP, foto produk & nomor HP pembeli.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merch_products', function (Blueprint $t) {
            if (! Schema::hasColumn('merch_products', 'category')) {
                $t->string('category', 30)->nullable()->after('brand');
            }
            if (! Schema::hasColumn('merch_products', 'hpp')) {
                $t->decimal('hpp', 12, 2)->default(0)->after('base_price');
            }
            if (! Schema::hasColumn('merch_products', 'image')) {
                $t->string('image', 255)->nullable()->after('description');
            }
        });

        Schema::table('merch_variants', function (Blueprint $t) {
            if (! Schema::hasColumn('merch_variants', 'min_stock')) {
                $t->integer('min_stock')->nullable()->after('stock_quantity');
            }
            if (! Schema::hasColumn('merch_variants', 'is_active')) {
                $t->boolean('is_active')->default(true)->after('min_stock');
            }
        });

        if (! Schema::hasTable('merch_stock_movements')) {
            Schema::create('merch_stock_movements', function (Blueprint $t) {
                $t->ulid('id')->primary();
                $t->foreignUlid('variant_id')->nullable()->constrained('merch_variants')->nullOnDelete();
                $t->string('product_name', 100);
                $t->string('variant_label', 50);
                $t->string('type', 20); // SALE, SALE_CANCEL, RESTOCK, ADJUSTMENT (RETURN_GOOD / RETURN_DAMAGED: Tahap 2B)
                $t->integer('qty_change');
                $t->integer('stock_after');
                $t->decimal('unit_cost', 12, 2)->default(0); // HPP saat itu (nilai kerugian penyesuaian)
                $t->decimal('purchase_total', 14, 2)->nullable(); // barang masuk: total harga beli baris ini
                $t->string('reference_number', 30)->nullable(); // barang masuk: satu nomor untuk satu nota
                $t->string('supplier', 100)->nullable();
                $t->string('invoice_number', 60)->nullable();
                $t->foreignUlid('order_id')->nullable()->constrained('orders')->nullOnDelete();
                $t->string('reason', 30)->nullable();
                $t->text('notes')->nullable();
                $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('created_at')->nullable();

                $t->index(['variant_id', 'created_at'], 'idx_merch_stock_variant_time');
                $t->index(['type', 'created_at'], 'idx_merch_stock_type_time');
            });
        }

        if (! Schema::hasColumn('orders', 'customer_phone')) {
            Schema::table('orders', function (Blueprint $t) {
                $t->string('customer_phone', 20)->nullable();
                $t->index('customer_phone', 'idx_orders_customer_phone');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'customer_phone')) {
            Schema::table('orders', function (Blueprint $t) {
                $t->dropIndex('idx_orders_customer_phone');
                $t->dropColumn('customer_phone');
            });
        }
        Schema::dropIfExists('merch_stock_movements');
        Schema::table('merch_variants', function (Blueprint $t) {
            foreach (['min_stock', 'is_active'] as $column) {
                if (Schema::hasColumn('merch_variants', $column)) {
                    $t->dropColumn($column);
                }
            }
        });
        Schema::table('merch_products', function (Blueprint $t) {
            foreach (['category', 'hpp', 'image'] as $column) {
                if (Schema::hasColumn('merch_products', $column)) {
                    $t->dropColumn($column);
                }
            }
        });
    }
};
