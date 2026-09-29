<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apkuk_source_rows', function (Blueprint $table) {
            $table->id();

            // Data mentah dari Sheet1 APKUK
            $table->string('source_id')->nullable();
            $table->date('tanggal')->nullable();
            $table->string('petugas_raw')->nullable();
            $table->string('penyulang_raw')->nullable();
            $table->string('section_raw')->nullable();
            $table->string('asset_code_raw')->nullable();
            $table->string('naik_dari_raw')->nullable();

            $table->smallInteger('terpasang_bh')->nullable();
            $table->smallInteger('kurang_bh')->nullable();

            $table->string('status_raw')->nullable();

            // Koordinat asli tetap disimpan
            $table->string('koordinat_raw')->nullable();

            // Hasil parsing koordinat
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->text('foto_raw')->nullable();

            // Nomor baris asli pada spreadsheet
            $table->unsignedInteger('source_row_number')->nullable();

            // Waktu data masuk dari sumber
            $table->timestamp('imported_at')->nullable();

            $table->timestamps();

            // Index untuk pencarian/matching nanti
            $table->index('source_id');
            $table->index('asset_code_raw');
            $table->index('penyulang_raw');
            $table->index('section_raw');
            $table->index('status_raw');
            $table->index('tanggal');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apkuk_source_rows');
    }
};