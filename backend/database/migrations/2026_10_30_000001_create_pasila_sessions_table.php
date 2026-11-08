<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesi PASILA per Posko per shift (Batch B3.1), additive.
 *
 * - Satu sesi per shift_instance + Posko (unik), dibuka otomatis pada awal shift; pembuka yang
 *   dipanggil ulang atau paralel tidak membuat sesi ganda.
 * - Section yang dipatroli disimpan per sesi (penyulang + Section ternormalisasi). Kepemilikan
 *   Section tetap berasal dari SectionPoskoMap; tabel ini hanya mencatat pilihan shift tersebut.
 * - status (siklus sesi) dan section_status (penentuan Section) terpisah; status WO APKUK dan
 *   kewajiban P0 tidak disimpan di sini.
 * - Temuan per titik (B3.2) dan P0 (B3.3) kelak merujuk pasila_sessions.id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pasila_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 60)->unique();
            $table->foreignId('shift_instance_id')->constrained('shift_instances')->restrictOnDelete();
            $table->foreignUuid('posko_id')->constrained('poskos')->restrictOnDelete();
            $table->string('status', 24); // DIBUKA
            $table->string('section_status', 24); // MENUNGGU_PENENTUAN | REKOMENDASI_SISTEM | DITETAPKAN_ADMIN
            $table->foreignUuid('penyulang_id')->nullable()->constrained('penyulangs')->restrictOnDelete();
            $table->string('section_key')->nullable(); // Section ternormalisasi (ApkukSectionService::normalize)
            $table->string('section_name')->nullable();
            $table->string('section_hold_reason', 60)->nullable();
            $table->json('recommendation')->nullable(); // strategi, versi, alasan, jumlah Section dipertimbangkan
            $table->foreignId('section_set_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('section_set_at')->nullable();
            $table->text('section_set_reason')->nullable();
            $table->timestamp('opened_at');
            $table->string('opened_trigger', 16); // SCHEDULER | CLI | ADMIN_WEB
            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['shift_instance_id', 'posko_id']);
            $table->index(['posko_id', 'section_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pasila_sessions');
    }
};
