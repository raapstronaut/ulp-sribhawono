<?php

namespace App\Providers;

use App\Services\Apkuk\Generation\BaselineCandidateRanking;
use App\Services\Apkuk\Generation\CandidateRanking;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
