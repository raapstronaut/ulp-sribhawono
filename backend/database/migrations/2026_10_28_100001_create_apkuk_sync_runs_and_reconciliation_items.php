<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Riwayat setiap eksekusi apkuk:sync (bukan dry-run). Snapshot terakhir yang diterima
        // (snapshot_accepted) menjadi pembanding snapshot berikutnya.
        Schema::create('apkuk_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 40)->index()->comment('RUNNING | SUCCESS | SUCCESS_WITH_PENDING_RECONCILIATION | FAILED');
            $table->string('failure_stage', 32)->nullable()->comment('SOURCE_READ | SOURCE_VALIDATION | SNAPSHOT_GUARD | DATABASE');
            $table->text('failure_reason')->nullable();
            $table->boolean('snapshot_accepted')->default(false)->comment('Snapshot lolos validasi dan pengaman, lalu diterapkan');
            $table->unsignedInteger('source_report_count')->nullable();
            $table->string('source_digest', 64)->nullable()->comment('Sidik isi seluruh laporan sumber');
            $table->string('identity_digest', 64)->nullable()->comment('Sidik himpunan legacy_key sumber');
            $table->json('counts')->nullable()->comment('Jumlah baru, diperbarui, pindah baris, muncul kembali, hilang, antrean');
            $table->json('guard')->nullable()->comment('Pembanding, batas, opsi manual, dan pelanggaran pengaman');
            $table->string('database_user', 120)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['snapshot_accepted', 'id']);
        });

        // Antrean rekonsiliasi: identitas aktif yang hilang dari sumber (MISSING) dan identitas
        // baru yang ditahan karena ID AppSheet-nya sama dengan laporan yang hilang (IDENTITY_CHANGE).
        // Baris tidak pernah dihapus; penyelesaian tercatat di kolom resolution*.
        Schema::create('apkuk_reconciliation_items', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 32)->comment('MISSING | IDENTITY_CHANGE');
            $table->string('status', 16)->index()->comment('OPEN | RESOLVED');
            $table->string('open_key', 100)->nullable()->unique()->comment('<kind>:<legacy_key> selama OPEN; mencegah antrean ganda');
            $table->foreignId('apkuk_id')->nullable()->constrained('apkuks')->restrictOnDelete();
            $table->string('legacy_key', 64)->comment('MISSING: identitas yang hilang; IDENTITY_CHANGE: identitas baru yang ditahan');
            $table->string('source_id')->nullable();
            $table->string('no_tiang')->nullable();
            $table->json('source_record')->nullable()->comment('IDENTITY_CHANGE: baris sumber yang ditahan');
            $table->json('candidate_apkuk_ids')->nullable()->comment('IDENTITY_CHANGE: laporan hilang dengan ID AppSheet sama');
            $table->foreignId('first_seen_run_id')->nullable()->constrained('apkuk_sync_runs')->nullOnDelete();
            $table->foreignId('last_seen_run_id')->nullable()->constrained('apkuk_sync_runs')->nullOnDelete();
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->unsignedInteger('detection_count')->default(1);
            $table->string('resolution', 32)->nullable()->comment('RETURNED | SUPERSEDED | CONFIRMED_REMOVED | IDENTITY_CONFIRMED | INSERTED_AS_NEW | RECONCILED');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->restrictOnDelete()
                ->comment('Admin terautentikasi yang memutuskan; null untuk penyelesaian otomatis/rekonsiliasi CLI');
            $table->text('resolution_reason')->nullable();
            $table->json('resolution_details')->nullable();
            $table->foreignId('resolved_run_id')->nullable()->constrained('apkuk_sync_runs')->nullOnDelete();
            $table->foreignId('reconciliation_id')->nullable()->constrained('apkuk_reconciliations')->nullOnDelete();
            $table->timestamps();
            $table->index(['apkuk_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apkuk_reconciliation_items');
        Schema::dropIfExists('apkuk_sync_runs');
    }
};
