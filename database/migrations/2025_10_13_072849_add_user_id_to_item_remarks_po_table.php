<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_remarks_po', function (Blueprint $table) {
            // siapa user terakhir yang menyimpan/mengubah remark
            $table->foreignId('user_id')
                ->nullable()
                ->after('po_item_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete(); // jika user dihapus, remark tetap ada (user_id jadi NULL)
        });
    }

    public function down(): void
    {
        Schema::table('item_remarks_po', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
