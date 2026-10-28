<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Koreksi Section internal oleh Admin untuk laporan APKUK yang Section sumbernya kosong/DUMMY
 * (keputusan pengguna 28 Okt 2026). Kolom apkuks.section (nilai SPS BOS) tidak pernah diubah;
 * sinkronisasi apkuk:sync tidak menyentuh tabel ini. Satu baris per laporan menyimpan keadaan
 * koreksi saat ini; seluruh riwayat (nilai lama/baru, pelaku, waktu, alasan) ada di audit_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apkuk_section_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('apkuk_id')->unique()->constrained('apkuks')->restrictOnDelete();
            // Jejak identitas laporan sumber saat koreksi dibuat.
            $table->string('apkuk_source_id')->nullable();
            // Null bila koreksi dicabut (Section efektif kembali ke nilai sumber).
            $table->string('section_name')->nullable();
            // Nilai Section sumber pada saat koreksi terakhir disimpan/dicabut.
            $table->string('source_section_at_change')->nullable();
            // Referensi yang membuktikan nilai koreksi: SECTION_PIC_RAW | APKUK_PENYULANG_SAMA.
            $table->string('reference_basis', 40)->nullable();
            $table->string('status', 20); // AKTIF | DICABUT
            $table->text('reason');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apkuk_section_overrides');
    }
};
