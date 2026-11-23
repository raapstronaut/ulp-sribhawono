<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Penugasan eksplisit Admin. BUKAN WO, BUKAN jatah kuota/shift.
        Schema::create('row_inspection_plans', function (Blueprint $table): void {
            $table->foreignUuid('inspection_finding_id')->constrained('inspection_findings')->restrictOnDelete();
            $table->primary('inspection_finding_id');
            $table->foreignUuid('field_team_id')->constrained('field_teams')->restrictOnDelete();
            $table->string('work_type', 32); // RABAS | PANGKAS | TEBANG
            $table->foreignId('planned_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['field_team_id', 'work_type'], 'row_plans_team_type_idx');
        });

        // Append-only audit untuk perubahan klasifikasi dan tim penerima.
        Schema::create('row_inspection_plan_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('inspection_finding_id')->constrained('inspection_findings')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('previous_field_team_id')->nullable()->constrained('field_teams')->restrictOnDelete();
            $table->foreignUuid('field_team_id')->constrained('field_teams')->restrictOnDelete();
            $table->string('previous_work_type', 32)->nullable();
            $table->string('work_type', 32);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['inspection_finding_id', 'created_at'], 'row_plan_logs_finding_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('row_inspection_plan_logs');
        Schema::dropIfExists('row_inspection_plans');
    }
};
