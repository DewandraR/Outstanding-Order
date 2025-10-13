<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // 1) Tambah user_id (kalau belum ada)
        if (!Schema::hasColumn('item_remarks', 'user_id')) {
            Schema::table('item_remarks', function (Blueprint $table) {
                $table->foreignId('user_id')
                    ->nullable()
                    ->after('so_item_id')
                    ->constrained('users')
                    ->cascadeOnUpdate()
                    ->nullOnDelete();
            });
        }

        // 2) Lepas unique lama agar bisa multi-row per item
        $exists = DB::selectOne("
            SELECT COUNT(1) c
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'item_remarks'
              AND INDEX_NAME = 'item_remarks_nk_unique'
        ")->c ?? 0;

        if ($exists) {
            DB::statement("ALTER TABLE `item_remarks`
                           DROP INDEX `item_remarks_nk_unique`");
        }

        // 3) Ganti dengan index lookup biasa (non-unique)
        //    supaya query by (werks, auart, vbeln, posnr) tetap cepat
        $hasLookup = DB::selectOne("
            SELECT COUNT(1) c FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME='item_remarks'
              AND INDEX_NAME='item_remarks_lookup_idx'
        ")->c ?? 0;

        if (!$hasLookup) {
            Schema::table('item_remarks', function (Blueprint $table) {
                $table->index(
                    ['IV_WERKS_PARAM', 'IV_AUART_PARAM', 'VBELN', 'POSNR'],
                    'item_remarks_lookup_idx'
                );
            });
        }
    }

    public function down(): void
    {
        // rollback: hapus index lookup, drop FK user_id (opsional)
        Schema::table('item_remarks', function (Blueprint $table) {
            if (Schema::hasColumn('item_remarks', 'user_id')) {
                $table->dropConstrainedForeignId('user_id');
            }
            $table->dropIndex('item_remarks_lookup_idx');
        });

        // (opsional) hidupkan kembali unique lama
        DB::statement("
            ALTER TABLE `item_remarks`w
            ADD UNIQUE KEY `item_remarks_nk_unique`
            (`IV_WERKS_PARAM`,`IV_AUART_PARAM`,`VBELN`,`POSNR`)
        ");
    }
};
