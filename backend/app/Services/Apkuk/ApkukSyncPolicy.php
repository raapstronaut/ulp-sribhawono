<?php

namespace App\Services\Apkuk;

use App\Models\ApkukSyncRun;

/**
 * Pengaman snapshot sebelum apkuk:sync menulis. Pelanggaran apa pun membatalkan seluruh
 * penulisan APKUK dari snapshot tersebut (status FAILED, exit non-zero).
 *
 * Pembanding jumlah laporan adalah snapshot valid terakhir (apkuk_sync_runs). Sebelum ada
 * riwayat, dipakai jumlah laporan aktif di apkuks. Batas default di config/apkuk.php;
 * opsi --max-* hanya untuk eksekusi manual dan tercatat di apkuk_sync_runs.guard.
 */
final class ApkukSyncPolicy
{
    /**
     * @param  array<string, int|float>  $config  config('apkuk.sync')
     */
    public function __construct(
        private readonly array $config,
        private readonly ?int $maxMissingOverride = null,
        private readonly ?int $maxNewOverride = null,
        private readonly ?int $maxUpdatedOverride = null,
    ) {}

    public static function fromConfig(?int $maxMissing = null, ?int $maxNew = null, ?int $maxUpdated = null): self
    {
        return new self((array) config('apkuk.sync'), $maxMissing, $maxNew, $maxUpdated);
    }

    /**
     * Jumlah laporan snapshot valid terakhir; sebelum ada riwayat, laporan aktif di apkuks.
     */
    public static function baseline(ApkukSyncPlan $plan): ?int
    {
        $last = ApkukSyncRun::lastAccepted();
        if ($last !== null) {
            return $last->source_report_count;
        }

        return $plan->activeBefore > 0 ? $plan->activeBefore : null;
    }

    /**
     * @return array{baseline: ?int, max_drop: ?int, max_new_missing: int, max_new: ?int, max_updated: int, overrides: array<string, int>}
     */
    public function limits(ApkukSyncPlan $plan, ?int $baseline): array
    {
        $maxMissing = $this->maxMissingOverride ?? min(
            (int) $this->config['max_new_missing'],
            max(1, (int) ceil($plan->activeBefore * (float) $this->config['max_new_missing_ratio'])),
        );

        return [
            'baseline' => $baseline,
            // Laporan yang boleh berkurang dibanding snapshot terakhir; opsi --max-missing
            // yang disetujui untuk penghapusan besar ikut melonggarkan batas ini.
            'max_drop' => $baseline === null ? null : max(
                (int) ceil($baseline * (float) $this->config['max_drop_ratio']),
                $this->maxMissingOverride ?? 0,
            ),
            'max_new_missing' => $maxMissing,
            // Sinkronisasi pertama (apkuks masih kosong) tidak dibatasi.
            'max_new' => $this->maxNewOverride ?? ($baseline === null || $baseline === 0 ? null : max(
                (int) $this->config['max_new_reports'],
                (int) ceil($baseline * (float) $this->config['max_growth_ratio']),
            )),
            'max_updated' => $this->maxUpdatedOverride ?? max(
                (int) $this->config['max_updated'],
                (int) ceil($plan->matched() * (float) $this->config['max_updated_ratio']),
            ),
            'overrides' => array_filter([
                'max_missing' => $this->maxMissingOverride,
                'max_new' => $this->maxNewOverride,
                'max_updated' => $this->maxUpdatedOverride,
            ], fn (?int $value) => $value !== null),
        ];
    }

    /**
     * @return array<int, string> pesan pelanggaran, kosong bila snapshot aman diterapkan
     */
    public function violations(ApkukSyncPlan $plan, ?int $baseline): array
    {
        $limits = $this->limits($plan, $baseline);
        $violations = [];

        if ($limits['max_drop'] !== null && $baseline - $plan->sourceReports > $limits['max_drop']) {
            $violations[] = sprintf(
                'Jumlah laporan sumber turun dari %d (snapshot valid terakhir) menjadi %d, melebihi batas penurunan %d. Kemungkinan Google Sheets terpotong/belum lengkap.',
                $baseline, $plan->sourceReports, $limits['max_drop'],
            );
        }

        $newlyMissing = count($plan->newlyMissingIds);
        if ($newlyMissing > $limits['max_new_missing']) {
            $violations[] = sprintf(
                '%d identitas aktif hilang dari sumber sekaligus, melebihi batas %d per eksekusi. Kemungkinan sumber terpotong atau kolom ID/tiang berubah.',
                $newlyMissing, $limits['max_new_missing'],
            );
        }

        $added = count($plan->inserts) + count($plan->returned);
        if ($limits['max_new'] !== null && $added > $limits['max_new']) {
            $violations[] = sprintf(
                '%d laporan baru/muncul kembali sekaligus, melebihi batas %d per eksekusi. Kemungkinan data sumber terduplikasi.',
                $added, $limits['max_new'],
            );
        }

        if (count($plan->updates) > $limits['max_updated']) {
            $violations[] = sprintf(
                '%d laporan berubah isi sekaligus, melebihi batas %d per eksekusi. Kemungkinan kolom sumber bergeser atau rumus rusak.',
                count($plan->updates), $limits['max_updated'],
            );
        }

        return $violations;
    }
}
