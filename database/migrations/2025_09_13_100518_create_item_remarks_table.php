    <?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration
    {
        /**
         * Jalankan migrasi.
         */
        public function up()
        {
            Schema::create('item_remarks', function (Blueprint $table) {
                $table->id();
                // Kolom untuk menghubungkan ke tabel item. Dibuat sebagai index untuk performa query.
                $table->unsignedBigInteger('so_item_id')->unique();
                $table->text('remark')->nullable();
                $table->timestamps();
            });
        }

        /**
         * Batalkan migrasi.
         */
        public function down()
        {
            Schema::dropIfExists('item_remarks');
        }
    };
