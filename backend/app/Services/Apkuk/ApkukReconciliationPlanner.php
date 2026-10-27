<?php

namespace App\Services\Apkuk;

use App\Enums\WorkOrderStatus;
use App\Services\ApkukCandidate;
use App\Services\ApkukCandidateService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Membandingkan snapshot APKUK_RAW dengan tabel apkuks tanpa menulis apa pun.
 *
 * Di PostgreSQL seluruh pembacaan dijalankan dalam transaksi READ ONLY lalu
 * di-rollback, sehingga dry-run secara teknis tidak dapat mengubah data. Bila sudah
 * berada di dalam transaksi (validasi ulang saat apply), pembacaan memakai transaksi itu.
 */
class ApkukReconciliationPlanner
{
    public function __construct(private readonly ApkukCandidateService $candidates) {}

    public function plan(ApkukSourceSnapshot $source): ApkukReconciliationPlan
    {
        return $this->readOnly(function () use ($source): ApkukReconciliationPlan {
            $rows = DB::table('apkuks')
                ->where('sumber_input', ApkukSourceReader::SOURCE)
                ->whereNotNull('legacy_key')
                ->orderBy('id')
                ->get(['id', 'source_id', 'legacy_key', 'no_tiang', 'tanggal', 'penyulang', 'status', 'source_missing_at']);

            $sourceIdPoles = [];
            $sourcePoles = [];
            foreach ($source->records as $record) {
                $normalizedPole = ApkukSourceReader::normalizePole($record['no_tiang']);
                $sourceIdPoles[ApkukSourceReader::normalizeId($record['source_id'])][$normalizedPole] = true;
                $sourcePoles[$normalizedPole] = true;
            }

            $knownKeys = [];
            $missing = [];
            $returning = [];
            $active = 0;
            $marked = 0;
            foreach ($rows as $row) {
                $knownKeys[$row->legacy_key] = true;
                $inSource = $source->hasKey($row->legacy_key);

                if ($row->source_missing_at !== null) {
                    $marked++;
                    if ($inSource) {
                        $returning[] = ['apkuk_id' => (int) $row->id, 'source_id' => $row->source_id, 'no_tiang' => $row->no_tiang];
                    }

                    continue;
                }

                $active++;
                if (! $inSource) {
                    $missing[] = [
                        'apkuk_id' => (int) $row->id,
                        'source_id' => $row->source_id,
                        'no_tiang' => $row->no_tiang,
                        'tanggal' => $row->tanggal === null ? null : substr((string) $row->tanggal, 0, 10),
                        'penyulang' => $row->penyulang,
                        'status' => $row->status,
                        'legacy_key' => $row->legacy_key,
                        'category' => $this->categorize($row, $sourceIdPoles, $sourcePoles),
                    ];
                }
            }
            unset($rows);

            $new = [];
            foreach ($source->records as $record) {
                if (! isset($knownKeys[$record['legacy_key']])) {
                    $new[] = ['source_id' => $record['source_id'], 'no_tiang' => $record['no_tiang'], 'sheet_row' => $record['source_row_number']];
                }
            }

            $missingIds = array_column($missing, 'apkuk_id');
            $workOrderRefs = $this->workOrderRefs($missingIds);
            $impact = $this->downstreamImpact($missingIds);
            $eligibleRemoved = array_flip(array_column($impact['eligible_removed'], 'apkuk_id'));
            foreach ($missing as $index => $row) {
                $missing[$index]['eligible_candidate'] = isset($eligibleRemoved[$row['apkuk_id']]);
            }

            return new ApkukReconciliationPlan(
                fingerprintParts: [
                    'source' => $source->digest(),
                    'missing' => ApkukReconciliationPlan::missingDigest($missing),
                    'database' => $this->databaseDigest(),
                    'work_orders' => hash('sha256', serialize($workOrderRefs)),
                    'impact' => $this->impactDigest($impact),
                ],
                sourceReports: count($source->records),
                activeIdentities: $active,
                markedIdentities: $marked,
                matched: $active - count($missing),
                missing: $missing,
                new: $new,
                returning: $returning,
                duplicatedIds: $this->duplicatedIds($source),
                possibleDuplicateReports: $this->possibleDuplicateReports($source),
                workOrderRefs: $workOrderRefs,
                impact: $impact,
            );
        });
    }

