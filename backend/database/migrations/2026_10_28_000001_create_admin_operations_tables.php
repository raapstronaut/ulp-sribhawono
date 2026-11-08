<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch A Web Admin operasional. Seluruhnya additive: tabel baru dan satu kolom nullable
 * pada users. Tidak mengubah atau menghapus data yang sudah ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Akun tim lapangan (POSKO/HARSUS/INSPEKSI/ROW) terikat ke satu tim; akun ADMIN tidak.
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignUuid('field_team_id')->nullable()->constrained('field_teams')->restrictOnDelete();
        });

        // Audit umum untuk perubahan master data, akun, dan keputusan Admin di luar WO.
        // Perubahan WO tetap tercatat di work_order_status_logs.
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('actor_field_team_id')->nullable()->constrained('field_teams')->restrictOnDelete();
            $table->string('entity_type', 60);
            $table->string('entity_id', 64);
            $table->string('entity_label')->nullable();
            $table->string('action', 40);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['action', 'created_at']);
            $table->index('created_at');
        });

        // Target lokasi WO non-APKUK: aset jaringan (keypoint) untuk HARSUS, titik vegetasi
        // untuk ROW, atau salinan lokasi WO asal untuk limpahan. Snapshot: sinkronisasi
        // sumber berikutnya tidak mengubah target WO.
        Schema::create('work_order_location_targets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_order_id')->unique()->constrained('work_orders')->restrictOnDelete();
            $table->string('target_type', 24); // KEYPOINT | VEGETASI | WORK_ORDER
            $table->string('source_ref', 64);
            $table->string('target_key', 191)->index();
            // Terisi hanya selama WO aktif: mencegah dua WO aktif berjenis sama untuk titik yang sama.
            $table->string('active_target_key', 191)->nullable()->unique();
            $table->string('label');
            $table->string('asset_type')->nullable();
            $table->string('penyulang_source')->nullable();
            $table->string('section_source')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();
        });

        // Permintaan limpahan lintas tim; ACC Admin → sumber SELESAI_DITERUSKAN + child WO.
        Schema::create('work_escalations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('source_work_order_id')->constrained('work_orders')->restrictOnDelete();
            $table->string('target_team_type', 24);
            $table->string('target_work_type', 40);
            $table->string('reason_code', 80);
            $table->text('note');
            $table->foreignUuid('requested_by_field_team_id')->nullable()->constrained('field_teams')->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 24); // MENUNGGU_ACC | DISETUJUI | DITOLAK
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignUuid('child_work_order_id')->nullable()->unique()->constrained('work_orders')->restrictOnDelete();
            $table->string('client_request_id', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('source_work_order_id');
        });

        // Special case / kendala pelaksanaan WO.
        Schema::create('work_order_issues', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_order_id')->constrained('work_orders')->restrictOnDelete();
            $table->string('issue_type', 40);
            $table->text('description');
            $table->string('status', 24); // TERBUKA | SELESAI
            $table->foreignUuid('reported_by_field_team_id')->nullable()->constrained('field_teams')->restrictOnDelete();
            $table->foreignId('reported_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('resolution_action', 24)->nullable();
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('client_request_id', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('work_order_id');
        });

        // Kunjungan INSPEKSI, termasuk hasil "Tidak Ada Temuan" yang dicatat jujur.
        Schema::create('inspections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->foreignUuid('field_team_id')->nullable()->constrained('field_teams')->restrictOnDelete();
            $table->foreignUuid('penyulang_id')->nullable()->constrained('penyulangs')->restrictOnDelete();
            $table->string('section_name')->nullable();
            $table->timestamp('visited_at');
            $table->boolean('has_finding');
            $table->text('summary')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('gps_accuracy_m', 8, 2)->nullable();
            $table->string('source', 16); // MOBILE | ADMIN
            $table->foreignUuid('submitted_by_field_team_id')->nullable()->constrained('field_teams')->restrictOnDelete();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('client_request_id', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['visited_at']);
            $table->index(['field_team_id', 'visited_at']);
            $table->index('penyulang_id');
        });

        Schema::create('inspection_personnel', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('inspection_id')->constrained('inspections')->restrictOnDelete();
            $table->foreignUuid('field_personnel_id')->nullable()->constrained('field_personnel')->restrictOnDelete();
            $table->string('personnel_name');
            $table->timestamps();

            $table->index('inspection_id');
        });

        // Satu temuan satu lokasi/foto; ACC Admin sebelum menjadi dasar tindakan.
        Schema::create('inspection_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('inspection_id')->constrained('inspections')->restrictOnDelete();
            $table->string('finding_code', 80);
            $table->text('description');
            $table->foreignId('keypoint_id')->nullable()->constrained('keypoints')->restrictOnDelete();
            $table->string('asset_label')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->string('suggested_team_type', 24)->nullable();
            $table->string('validation_status', 24); // MENUNGGU_VALIDASI | DISETUJUI | DITOLAK
            $table->foreignId('validated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->text('validation_note')->nullable();
            $table->foreignUuid('follow_up_work_order_id')->nullable()->unique()->constrained('work_orders')->restrictOnDelete();
            $table->timestamps();

            $table->index(['validation_status', 'created_at']);
            $table->index('inspection_id');
        });

        Schema::create('inspection_finding_media', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('inspection_finding_id')->constrained('inspection_findings')->restrictOnDelete();
            $table->string('capture_source', 16); // CAMERA | GALLERY | ADMIN_UPLOAD
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

            $table->index('inspection_finding_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_finding_media');
        Schema::dropIfExists('inspection_findings');
        Schema::dropIfExists('inspection_personnel');
        Schema::dropIfExists('inspections');
        Schema::dropIfExists('work_order_issues');
        Schema::dropIfExists('work_escalations');
        Schema::dropIfExists('work_order_location_targets');
        Schema::dropIfExists('audit_logs');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('field_team_id');
        });
    }
};
