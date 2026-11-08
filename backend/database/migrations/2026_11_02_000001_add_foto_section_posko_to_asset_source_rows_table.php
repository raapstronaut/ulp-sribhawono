<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom ASSET_RAW H–J, nilai mentah sumber. Section dan Posko di sini belum terverifikasi dan
     * bukan referensi kepemilikan: kepemilikan Section → Posko hanya dari SECTION_PIC_RAW (SectionPoskoMap).
     */
    public function up(): void
    {
        Schema::table('asset_source_rows', function (Blueprint $table) {
            $table->text('foto_raw')->nullable();
            $table->string('section_raw')->nullable();
            $table->string('posko_raw')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('asset_source_rows', function (Blueprint $table) {
            $table->dropColumn(['foto_raw', 'section_raw', 'posko_raw']);
        });
    }
};
