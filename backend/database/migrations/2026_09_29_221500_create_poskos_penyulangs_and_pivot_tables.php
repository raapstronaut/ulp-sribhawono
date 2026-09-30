<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poskos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kode_posko')->unique();
            $table->string('nama_posko');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('penyulangs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kode_penyulang')->unique();
            $table->string('nama_penyulang');
            $table->string('gardu_induk')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('posko_penyulang', function (Blueprint $table) {
            $table->foreignUuid('posko_id')->constrained('poskos')->cascadeOnDelete();
            $table->foreignUuid('penyulang_id')->constrained('penyulangs')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['posko_id', 'penyulang_id']);
            $table->index('posko_id');
            $table->index('penyulang_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posko_penyulang');
        Schema::dropIfExists('penyulangs');
        Schema::dropIfExists('poskos');
    }
};
