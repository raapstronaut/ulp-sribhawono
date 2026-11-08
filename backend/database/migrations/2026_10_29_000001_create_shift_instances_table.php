<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identitas stabil satu shift dalam hari operasional (keputusan pengguna 29 Okt 2026):
 * hari operasional D = 08:00 WIB D sampai 08:00 WIB D+1; Shift C setelah tengah malam ber-tanggal D.
 * starts_at/ends_at disimpan UTC sebagai interval [start, end). Unik per tanggal operasional + kode,
 * sehingga scheduler yang dipanggil ulang tidak membuat shift ganda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_instances', function (Blueprint $table): void {
            $table->id();
            $table->date('operational_date');
            $table->string('shift_code', 1);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('timezone', 40);
            $table->timestamps();

            $table->unique(['operational_date', 'shift_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_instances');
    }
};
