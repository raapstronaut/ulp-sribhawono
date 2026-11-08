<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P0 POSKO (Batch B3.3), additive.
 *
 * p0_items mencatat setiap P0 terpisah dari WO aktif: usulan SYSTEM (wajib ACC Admin sebelum
 * menjadi pekerjaan) maupun P0 manual Admin. Setiap P0 wajib terkait sesi PASILA dan sumber
 * masalah yang tercatat (temuan PASILA atau temuan inspeksi yang sudah divalidasi), dengan Posko,
 * Penyulang, dan Section dari sumber yang terverifikasi milik Posko sesi.
 *
 * - WO P0 (work_orders.work_type = P0) dibuat saat ACC atau saat P0 manual dibuat; work_order_id unik.
 * - Penolakan SYSTEM menimbulkan kewajiban pengganti manual (replacement_status = WAJIB) yang dipenuhi
 *   P0 manual dengan replaces_p0_item_id ke usulan itu (unik: satu pengganti per penolakan).
 * - active_source_key unik mencegah dua P0 berjalan untuk sumber yang sama; dikosongkan saat ditolak.
 * - Kolom work_orders tidak diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('p0_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 80)->unique();
            $table->foreignUuid('pasila_session_id')->constrained('pasila_sessions')->restrictOnDelete();
            $table->unsignedInteger('sequence'); // nomor P0 dalam sesi PASILA, mulai 1
            $table->foreignUuid('posko_id')->constrained('poskos')->restrictOnDelete();
            $table->foreignUuid('penyulang_id')->constrained('penyulangs')->restrictOnDelete();
            $table->string('section_key');
            $table->string('section_name');
            $table->string('origin', 16); // SYSTEM | ADMIN
            $table->string('source_type', 32); // PASILA_FINDING | INSPECTION_FINDING
            $table->foreignUuid('pasila_finding_id')->nullable()->constrained('pasila_findings')->restrictOnDelete();
            $table->foreignUuid('inspection_finding_id')->nullable()->constrained('inspection_findings')->restrictOnDelete();
            $table->string('active_source_key', 120)->nullable()->unique();
            $table->json('source_snapshot');
            $table->text('description'); // uraian pekerjaan P0
            $table->json('recommendation')->nullable(); // SYSTEM: strategi, versi, is_machine_learning, alasan
            $table->string('approval_status', 24); // MENUNGGU_ACC | DISETUJUI | DITOLAK | TIDAK_PERLU
            $table->string('replacement_status', 24); // TIDAK_PERLU | WAJIB | TERPENUHI
            $table->uuid('replaces_p0_item_id')->nullable()->unique(); // FK ke tabel ini, ditambahkan setelah primary key ada
            $table->foreignUuid('work_order_id')->nullable()->unique()->constrained('work_orders')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->unique(['pasila_session_id', 'sequence']);
            $table->index(['approval_status', 'created_at']);
            $table->index(['posko_id', 'created_at']);
        });

        // PostgreSQL menjalankan FK sebelum primary key bila keduanya di Schema::create; FK diri-sendiri dibuat terpisah.
        Schema::table('p0_items', function (Blueprint $table): void {
            $table->foreign('replaces_p0_item_id')->references('id')->on('p0_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('p0_items');
    }
};
