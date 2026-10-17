<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// =====================================
// GANGGUAN PLN PUSAT
// Auto-sync permanen setiap 15 menit
// =====================================
Schedule::command('gangguan:sync')
    ->everyFifteenMinutes()
    ->withoutOverlapping(30)
    ->appendOutputTo(
        storage_path('logs/gangguan-sync.log')
    );

// =====================================
// APKUK APPSHEET LAMA
// Auto-sync sementara sebelum go-live
// =====================================
Schedule::command('apkuk:sync')
    ->cron('5,20,35,50 * * * *')
    ->withoutOverlapping(30)
    ->appendOutputTo(
        storage_path('logs/apkuk-sync.log')
    );
 // KEYPOINT SPS BOS
 // Sinkronisasi setiap 15 menit sebelum go-live
 Schedule::command('keypoint:sync')
     ->cron('10,25,40,55 * * * *')
     ->withoutOverlapping(30)
     ->appendOutputTo(
         storage_path('logs/keypoint-sync.log')
     );
