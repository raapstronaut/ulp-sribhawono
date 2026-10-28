<?php

namespace App\Services\Apkuk;

use App\Models\ApkukReconciliationItem;
use App\Models\ApkukSyncRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Menerapkan snapshot APKUK_RAW yang valid ke apkuks dalam satu transaksi terkunci.
 *
 * - Laporan baru, diperbarui, pindah baris, dan muncul kembali ditulis walau ada laporan lain
 *   yang menunggu rekonsiliasi; laporan tidak berubah hanya diperbarui last_synced_at.
 * - Laporan yang hilang masuk antrean (tidak dihapus, source_missing_at tidak diisi);
 *   identitas baru dengan ID AppSheet sama ditahan di antrean, tidak disisipkan.
 * - Antrean yang tidak lagi relevan diselesaikan otomatis (muncul kembali/digantikan).
 * - Rencana dihitung ulang setelah kunci didapat, lalu diperiksa pengaman; pelanggaran
 *   membatalkan seluruh transaksi. Menjalankan ulang dengan sumber sama tidak mengubah isi.
 */
class ApkukSynchronizer
{
    public function __construct(private readonly ApkukSyncPlanner $planner) {}

    /**
     * @throws ApkukSnapshotRejected
     */
    public function apply(ApkukSourceSnapshot $source, ApkukSyncPolicy $policy, ApkukSyncRun $run): ApkukSyncOutcome
    {
        $outcome = DB::transaction(function () use ($source, $policy, $run): ApkukSyncOutcome {
            ApkukSyncLock::exclusive();
            if (DB::connection()->getDriverName() === 'pgsql') {
                // Penulis lain (rekonsiliasi, penyelesaian antrean) menunggu; pembaca tetap jalan.
                DB::statement('LOCK TABLE apkuks IN SHARE ROW EXCLUSIVE MODE');
            }

            $plan = $this->planner->plan($source);
            $baseline = ApkukSyncPolicy::baseline($plan);
            $limits = $policy->limits($plan, $baseline);
            $violations = $policy->violations($plan, $baseline);
            if ($violations !== []) {
                throw new ApkukSnapshotRejected($plan, $violations, $limits);
            }

            $now = Carbon::now();
            $this->writeReports($plan);
            $this->touchPresentReports($plan, $now);
            $this->writeQueue($plan, $source, $run, $now);

            $status = $plan->desiredItems === [] ? ApkukSyncRun::STATUS_SUCCESS : ApkukSyncRun::STATUS_SUCCESS_WITH_PENDING;
            // Histori yang muncul kembali dipulihkan otomatis; ID-nya dicatat karena pemulihan itu
            // membatalkan keputusan arsip sebelumnya.
            $returnedIds = array_column($plan->returned, 'apkuk_id');
            $run->forceFill([
                'status' => $status,
                'snapshot_accepted' => true,
                'source_report_count' => $source->reportCount,
                'source_digest' => $source->digest(),
                'identity_digest' => $source->identityDigest(),
                'counts' => $plan->counts(),
                'guard' => $limits,
                'details' => $returnedIds === [] ? null : ['returned_apkuk_ids' => $returnedIds],
                'database_user' => DB::connection()->getDriverName() === 'pgsql' ? (string) DB::scalar('select current_user') : null,
                'finished_at' => $now,
            ])->save();

            return new ApkukSyncOutcome($plan, $limits, $status);
        });

        if ($outcome->plan->returned !== []) {
            Log::warning('apkuk:sync memulihkan laporan APKUK yang diarsipkan karena muncul kembali di sumber', [
                'run_id' => $run->id,
                'apkuk_ids' => array_column($outcome->plan->returned, 'apkuk_id'),
            ]);
        }

        return $outcome;
    }

    private function writeReports(ApkukSyncPlan $plan): void
    {
        $records = array_merge(
            $plan->inserts,
            array_column($plan->updates, 'record'),
            array_column($plan->moved, 'record'),
            array_column($plan->returned, 'record'), // source_missing_at => null memulihkan laporan
        );
        if ($records === []) {
            return;
        }

        $toUpdate = array_values(array_diff(array_keys($records[0]), ['legacy_key', 'created_at', 'sumber_input']));
        foreach (array_chunk($records, 200) as $chunk) {
            DB::table('apkuks')->upsert($chunk, ['legacy_key'], $toUpdate);
        }
    }

    /**
     * last_synced_at = terakhir terlihat di sumber, untuk semua laporan yang masih ada.
     * Laporan di antrean tidak diperbarui, sehingga cap waktunya menunjukkan kapan terakhir terlihat.
     */
    private function touchPresentReports(ApkukSyncPlan $plan, Carbon $now): void
    {
        $query = DB::table('apkuks')
            ->where('sumber_input', ApkukSourceReader::SOURCE)
            ->whereNotNull('legacy_key')
            ->whereNull('source_missing_at');

        $missingIds = array_column($plan->missing, 'apkuk_id');
        foreach (array_chunk($missingIds, 1000) as $chunk) {
            $query->whereNotIn('id', $chunk);
        }

        $query->update(['last_synced_at' => $now]);
    }

