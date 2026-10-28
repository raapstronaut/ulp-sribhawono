<?php

namespace App\Services\Apkuk;

use Illuminate\Support\Facades\DB;

/**
 * Kunci transaksi PostgreSQL untuk penulis data APKUK.
 *
 * - exclusive(): apkuk:sync, apkuk:reconcile --apply, dan penyelesaian antrean oleh Admin.
 *   Hanya satu yang berjalan; cron yang tumpang tindih menunggu, bukan menulis ganda.
 * - shared(): pembuatan WO APKUK. Banyak WO boleh dibuat bersamaan, tetapi menunggu
 *   sinkronisasi yang sedang menulis, sehingga penilaian kandidat selalu membaca antrean
 *   rekonsiliasi yang sudah di-commit.
 *
 * Kunci dilepas otomatis saat commit/rollback. Bila tidak didapat dalam LOCK_TIMEOUT,
 * transaksi gagal tanpa perubahan. SQLite (test) tidak mengenal kunci ini; transaksi
 * SQLite sendiri sudah serial.
 */
final class ApkukSyncLock
{
    /** Kunci advisory tetap (bigint) khusus data APKUK. */
    public const KEY = 7_202_610_280_001;

    public const LOCK_TIMEOUT = '10s';

    public static function exclusive(): void
    {
        self::acquire('pg_advisory_xact_lock');
    }

    public static function shared(): void
    {
        self::acquire('pg_advisory_xact_lock_shared');
    }

    private static function acquire(string $function): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SET LOCAL lock_timeout = '".self::LOCK_TIMEOUT."'");
        DB::select("select {$function}(?)", [self::KEY]);
    }
}
