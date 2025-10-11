<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_remarks_po', function (Blueprint $table) {
            $table->id();

            // legacy optional (boleh nanti dihapus): biarkan nullable
            $table->unsignedBigInteger('po_item_id')->nullable();

            // NATURAL KEY (stabil)
            $table->string('IV_WERKS_PARAM', 10);
            $table->string('IV_AUART_PARAM', 10);
            $table->string('VBELN', 20);
            $table->string('POSNR', 10);

            $table->text('remark')->nullable();
            $table->timestamps();

            $table->index(['IV_WERKS_PARAM', 'IV_AUART_PARAM', 'VBELN']);
            $table->unique(['IV_WERKS_PARAM', 'IV_AUART_PARAM', 'VBELN', 'POSNR'], 'item_remarks_nk_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_remarks_po');
    }
};
