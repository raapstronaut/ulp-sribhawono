<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Generic WO header: POSKO/PEMASANGAN_APKUK now, HARSUS and ROW later.
        Schema::create('work_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('team_type', 24); // POSKO | HARSUS | ROW
            $table->string('work_type', 40); // PEMASANGAN_APKUK | PEKERJAAN_KHUSUS | RABAS | PANGKAS | TEBANG
            $table->string('source', 24); // ADMIN_MANUAL | SYSTEM | ESCALATION
            $table->string('status', 32);
            $table->foreignUuid('posko_id')->nullable()->constrained('poskos')->restrictOnDelete();
            $table->foreignUuid('field_team_id')->nullable()->constrained('field_teams')->restrictOnDelete();
            $table->foreignUuid('penyulang_id')->nullable()->constrained('penyulangs')->restrictOnDelete();
            // Canonical sections do not exist yet; keep the source text instead of guessing a link.
            $table->string('section_name')->nullable();
            $table->date('work_date');
            $table->string('shift_code', 1)->nullable();
            $table->timestamp('scheduled_start_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->uuid('parent_work_order_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['team_type', 'work_type', 'status', 'due_at'], 'work_orders_type_status_due_idx');
            $table->index(['posko_id', 'work_date', 'shift_code'], 'work_orders_posko_shift_idx');
            $table->index('penyulang_id');
        });

        // Self reference (escalation child → source) must follow the primary key on PostgreSQL.
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->foreign('parent_work_order_id')->references('id')->on('work_orders')->restrictOnDelete();
        });

        // APKUK target snapshot. apkuks rows are synced history and are only referenced, never modified.
        Schema::create('work_order_apkuk_targets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_order_id')->unique()->constrained('work_orders')->restrictOnDelete();
            $table->foreignId('apkuk_id')->constrained('apkuks')->restrictOnDelete();
            // Normalized pole identity: PENYULANG|NO_TIANG.
            $table->string('target_key', 191)->index();
            // Filled only while the WO is active; NULLs are not unique, so this blocks
            // a second active WO for the same pole at database level.
            $table->string('active_target_key', 191)->nullable()->unique();
            $table->string('no_tiang');
            $table->string('penyulang_source')->nullable();
            $table->string('section_source')->nullable();
            $table->string('naik_dari')->nullable();
            $table->date('source_report_date')->nullable();
            $table->string('source_status')->nullable();
            $table->integer('source_terpasang_bh')->nullable();
            $table->integer('source_kurang_bh');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('target_quantity');
            $table->unsignedInteger('installed_quantity')->nullable();
            $table->unsignedInteger('remaining_quantity')->nullable();
            $table->timestamps();

            $table->index('apkuk_id');
        });

        // Append-only audit trail of every WO mutation.
        Schema::create('work_order_status_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('work_order_id')->constrained('work_orders')->restrictOnDelete();
            $table->string('action', 40);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('actor_field_team_id')->nullable()->constrained('field_teams')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['work_order_id', 'created_at']);
        });

        Schema::create('work_order_reschedules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_order_id')->constrained('work_orders')->restrictOnDelete();
            $table->foreignUuid('requested_by_field_team_id')->nullable()->constrained('field_teams')->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reason_code', 80);
            $table->text('note'); // Mandatory manual note.
            $table->date('from_work_date');
            $table->string('from_shift_code', 1)->nullable();
            $table->date('proposed_work_date');
            $table->string('proposed_shift_code', 1)->nullable();
            $table->string('status', 24); // MENUNGGU_ACC | DISETUJUI | DITOLAK
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->string('client_request_id', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['work_order_id', 'status']);
        });

        // Field submissions: installation done (no ACC) or "already complete" finding (ACC required).
        Schema::create('work_order_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_order_id')->constrained('work_orders')->restrictOnDelete();
            $table->string('report_type', 40); // PEMASANGAN_SELESAI | TEMUAN_SUDAH_LENGKAP
            $table->string('verification_status', 24); // TIDAK_PERLU | MENUNGGU_VERIFIKASI | DISETUJUI | DITOLAK
            $table->unsignedInteger('installed_quantity')->nullable();
            $table->unsignedInteger('remaining_quantity')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('gps_accuracy_m', 8, 2)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignUuid('submitted_by_field_team_id')->nullable()->constrained('field_teams')->restrictOnDelete();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_note')->nullable();
            $table->string('client_request_id', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['work_order_id', 'verification_status']);
        });

        // Actual personnel (people, not login accounts). Name is snapshotted because the roster is incomplete.
        Schema::create('work_order_report_personnel', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('work_order_report_id')->constrained('work_order_reports')->restrictOnDelete();
            $table->foreignUuid('field_personnel_id')->nullable()->constrained('field_personnel')->restrictOnDelete();
            $table->string('personnel_name');
            $table->timestamps();

            $table->index('work_order_report_id');
        });

        // Evidence photos are never overwritten; every file is its own row.
        Schema::create('work_order_media', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_order_id')->constrained('work_orders')->restrictOnDelete();
            $table->foreignUuid('work_order_report_id')->nullable()->constrained('work_order_reports')->restrictOnDelete();
            $table->string('photo_type', 24); // BEFORE | AFTER
            $table->string('capture_source', 16); // CAMERA | GALLERY
            $table->string('disk', 32);
            $table->string('storage_path', 512)->unique();
            $table->string('sha256', 64)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('gps_accuracy_m', 8, 2)->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->index(['work_order_id', 'photo_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_media');
        Schema::dropIfExists('work_order_report_personnel');
        Schema::dropIfExists('work_order_reports');
        Schema::dropIfExists('work_order_reschedules');
        Schema::dropIfExists('work_order_status_logs');
        Schema::dropIfExists('work_order_apkuk_targets');
        Schema::dropIfExists('work_orders');
    }
};
