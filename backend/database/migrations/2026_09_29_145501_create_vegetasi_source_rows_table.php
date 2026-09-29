<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vegetasi_source_rows', function (Blueprint $table) {
            $table->id();

            // Data mentah dari VEGETASI_RAW (20 kolom, semua string)
            // Kolom M dan T di spreadsheet sama-sama bernama KETERANGAN.
            // Mapping berdasarkan posisi: M → keterangan_m_raw, T → keterangan_t_raw.
            $table->string('id_pohon_raw')->nullable();
            $table->string('nama_pohon_raw')->nullable();
            $table->string('penyulang_raw')->nullable();
            $table->string('id_aset_raw')->nullable();
            $table->string('nama_tiang_raw')->nullable();
            $table->string('jenis_pohon_raw')->nullable();
            $table->string('latitude_raw')->nullable();
            $table->string('longitude_raw')->nullable();
            $table->string('jarak_pohon_raw')->nullable();
            $table->string('kondisi_raw')->nullable();
            $table->string('tanggal_survey_raw')->nullable();
            $table->string('section_id_raw')->nullable();
            $table->text('keterangan_m_raw')->nullable();
            $table->string('status_raw')->nullable();
            $table->string('tanggal_inspeksi_terakhir_raw')->nullable();
            $table->string('tanggal_row_terakhir_raw')->nullable();
            $table->string('jenis_row_terakhir_raw')->nullable();
            $table->string('kondisi_terakhir_agustus_raw')->nullable();
            $table->text('foto_ref_raw')->nullable();
            $table->text('keterangan_t_raw')->nullable();

            $table->unsignedInteger('source_row_number')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index('id_pohon_raw');
            $table->index('penyulang_raw');
            $table->index('id_aset_raw');
            $table->index('section_id_raw');
            $table->index('status_raw');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vegetasi_source_rows');
    }
};
