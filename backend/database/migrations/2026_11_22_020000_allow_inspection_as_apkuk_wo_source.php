<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v4: inspectie FULL may be the APKUK WO source without a fake SPS apkuk_id.
 * Historical SPS-based targets remain intact. No legacy rows are rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_apkuk_targets', function (Blueprint $table): void {
            // Existing SPS rows retain the original numeric ID; inspection targets use NULL.
            $table->foreignId('apkuk_id')->nullable()->change();
            $table->foreignUuid('inspection_finding_id')->nullable()->unique()
                ->constrained('inspection_findings')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Deliberately fail closed instead of deleting any real inspection-based WO target.
        if (DB::table('work_order_apkuk_targets')->whereNotNull('inspection_finding_id')->exists()
            || DB::table('work_order_apkuk_targets')->whereNull('apkuk_id')->exists()) {
            throw new RuntimeException('Rollback ditolak: sudah ada target WO dari INSPEKSI. Data tidak boleh hilang.');
        }

        Schema::table('work_order_apkuk_targets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('inspection_finding_id');
            $table->foreignId('apkuk_id')->nullable(false)->change();
        });
    }
};
