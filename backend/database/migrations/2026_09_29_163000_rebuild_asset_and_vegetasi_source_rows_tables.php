<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─── 1. Rebuild asset_source_rows (ASSET_RAW: 7 kolom) ───
        Schema::dropIfExists('asset_source_rows');

        Schema::create('asset_source_rows', function (Blueprint $table) {
            $table->id();

            // Data mentah dari ASSET_RAW (7 kolom, semua string)
            $table->string('id_aset_raw')->nullable();
            $table->string('nama_aset_raw')->nullable();
            $table->string('jenis_aset_raw')->nullable();
            $table->string('penyulang_raw')->nullable();
            $table->string('latitude_raw')->nullable();
            $table->string('longitude_raw')->nullable();
            $table->string('status_raw')->nullable();

            $table->unsignedInteger('source_row_number')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index('id_aset_raw');
            $table->index('penyulang_raw');
            $table->index('status_raw');
        });

        // ─── 2. Rebuild vegetasi_source_rows (VEGETASI_RAW: 28 kolom) ───
        Schema::dropIfExists('vegetasi_source_rows');

        Schema::create('vegetasi_source_rows', function (Blueprint $table) {
            $table->id();

            // Data mentah dari VEGETASI_RAW (28 kolom, semua string)
            $table->string('no_raw')->nullable();
            $table->string('nama_pohon_raw')->nullable();
            $table->string('up3_raw')->nullable();
            $table->string('ulp_raw')->nullable();
            $table->string('penyulang_raw')->nullable();
            $table->string('id_jtm_raw')->nullable();
            $table->string('id_tiang_raw')->nullable();
            $table->string('nama_tiang_raw')->nullable();
            $table->string('jenis_pohon_raw')->nullable();
            $table->string('pertumbuhan_pohon_raw')->nullable();
            $table->string('jarak_aman_raw')->nullable();
            $table->string('jarak_bahaya_raw')->nullable();
            $table->string('kondisi_raw')->nullable();
            $table->string('section_raw')->nullable();
            $table->string('eam_raw')->nullable();
            $table->string('latitude_raw')->nullable();
            $table->string('longitude_raw')->nullable();
            $table->string('jarak_pohon_raw')->nullable();
            $table->string('jarak_pohon_saat_ini_raw')->nullable();
            $table->string('izin_padam_raw')->nullable();
            $table->string('izin_tebang_bawah_raw')->nullable();
            $table->string('tanggal_update_raw')->nullable();
            $table->string('tanggal_periksa_raw')->nullable();
            $table->string('tanggal_perkiraan_bahaya_raw')->nullable();
            $table->string('user_pembuat_raw')->nullable();
            $table->string('user_update_raw')->nullable();
            $table->text('keterangan_raw')->nullable();
            $table->string('id_pohon_raw')->nullable();

            $table->unsignedInteger('source_row_number')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index('id_pohon_raw');
            $table->index('penyulang_raw');
            $table->index('id_tiang_raw');
            $table->index('id_jtm_raw');
            $table->index('kondisi_raw');
        });
    }

    public function down(): void
    {
        // Rollback ke skema lama jika diperlukan
        Schema::dropIfExists('asset_source_rows');

        Schema::create('asset_source_rows', function (Blueprint $table) {
            $table->id();

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

        Schema::dropIfExists('vegetasi_source_rows');

        Schema::create('vegetasi_source_rows', function (Blueprint $table) {
            $table->id();

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
};
