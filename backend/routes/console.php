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

// =====================================
// GENERATOR WO APKUK PER SHIFT (Batch B2)
// Hanya terdaftar bila APKUK_GENERATOR_ENABLED=true (default false). Di luar APP_ENV
// local/testing setiap eksekusi tetap ditolak ApkukGeneratorGuard (fail-closed) sampai model ML
// dan prosedur readiness tersedia. Railway saat ini hanya menjalankan railway-sync.sh, bukan schedule:run.
// =====================================
if (config('apkuk.generator.enabled')) {
    Schedule::command('apkuk:generate-shift --current --generate --trigger=SCHEDULER')
        ->cron('5 0,8,16 * * *')
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(30)
        ->appendOutputTo(storage_path('logs/apkuk-generator.log'));
}
