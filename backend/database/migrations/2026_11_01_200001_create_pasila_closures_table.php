<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penutupan sesi PASILA oleh tim POSKO (Batch B3.4), additive.
 *
 * Satu penutupan per sesi (unik). Penutupan mensyaratkan laporan patroli yang sudah diterima server
 * (temuan atau laporan nihil aktif) dan tidak menunggu target P0 maupun reschedule WO APKUK.
 * snapshot mencatat keadaan saat ditutup (patroli, P0, WO APKUK) untuk audit; status terkini tetap
 * dihitung dari record asli. Temuan sah yang tiba setelah penutupan tetap diterima dan ditandai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pasila_closures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('pasila_session_id')->unique()->constrained('pasila_sessions')->restrictOnDelete();
            $table->string('outcome', 16); // ADA_TEMUAN | NIHIL
            $table->unsignedInteger('finding_count');
            $table->foreignUuid('pasila_nil_report_id')->nullable()->constrained('pasila_nil_reports')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('gps_accuracy_m', 8, 2)->nullable();
            $table->json('personnel'); // petugas roster saat menutup: [{id, name}]
            $table->json('snapshot'); // keadaan saat ditutup: patroli, P0, WO APKUK
            $table->timestamp('occurred_at');
            $table->timestamp('received_at');
            $table->foreignUuid('submitted_by_field_team_id')->constrained('field_teams')->restrictOnDelete();
            $table->foreignId('submitted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('client_request_id', 64)->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pasila_closures');
    }
};
