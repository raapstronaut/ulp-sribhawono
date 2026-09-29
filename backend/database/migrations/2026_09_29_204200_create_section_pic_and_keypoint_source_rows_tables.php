<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('section_pic_source_rows', function (Blueprint $table) {
            $table->id();

            // Data mentah dari SECTION_PIC_RAW (3 kolom, semua string)
            // PENYULANG sengaja nullable karena source menggunakan grouping visual:
            // penyulang ditulis sekali, baris keypoint berikutnya kosong.
            // Jangan fill-down — pertahankan persis seperti source.
            $table->string('penyulang_raw')->nullable();
            $table->string('pemutus_raw')->nullable();
            $table->string('posko_pic_raw')->nullable();

            $table->unsignedInteger('source_row_number')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index('penyulang_raw');
            $table->index('posko_pic_raw');
        });

        Schema::create('keypoint_source_rows', function (Blueprint $table) {
            $table->id();

            // Data mentah dari KEYPOINT_RAW (5 kolom, semua string)
            // KOORDINAT disimpan sebagai raw string (contoh: "-5.21099, 105.46007")
            // Jangan split atau normalisasi.
            $table->string('penyulang_raw')->nullable();
            $table->string('jenis_raw')->nullable();
            $table->string('nama_raw')->nullable();
            $table->string('koordinat_raw')->nullable();
            $table->text('note_raw')->nullable();

            $table->unsignedInteger('source_row_number')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index('penyulang_raw');
            $table->index('jenis_raw');
            $table->index('nama_raw');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keypoint_source_rows');
        Schema::dropIfExists('section_pic_source_rows');
    }
};
