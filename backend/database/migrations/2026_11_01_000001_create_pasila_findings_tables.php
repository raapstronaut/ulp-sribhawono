<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Temuan PASILA per titik dan laporan nihil (Batch B3.2), additive.
 *
 * - pasila_findings: satu record = satu temuan lapangan dalam satu sesi PASILA, dengan Section
 *   (terverifikasi milik Posko sesi saat dicatat), kode temuan dari reference_codes, uraian,
 *   GPS perangkat, waktu kejadian, dan pelapor. Banyak temuan per sesi (sequence unik per sesi).
 *   Dirujuk P0 kelak (B3.3) lewat id; tidak ada kolom P0 di sini.
 * - pasila_finding_media / pasila_finding_personnel: foto bukti dan petugas roster per temuan.
 * - pasila_nil_reports: pernyataan eksplisit "patroli dilakukan, tidak ada temuan" (satu per sesi),
 *   terpisah dari temuan agar tidak pernah dihitung sebagai temuan. Temuan yang masuk kemudian
 *   menggantikannya (status DIGANTIKAN_TEMUAN), laporan nihil tetap tersimpan sebagai riwayat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pasila_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('pasila_session_id')->constrained('pasila_sessions')->restrictOnDelete();
            $table->unsignedInteger('sequence'); // nomor temuan dalam sesi, mulai 1
            $table->foreignUuid('posko_id')->constrained('poskos')->restrictOnDelete();
            $table->foreignUuid('penyulang_id')->constrained('penyulangs')->restrictOnDelete();
            $table->string('section_key'); // Section ternormalisasi (ApkukSectionService::normalize)
            $table->string('section_name');
            $table->string('finding_code', 80); // reference_codes kategori PASILA_FINDING
            $table->text('description');
            $table->foreignId('keypoint_id')->nullable()->constrained('keypoints')->restrictOnDelete();
            $table->string('asset_label')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('gps_accuracy_m', 8, 2)->nullable();
            $table->string('coordinate_source', 16); // PERANGKAT
            $table->timestamp('occurred_at');
            $table->timestamp('received_at');
            $table->string('status', 24); // TERCATAT
            $table->string('source', 16); // MOBILE
            $table->foreignUuid('submitted_by_field_team_id')->constrained('field_teams')->restrictOnDelete();
            $table->foreignId('submitted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('client_request_id', 64)->nullable()->unique();
            $table->timestamps();

            $table->unique(['pasila_session_id', 'sequence']);
            $table->index(['posko_id', 'occurred_at']);
            $table->index('section_key');
        });

        Schema::create('pasila_finding_media', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('pasila_finding_id')->constrained('pasila_findings')->restrictOnDelete();
            $table->string('capture_source', 16); // CAMERA
            $table->string('disk', 32);
            $table->string('storage_path', 512)->unique();
            $table->string('sha256', 64)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->index('pasila_finding_id');
        });

        Schema::create('pasila_finding_personnel', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('pasila_finding_id')->constrained('pasila_findings')->restrictOnDelete();
            $table->foreignUuid('field_personnel_id')->constrained('field_personnel')->restrictOnDelete();
            $table->string('personnel_name'); // nama saat dicatat
            $table->timestamps();

            $table->unique(['pasila_finding_id', 'field_personnel_id']);
        });

        Schema::create('pasila_nil_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('pasila_session_id')->unique()->constrained('pasila_sessions')->restrictOnDelete();
            $table->text('note'); // area/rute yang dipatroli
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('gps_accuracy_m', 8, 2)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('received_at');
            $table->string('status', 24); // AKTIF | DIGANTIKAN_TEMUAN
            $table->foreignUuid('superseded_by_finding_id')->nullable()->constrained('pasila_findings')->restrictOnDelete();
            $table->timestamp('superseded_at')->nullable();
            $table->string('source', 16); // MOBILE
            $table->foreignUuid('submitted_by_field_team_id')->constrained('field_teams')->restrictOnDelete();
            $table->foreignId('submitted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('client_request_id', 64)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('pasila_nil_report_personnel', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('pasila_nil_report_id')->constrained('pasila_nil_reports')->restrictOnDelete();
            $table->foreignUuid('field_personnel_id')->constrained('field_personnel')->restrictOnDelete();
            $table->string('personnel_name');
            $table->timestamps();

            $table->unique(['pasila_nil_report_id', 'field_personnel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pasila_nil_report_personnel');
        Schema::dropIfExists('pasila_nil_reports');
        Schema::dropIfExists('pasila_finding_personnel');
        Schema::dropIfExists('pasila_finding_media');
        Schema::dropIfExists('pasila_findings');
    }
};
