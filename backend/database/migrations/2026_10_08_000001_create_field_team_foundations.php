<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Account identity is separate from the personnel who perform a task.
        Schema::create('field_teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('role', 24); // POSKO | HARSUS | INSPEKSI | ROW
            $table->foreignUuid('posko_id')->nullable()->constrained('poskos')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['role', 'is_active']);
        });

        Schema::create('field_personnel', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('field_team_id')->constrained('field_teams')->restrictOnDelete();
            $table->string('personnel_code')->nullable()->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['field_team_id', 'is_active']);
        });

        // Shift assignments are time-bound: personnel stay on their team,
        // while the Posko team may rotate between A, B and C.
        Schema::create('field_team_shift_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('field_team_id')->constrained('field_teams')->restrictOnDelete();
            $table->string('shift_code', 1); // A, B, C; validate through application services
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->timestamps();
            $table->index(['field_team_id', 'effective_from', 'effective_until'], 'field_team_shift_period_idx');
        });

        // Central reference choices for mobile forms, not hardcoded Dart options.
        Schema::create('reference_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('category', 80);
            $table->string('code', 80);
            $table->string('label');
            $table->string('role_scope', 24)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['category', 'code']);
            $table->index(['category', 'role_scope', 'is_active'], 'reference_codes_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_codes');
        Schema::dropIfExists('field_team_shift_assignments');
        Schema::dropIfExists('field_personnel');
        Schema::dropIfExists('field_teams');
    }
};
