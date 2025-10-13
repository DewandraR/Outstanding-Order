<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_remarks', function (Blueprint $table) {
            // simpan siapa pengguna yang terakhir menyimpan/mengubah remark
            $table->foreignId('user_id')
                ->nullable()
                ->after('so_item_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete(); // jika user dihapus, set NULL agar remark tetap ada
        });
    }

    public function down(): void
    {
        Schema::table('item_remarks', function (Blueprint $table) {
            // drop FK + kolom sekaligus
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
