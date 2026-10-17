
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hanya hapus tabel gangguan lama.
        Schema::dropIfExists('gangguan_source_rows');

        // Satu tabel utama gangguan dari PLN pusat.
        Schema::create('gangguans', function (Blueprint $table) {
            $table->string('id_gangguan', 100)->primary();
            $table->date('tanggal_kejadian')->nullable()->index();
            $table->string('penyulang')->nullable()->index();
            $table->string('ulp')->nullable();
            $table->string('peralatan')->nullable();
            $table->string('kategori_proteksi_pusat')->nullable();

            $table->string('kode_gangguan')->nullable();
            $table->string('sifat_gangguan')->nullable()->index();
            $table->string('penyebab')->nullable()->index();
            $table->string('jenis_hewan')->nullable();

            $table->text('uraian_penyebab')->nullable();
            $table->string('no_tiang')->nullable()->index();
            $table->text('keypoint_pusat')->nullable();

            $table->string('lepas', 30)->nullable();
            $table->string('masuk', 30)->nullable();
            $table->decimal('lama_padam_menit', 18, 8)->nullable();
            $table->decimal('ens_kwh', 20, 8)->nullable();
            $table->decimal('ens_apkt_kwh', 20, 8)->nullable();

            $table->decimal('beban_amp', 18, 6)->nullable();
            $table->decimal('beban_setelah_amp', 18, 6)->nullable();
            $table->string('rele_kerja')->nullable();
            $table->decimal('arus_gangguan_amp', 18, 6)->nullable();

            $table->string('material_rusak')->nullable();
            $table->text('tindakan_corrective')->nullable();
            $table->text('tindakan_preventive')->nullable();
            $table->string('keterangan_temuan')->nullable();

            $table->text('foto_gangguan_url')->nullable();
            $table->text('foto_corrective_url')->nullable();

            $table->string('sumber_data')->default('PLN_PUSAT');

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Rollback tidak bisa memulihkan data lama.
        Schema::dropIfExists('gangguans');
    }
};