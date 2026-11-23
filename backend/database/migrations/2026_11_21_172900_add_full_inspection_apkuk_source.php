<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only: inspeksi lama tetap UMUM, temuan lama bukan kandidat APKUK otomatis.
 * Tidak menjalankan generator atau membuat WO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspections', function (Blueprint $table): void {
            $table->string('inspection_type', 16)->default('UMUM')->index();
        });

        Schema::table('inspection_findings', function (Blueprint $table): void {
            $table->string('apkuk_no_tiang', 191)->nullable();
            $table->unsignedInteger('apkuk_target_quantity')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inspection_findings', function (Blueprint $table): void {
            $table->dropColumn(['apkuk_no_tiang', 'apkuk_target_quantity']);
        });
        Schema::table('inspections', function (Blueprint $table): void {
            $table->dropColumn('inspection_type');
        });
    }
};