    /**
     * @param  array<string, array<string, true>>  $sourceIdPoles
     * @param  array<string, true>  $sourcePoles
     */
    private function categorize(object $row, array $sourceIdPoles, array $sourcePoles): string
    {
        $id = ApkukSourceReader::normalizeId(trim((string) $row->source_id));
        $pole = ApkukSourceReader::normalizePole(trim((string) $row->no_tiang));

        if (isset($sourceIdPoles[$id])) {
            return ApkukReconciliationPlan::CATEGORY_ID_DIPAKAI_TIANG_LAIN;
        }

        return isset($sourcePoles[$pole])
            ? ApkukReconciliationPlan::CATEGORY_TIANG_MASIH_ADA
            : ApkukReconciliationPlan::CATEGORY_TIDAK_ADA;
    }

    /**
     * ID AppSheet yang dipakai lebih dari satu laporan (mis. A150). Tidak memblokir:
     * identitas memakai ID + tiang, tetapi perlu diperiksa saat rekonsiliasi.
     *
     * @return array<string, array<int, array{no_tiang: string, sheet_row: int}>>
     */
    private function duplicatedIds(ApkukSourceSnapshot $source): array
    {
        $duplicated = $source->duplicatedSourceIds();
        $result = [];
        foreach ($source->records as $record) {
            $id = ApkukSourceReader::normalizeId($record['source_id']);
            if (isset($duplicated[$id])) {
                $result[$record['source_id']][] = ['no_tiang' => $record['no_tiang'], 'sheet_row' => $record['source_row_number']];
            }
        }

        return $result;
    }

    /**
     * Laporan berbeda ID tetapi tiang, tanggal, petugas, jumlah, dan status sama:
     * kemungkinan input ganda di sumber. Informasi saja, tidak mengubah identitas.
     *
     * @return array<int, array<int, array{source_id: string, sheet_row: int}>>
     */
    private function possibleDuplicateReports(ApkukSourceSnapshot $source): array
    {
        $groups = [];
        foreach ($source->records as $record) {
            $signature = implode('|', [
                ApkukSourceReader::normalizePole($record['no_tiang']),
                $record['tanggal'],
                mb_strtoupper((string) $record['petugas']),
                (string) $record['terpasang_bh'],
                (string) $record['kurang_bh'],
                mb_strtoupper((string) $record['status']),
            ]);
            $groups[$signature][] = ['source_id' => $record['source_id'], 'sheet_row' => $record['source_row_number']];
        }

        return array_values(array_filter($groups, fn (array $group) => count($group) > 1));
    }

    /**
     * Dampak langsung: target WO yang merujuk baris yang akan ditandai.
     *
     * @param  array<int, int>  $apkukIds
     * @return array<int, array{target_id: string, work_order_id: string, apkuk_id: int, code: string, status: string, active: bool, active_target_key: ?string}>
     */
    private function workOrderRefs(array $apkukIds): array
    {
        $refs = [];
        foreach (array_chunk($apkukIds, 1000) as $chunk) {
            $rows = DB::table('work_order_apkuk_targets as target')
                ->join('work_orders as wo', 'wo.id', '=', 'target.work_order_id')
                ->whereIn('target.apkuk_id', $chunk)
                ->orderBy('wo.code')
                ->orderBy('target.id')
                ->get(['target.id as target_id', 'target.work_order_id', 'target.apkuk_id', 'target.active_target_key', 'wo.code', 'wo.status']);

            foreach ($rows as $row) {
                $refs[] = [
                    'target_id' => (string) $row->target_id,
                    'work_order_id' => (string) $row->work_order_id,
                    'apkuk_id' => (int) $row->apkuk_id,
                    'code' => $row->code,
                    'status' => $row->status,
                    'active' => WorkOrderStatus::tryFrom($row->status)?->isActive() ?? true,
                    'active_target_key' => $row->active_target_key,
                ];
            }
        }

        return $refs;
    }

