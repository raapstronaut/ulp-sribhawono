<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generator WO APKUK per Posko per shift (Batch B2), additive:
 * - apkuk_generation_runs: setiap eksekusi pratinjau (PREVIEW, tanpa WO) maupun generate,
 *   termasuk yang ditolak pengaman aktivasi; ringkasan per Posko dan kandidat yang ditahan.
 * - apkuk_shift_allocations: satu baris per shift + Posko yang sudah digenerate (unik), sehingga
 *   eksekusi ulang/paralel tidak menambah WO di atas kuota.
 * - work_orders.generation_run_id: provenance WO buatan generator (null untuk WO lain).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apkuk_generation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shift_instance_id')->constrained('shift_instances')->restrictOnDelete();
            $table->string('mode', 16); // PREVIEW | GENERATE
            $table->string('status', 16); // COMPLETED | REFUSED | FAILED
            $table->string('trigger', 16); // ADMIN_WEB | CLI | SCHEDULER
            $table->string('strategy', 64);
            $table->string('strategy_version', 32);
            $table->boolean('is_machine_learning')->default(false);
            $table->string('app_environment', 32);
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('summary')->nullable();
            $table->json('held')->nullable();
            $table->json('breakdown')->nullable(); // rincian kategori laporan APKUK (CandidateBreakdown)
            $table->text('message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['shift_instance_id', 'mode']);
        });

        Schema::create('apkuk_shift_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shift_instance_id')->constrained('shift_instances')->restrictOnDelete();
            $table->foreignUuid('posko_id')->constrained('poskos')->restrictOnDelete();
            $table->foreignId('generation_run_id')->constrained('apkuk_generation_runs')->restrictOnDelete();
            $table->unsignedSmallInteger('target_count');
            $table->unsignedSmallInteger('carry_over_count');
            $table->unsignedSmallInteger('existing_count');
            $table->unsignedSmallInteger('generated_count');
            $table->unsignedSmallInteger('total_count');
            $table->unsignedSmallInteger('shortfall_count');
            $table->unsignedSmallInteger('overload_count');
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['shift_instance_id', 'posko_id']);
        });

        Schema::table('work_orders', function (Blueprint $table): void {
            $table->foreignId('generation_run_id')->nullable()->constrained('apkuk_generation_runs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('generation_run_id');
        });
        Schema::dropIfExists('apkuk_shift_allocations');
        Schema::dropIfExists('apkuk_generation_runs');
    }
};
