<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gangguan_source_rows', function (Blueprint $table) {
            $table->id();

            // Data mentah dari GANGGUAN_RAW (24 kolom, semua string)
            $table->string('id_gangguan_raw')->nullable();
            $table->string('penyulang_raw')->nullable();
            $table->string('peralatan_raw')->nullable();
            $table->string('ulp_raw')->nullable();
            $table->string('tanggal_kejadian_raw')->nullable();
            $table->string('kode_gangguan_raw')->nullable();
            $table->text('uraian_penyebab_raw')->nullable();
            $table->string('penyebab_raw')->nullable();
            $table->string('lepas_raw')->nullable();
            $table->string('masuk_raw')->nullable();
            $table->string('beban_amp_raw')->nullable();
            $table->string('rele_kerja_raw')->nullable();
            $table->string('arus_gangguan_amp_raw')->nullable();
            $table->string('lama_padam_menit_raw')->nullable();
            $table->string('ens_kwh_raw')->nullable();
            $table->string('sifat_gangguan_raw')->nullable();
            $table->string('id_aset_raw')->nullable();
            $table->string('section_id_raw')->nullable();
            $table->string('posko_id_raw')->nullable();
            $table->string('latitude_raw')->nullable();
            $table->string('longitude_raw')->nullable();
            $table->string('jenis_hewan_raw')->nullable();
            $table->text('foto_ref_raw')->nullable();
            $table->string('sumber_data_raw')->nullable();

            $table->unsignedInteger('source_row_number')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index('id_gangguan_raw');
            $table->index('penyulang_raw');
            $table->index('id_aset_raw');
            $table->index('section_id_raw');
            $table->index('posko_id_raw');
            $table->index('tanggal_kejadian_raw');
            $table->index('sifat_gangguan_raw');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gangguan_source_rows');
    }
};