    private function writeQueue(ApkukSyncPlan $plan, ApkukSourceSnapshot $source, ApkukSyncRun $run, Carbon $now): void
    {
        $missingIds = array_flip(array_column($plan->missing, 'apkuk_id'));

        // 1. Selesaikan antrean yang tidak lagi relevan (open_key dikosongkan lebih dulu).
        $resolutions = [];
        foreach ($plan->itemsToResolve() as $item) {
            [$resolution, $reason] = match (true) {
                $item->kind === ApkukReconciliationItem::KIND_MISSING && $source->hasKey($item->legacy_key) => [ApkukReconciliationItem::RESOLUTION_RETURNED, 'Identitas muncul kembali di APKUK_RAW.'],
                $item->kind === ApkukReconciliationItem::KIND_MISSING && isset($missingIds[(int) $item->apkuk_id]) => [ApkukReconciliationItem::RESOLUTION_SUPERSEDED, 'Digantikan dugaan perubahan identitas (ID AppSheet sama).'],
                $item->kind === ApkukReconciliationItem::KIND_MISSING => [ApkukReconciliationItem::RESOLUTION_SUPERSEDED, 'Laporan tidak lagi aktif di apkuks (sudah menjadi histori).'],
                ! $source->hasKey($item->legacy_key) => [ApkukReconciliationItem::RESOLUTION_SUPERSEDED, 'Identitas baru tidak lagi ada di APKUK_RAW.'],
                default => [ApkukReconciliationItem::RESOLUTION_SUPERSEDED, 'Laporan lama ber-ID sama tidak lagi hilang; identitas baru diproses sebagai laporan baru.'],
            };
            $resolutions[$resolution."\n".$reason][] = (int) $item->id;
        }
        foreach ($resolutions as $group => $ids) {
            [$resolution, $reason] = explode("\n", $group, 2);
            DB::table('apkuk_reconciliation_items')->whereIn('id', $ids)->update([
                'status' => ApkukReconciliationItem::STATUS_RESOLVED,
                'open_key' => null,
                'resolution' => $resolution,
                'resolution_reason' => $reason,
                'resolved_at' => $now,
                'resolved_run_id' => $run->id,
                'updated_at' => $now,
            ]);
        }

        // 2. Antrean yang masih relevan: catat terlihat lagi; baris sumber yang ditahan diperbarui.
        $refresh = $plan->itemsToRefresh();
        foreach (array_chunk(array_map(fn (object $item) => (int) $item->id, array_values($refresh)), 1000) as $ids) {
            DB::table('apkuk_reconciliation_items')->whereIn('id', $ids)->update([
                'last_seen_run_id' => $run->id,
                'last_detected_at' => $now,
                'detection_count' => DB::raw('detection_count + 1'),
                'updated_at' => $now,
            ]);
        }
        foreach ($refresh as $openKey => $item) {
            $desired = $plan->desiredItems[$openKey];
            if ($item->kind !== ApkukReconciliationItem::KIND_IDENTITY_CHANGE) {
                continue;
            }
            $values = [
                'apkuk_id' => $desired['apkuk_id'],
                'source_id' => $desired['source_id'],
                'no_tiang' => $desired['no_tiang'],
                'source_record' => json_encode($desired['source_record']),
                'candidate_apkuk_ids' => json_encode($desired['candidate_apkuk_ids']),
            ];
            $current = [
                'apkuk_id' => $item->apkuk_id === null ? null : (int) $item->apkuk_id,
                'source_id' => $item->source_id,
                'no_tiang' => $item->no_tiang,
                'source_record' => json_encode(json_decode((string) $item->source_record, true)),
                'candidate_apkuk_ids' => json_encode(json_decode((string) $item->candidate_apkuk_ids, true)),
            ];
            if ($values !== $current) {
                DB::table('apkuk_reconciliation_items')->where('id', $item->id)->update($values + ['updated_at' => $now]);
            }
        }

        // 3. Antrean baru.
        $rows = [];
        foreach ($plan->itemsToOpen() as $openKey => $item) {
            $rows[] = [
                'kind' => $item['kind'],
                'status' => ApkukReconciliationItem::STATUS_OPEN,
                'open_key' => $openKey,
                'apkuk_id' => $item['apkuk_id'],
                'legacy_key' => $item['legacy_key'],
                'source_id' => $item['source_id'],
                'no_tiang' => $item['no_tiang'],
                'source_record' => $item['source_record'] === null ? null : json_encode($item['source_record']),
                'candidate_apkuk_ids' => $item['candidate_apkuk_ids'] === null ? null : json_encode($item['candidate_apkuk_ids']),
                'first_seen_run_id' => $run->id,
                'last_seen_run_id' => $run->id,
                'first_detected_at' => $now,
                'last_detected_at' => $now,
                'detection_count' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('apkuk_reconciliation_items')->insert($chunk);
        }
    }
}
