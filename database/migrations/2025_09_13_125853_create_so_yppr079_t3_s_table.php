<?php
// database/migrations/2025_09_13_000003_create_so_yppr079_t3_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('so_yppr079_t3', function (Blueprint $table) {
            $table->engine   = 'InnoDB';
            $table->charset  = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->bigIncrements('id');
            $table->string('IV_WERKS_PARAM', 10);
            $table->string('IV_AUART_PARAM', 10);
            $table->string('VBELN', 20)->nullable();
            $table->string('POSNR', 10)->nullable();
            $table->string('MANDT', 10)->nullable();
            $table->string('KUNNR', 20)->nullable();
            $table->string('NAME1', 120)->nullable();
            $table->string('AUART', 10)->nullable();
            $table->decimal('NETPR', 18, 2)->nullable();
            $table->decimal('NETWR', 18, 2)->nullable();
            $table->decimal('TOTPR', 18, 2)->nullable();
            $table->decimal('TOTPR2', 18, 2)->nullable();
            $table->string('WAERK', 5)->nullable();
            $table->date('EDATU')->nullable();
            $table->string('WERKS', 10)->nullable();
            $table->string('BSTNK', 80)->nullable();
            $table->decimal('KWMENG', 18, 3)->nullable();
            $table->decimal('BMENG', 18, 3)->nullable();
            $table->string('VRKME', 6)->nullable();
            $table->string('MEINS', 6)->nullable();
            $table->string('MATNR', 40)->nullable();
            $table->string('MAKTX', 200)->nullable();
            $table->decimal('KALAB', 18, 3)->nullable();
            $table->decimal('KALAB2', 18, 3)->nullable();
            $table->decimal('QTY_DELIVERY', 18, 3)->nullable();
            $table->decimal('QTY_GI', 18, 3)->nullable();
            $table->decimal('QTY_BALANCE', 18, 3)->nullable();
            $table->decimal('QTY_BALANCE2', 18, 3)->nullable();
            $table->decimal('MENGX1', 18, 3)->nullable();
            $table->decimal('MENGX2', 18, 3)->nullable();
            $table->decimal('MENGE', 18, 3)->nullable();
            $table->decimal('ASSYM', 18, 3)->nullable();
            $table->decimal('PAINT', 18, 3)->nullable();
            $table->decimal('PACKG', 18, 3)->nullable();
            $table->decimal('QTYS', 18, 3)->nullable();
            $table->decimal('MACHI', 18, 3)->nullable();
            $table->decimal('EBDIN', 18, 3)->nullable();
            $table->decimal('MACHP', 18, 3)->nullable();
            $table->decimal('EBDIP', 18, 3)->nullable();
            $table->string('TYPE1', 25)->nullable();
            $table->string('TYPE2', 25)->nullable();
            $table->string('TYPE', 25)->nullable();
            $table->integer('DAYX')->nullable();
            $table->dateTime('fetched_at');

            $table->unique(['IV_WERKS_PARAM', 'IV_AUART_PARAM', 'VBELN', 'POSNR'], 'uq_t3');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('so_yppr079_t3');
    }
};
