<?php

namespace App\Services\Apkuk;

/**
 * Hasil apkuk:sync yang berhasil diterapkan.
 */
final class ApkukSyncOutcome
{
    /**
     * @param  array<string, mixed>  $limits
     */
    public function __construct(
        public readonly ApkukSyncPlan $plan,
        public readonly array $limits,
        public readonly string $status,
    ) {}
}
