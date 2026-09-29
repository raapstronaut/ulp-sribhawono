<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_source_rows', function (Blueprint $table) {
            $table->id();

            // Data mentah dari ASSET_RAW (10 kolom, semua string)
            $table->string('id_aset_raw')->nullable();
            $table->string('nama_aset_raw')->nullable();
            $table->string('jenis_aset_raw')->nullable();
            $table->string('penyulang_raw')->nullable();
            $table->string('section_id_raw')->nullable();
            $table->string('posko_id_raw')->nullable();
            $table->string('latitude_raw')->nullable();
            $table->string('longitude_raw')->nullable();
            $table->text('foto_url_raw')->nullable();
            $table->string('status_raw')->nullable();

            $table->unsignedInteger('source_row_number')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index('id_aset_raw');
            $table->index('penyulang_raw');
            $table->index('section_id_raw');
            $table->index('posko_id_raw');
            $table->index('status_raw');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_source_rows');
    }
};
