<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Menambahkan kolom 'role' (NETRAL, ADMIN, SMG, SBY)
            // Default: NETRAL
            $table->enum('role', ['NETRAL', 'ADMIN', 'SMG', 'SBY'])
                ->default('NETRAL')
                ->after('email')
                ->comment('User role for access control (NETRAL, ADMIN, SMG, SBY)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
