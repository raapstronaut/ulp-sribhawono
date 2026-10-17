<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apkuks', function (Blueprint $table) {
            // ID utama PostgreSQL
            $table->id();

            // Identitas dari sistem AppSheet lama
            $table->string('source_id')->nullable()->index();
            $table->string('legacy_key', 64)->nullable()->unique();

            // Data monitoring APKUK
            $table->date('tanggal')->nullable();
            $table->string('petugas')->nullable();
            $table->string('penyulang')->nullable()->index();
            $table->string('section')->nullable();
            $table->string('no_tiang')->nullable()->index();
            $table->string('naik_dari')->nullable();

            $table->integer('terpasang_bh')->nullable();
            $table->integer('kurang_bh')->nullable();
            $table->string('status')->nullable()->index();

            // Lokasi
            $table->string('koordinat')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Dokumentasi
            $table->text('foto')->nullable();

            // Asal data
            $table->string('sumber_input')
                ->default('APPSHEET_LAMA');

            // Informasi sinkronisasi
            $table->unsignedInteger('source_row_number')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();
        });

        // Hapus tabel lama setelah tabel baru berhasil dibuat.
        Schema::dropIfExists('apkuk_source_rows');
    }

    public function down(): void
    {
        // Tidak mengembalikan data dari tabel lama.
        Schema::dropIfExists('apkuks');
    }
};
