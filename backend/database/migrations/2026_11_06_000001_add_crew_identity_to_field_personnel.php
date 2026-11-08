<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Satu akun POSKO tetap terikat ke satu field_team induk; delapan petugas
        // di dalam tim induk dikelompokkan menjadi empat pasangan (crew 1..4).
        Schema::table('field_personnel', function (Blueprint $table): void {
            $table->unsignedTinyInteger('crew_number')->nullable();
            $table->string('nickname')->nullable();
            $table->index(['field_team_id', 'crew_number'], 'field_personnel_crew_lookup_idx');
        });

        // Untuk akun INSPEKSI/HARSUS pribadi: satu akun boleh ditautkan ke satu
        // petugas, tanpa mengubah field_team_id yang tetap dipakai untuk cakupan akses.
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignUuid('field_personnel_id')->nullable()->unique()
                ->constrained('field_personnel')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('field_personnel_id');
        });

        Schema::table('field_personnel', function (Blueprint $table): void {
            $table->dropIndex('field_personnel_crew_lookup_idx');
            $table->dropColumn(['crew_number', 'nickname']);
        });
    }
};
