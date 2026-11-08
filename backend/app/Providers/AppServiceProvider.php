<?php

namespace App\Providers;

use App\Services\Apkuk\Generation\BaselineCandidateRanking;
use App\Services\Apkuk\Generation\CandidateRanking;
use App\Services\Pasila\P0CandidateStrategy;
use App\Services\Pasila\PasilaSectionStrategy;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Strategi peringkat kandidat WO APKUK. Baseline rule-based sampai model ML terlatih tersedia.
        $this->app->bind(CandidateRanking::class, BaselineCandidateRanking::class);

        // Strategi rekomendasi Section PASILA, dipilih lewat konfigurasi (baseline rotasi).
        $this->app->bind(PasilaSectionStrategy::class, fn ($app) => $app->make(config('pasila.section_strategy')));

        // Strategi usulan P0 SYSTEM; default menandai belum siap (tanpa usulan otomatis).
        $this->app->bind(P0CandidateStrategy::class, fn ($app) => $app->make(config('pasila.p0_strategy')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
