<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keypoints', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_key', 64)->nullable()->unique();
            $table->string('penyulang')->nullable()->index();
            $table->string('jenis')->nullable()->index();
            $table->string('nama')->nullable()->index();
            $table->text('koordinat_raw')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('note')->nullable();
            $table->string('sumber_input', 40)->default('SPS_BOS')->index();
            $table->unsignedInteger('source_row_number')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keypoints');
    }
};