    /**
     * Dampak tidak langsung, dihitung dengan simulasi tanpa menulis. Kandidat WO APKUK dan
     * Peta Jaringan hanya membaca apkuks yang belum bertanda, sehingga penandaan tidak
     * mengubah WO, tetapi laporan yang ditandai keluar dari daftar kandidat dan peta, dan
     * laporan lama di tiang yang sama dapat menjadi laporan terbaru (berubah layak/tidak).
     *
     * @param  array<int, int>  $missingIds
     * @return array{candidates: array{total_before: int, total_after: int, eligible_before: int, eligible_after: int}, map: array{active_before: int, active_after: int, markers_before: int, markers_after: int}, eligible_removed: array<int, array<string, mixed>>, newly_eligible: array<int, array<string, mixed>>, no_longer_eligible: array<int, array<string, mixed>>, reason_changed: array<int, array<string, mixed>>}
     */
    private function downstreamImpact(array $missingIds): array
    {
        $before = $this->candidates->evaluate();
        $after = $missingIds === [] ? $before : $this->candidates->evaluate($missingIds);

        $describe = fn (ApkukCandidate $candidate, ?ApkukCandidate $next): array => [
            'apkuk_id' => (int) $candidate->apkuk->id,
            'source_id' => $candidate->apkuk->source_id,
            'no_tiang' => $candidate->apkuk->no_tiang,
            'kurang_bh' => $candidate->apkuk->kurang_bh,
            'issues_before' => $candidate->issues,
            'issues_after' => $next?->issues,
        ];

        $changes = ['eligible_removed' => [], 'newly_eligible' => [], 'no_longer_eligible' => [], 'reason_changed' => []];
        foreach ($before as $apkukId => $candidate) {
            $next = $after->get($apkukId);
            if ($next === null) {
                if ($candidate->isEligible()) {
                    $changes['eligible_removed'][] = $describe($candidate, null);
                }
            } elseif ($candidate->isEligible() !== $next->isEligible()) {
                $changes[$next->isEligible() ? 'newly_eligible' : 'no_longer_eligible'][] = $describe($candidate, $next);
            } elseif ($candidate->issues !== $next->issues) {
                $changes['reason_changed'][] = $describe($candidate, $next);
            }
        }

        $eligible = fn (Collection $candidates) => $candidates->filter(fn (ApkukCandidate $c) => $c->isEligible())->count();
        // Kriteria marker sama dengan MapDataController: koordinat terisi dan dalam rentang.
        $markers = fn (Collection $candidates) => $candidates->filter(fn (ApkukCandidate $c) => $c->apkuk->latitude !== null
            && $c->apkuk->longitude !== null
            && abs((float) $c->apkuk->latitude) <= 90
            && abs((float) $c->apkuk->longitude) <= 180)->count();

        return [
            'candidates' => [
                'total_before' => $before->count(),
                'total_after' => $after->count(),
                'eligible_before' => $eligible($before),
                'eligible_after' => $eligible($after),
            ],
            'map' => [
                'active_before' => $before->count(),
                'active_after' => $after->count(),
                'markers_before' => $markers($before),
                'markers_after' => $markers($after),
            ],
        ] + $changes;
    }

    /**
     * Total absolut tidak ikut dikunci: WO baru untuk tiang lain mengubah total tanpa
     * mengubah efek rekonsiliasi ini. Yang dikunci adalah laporan yang terdampak.
     *
     * @param  array<string, mixed>  $impact
     */
    private function impactDigest(array $impact): string
    {
        return hash('sha256', serialize([
            $impact['eligible_removed'],
            $impact['newly_eligible'],
            $impact['no_longer_eligible'],
            $impact['reason_changed'],
            $impact['map']['markers_before'] - $impact['map']['markers_after'],
        ]));
    }

    /**
     * Sidik seluruh isi tabel apkuks (semua kolom, urut id). Selama ada identitas hilang
     * apkuk:sync menolak menulis, sehingga sidik ini hanya berubah oleh perubahan lain:
     * penandaan manual, rekonsiliasi lain, atau sync yang berhasil karena sumber pulih.
     */
    private function databaseDigest(): string
    {
        $hash = hash_init('sha256');
        foreach (DB::table('apkuks')->orderBy('id')->cursor() as $row) {
            hash_update($hash, serialize((array) $row)."\n");
        }

        return hash_final($hash);
    }

    private function readOnly(callable $callback): mixed
    {
        if (DB::connection()->getDriverName() !== 'pgsql' || DB::transactionLevel() > 0) {
            return $callback();
        }

        DB::beginTransaction();
        try {
            DB::statement('SET TRANSACTION READ ONLY');

            return $callback();
        } finally {
            DB::rollBack();
        }
    }
}
