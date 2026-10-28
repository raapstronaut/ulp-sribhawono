<?php

namespace App\Services\Apkuk;

use RuntimeException;

/**
 * Snapshot sumber melanggar pengaman ApkukSyncPolicy; transaksi sync di-rollback.
 */
class ApkukSnapshotRejected extends RuntimeException
{
    /**
     * @param  array<int, string>  $violations
     * @param  array<string, mixed>  $limits
     */
    public function __construct(
        public readonly ApkukSyncPlan $plan,
        public readonly array $violations,
        public readonly array $limits,
    ) {
        parent::__construct($violations[0] ?? 'Snapshot ditolak pengaman sinkronisasi.');
    }
}
