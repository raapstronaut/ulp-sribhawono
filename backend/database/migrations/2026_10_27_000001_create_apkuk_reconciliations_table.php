<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jejak append-only setiap rekonsiliasi APKUK yang disetujui. Baris apkuks tidak pernah
        // dihapus; rekonsiliasi hanya mengisi source_missing_at dan tercatat di sini.
        Schema::create('apkuk_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->string('plan_id', 32)->index();
            $table->string('fingerprint', 64)->comment('Sidik persetujuan lengkap (sha256); plan_id = 16 karakter pertama');
            $table->json('fingerprint_parts')->comment('Sidik per komponen: sumber, identitas hilang, apkuks, referensi WO, dampak');
            $table->string('action', 32); // MARK_SOURCE_MISSING
            $table->string('approved_by', 120)->comment('Diisi operator lewat --approved-by; metadata audit, tidak diverifikasi');
            $table->text('reason');
            $table->string('database_user', 120)->nullable()->comment('Role database yang menjalankan apply (current_user)');
            $table->unsignedInteger('marked_count');
            $table->unsignedInteger('source_report_count');
            $table->timestamp('marked_at');
            $table->json('apkuk_ids');
            $table->json('legacy_keys');
            $table->json('impact')->comment('Ringkasan dampak langsung (target WO) dan tidak langsung (kandidat WO, peta)');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apkuk_reconciliations');
    }
};
