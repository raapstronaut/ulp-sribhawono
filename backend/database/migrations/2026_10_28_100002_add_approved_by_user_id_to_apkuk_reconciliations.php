<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pengarsipan massal lewat apkuk:reconcile --apply kini wajib Admin terautentikasi;
        // approved_by tetap berisi konfirmasi pemilik data (teks), akun Admin dicatat di sini.
        Schema::table('apkuk_reconciliations', function (Blueprint $table): void {
            $table->foreignId('approved_by_user_id')->nullable()
                ->comment('Admin aktif yang terautentikasi (username + kata sandi) saat apply')
                ->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('apkuk_reconciliations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by_user_id');
        });
    }
};
