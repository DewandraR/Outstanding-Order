<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('item_remarks_po', function (Blueprint $table) {
            // 1) Hapus UNIQUE lama agar 1 item bisa punya banyak remark
            $table->dropUnique('item_remarks_nk_unique');

            // 2) Index lookup untuk API list (werks+auart+vbeln+posnr)
            $table->index(
                ['IV_WERKS_PARAM', 'IV_AUART_PARAM', 'VBELN', 'POSNR'],
                'item_remarks_po_lookup_idx'
            );

            // 3) Index bantu untuk ambil remark terbaru per item (MAX(id))
            //    Urutan kolom penting agar GROUP BY di (werks, auart, vbeln, posnr) efisien
            $table->index(
                ['IV_WERKS_PARAM', 'IV_AUART_PARAM', 'VBELN', 'POSNR', 'id'],
                'item_remarks_po_item_latest_idx'
            );

            // 4) (opsional) percepat filter berdasarkan pemilik
            $table->index('user_id', 'item_remarks_po_user_idx');
        });
    }

    public function down(): void
    {
        Schema::table('item_remarks_po', function (Blueprint $table) {
            // rollback index baru
            $table->dropIndex('item_remarks_po_user_idx');
            $table->dropIndex('item_remarks_po_item_latest_idx');
            $table->dropIndex('item_remarks_po_lookup_idx');

            // kembalikan UNIQUE lama (akan kembali membatasi 1 remark per item)
            $table->unique(
                ['IV_WERKS_PARAM', 'IV_AUART_PARAM', 'VBELN', 'POSNR'],
                'item_remarks_nk_unique'
            );
        });
    }
};
