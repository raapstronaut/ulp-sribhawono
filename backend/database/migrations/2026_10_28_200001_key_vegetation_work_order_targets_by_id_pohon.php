<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Target WO ROW lama memakai kunci `VEGETASI:{vegetasi_source_rows.id}`. ID baris staging berubah
 * setiap import `--replace`, sehingga kunci itu tidak stabil. Migration ini memindahkan kunci ke
 * identitas bisnis `POHON:{ID POHON}` yang diambil dari snapshot target (bukan dari staging saat ini).
 *
 * Tidak ada kolom maupun baris yang dihapus: kunci dan referensi lama disimpan di
 * snapshot.legacy_target_key / legacy_source_ref sehingga down() dapat mengembalikannya.
 * Target tanpa ID POHON di snapshot dibiarkan. Bila dua WO aktif lama ternyata menunjuk pohon yang
 * sama (jenis pekerjaan sama), WO yang lebih baru tetap memakai kunci aktif lama dan ditandai
 * snapshot.identity_conflict agar ditinjau Admin; tidak ada WO yang dibatalkan otomatis.
 */
return new class extends Migration
{
    public function up(): void
    {
        $targets = DB::table('work_order_location_targets')
            ->where('target_type', 'VEGETASI')
            ->where('target_key', 'like', 'VEGETASI:%')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'target_key', 'source_ref', 'active_target_key', 'snapshot']);

        foreach ($targets as $target) {
            $snapshot = json_decode((string) $target->snapshot, true) ?: [];
            $idPohon = trim((string) ($snapshot['id_pohon'] ?? ''));
            if ($idPohon === '' || mb_strlen($idPohon) > 64) {
                continue;
            }

            $newKey = 'POHON:'.$idPohon;
            $snapshot['identity'] = 'ID_POHON';
            $snapshot['legacy_target_key'] = $target->target_key;
            $snapshot['legacy_source_ref'] = $target->source_ref;

            $activeKey = $target->active_target_key;
            if ($activeKey !== null && str_ends_with($activeKey, '|'.$target->target_key)) {
                $candidate = substr($activeKey, 0, -strlen($target->target_key)).$newKey;
                $taken = DB::table('work_order_location_targets')
                    ->where('active_target_key', $candidate)
                    ->where('id', '!=', $target->id)
                    ->exists();
                if ($taken) {
                    $snapshot['identity_conflict'] = 'ACTIVE_DUPLICATE_FOR_ID_POHON';
                } else {
                    $activeKey = $candidate;
                }
            }

            DB::table('work_order_location_targets')->where('id', $target->id)->update([
                'target_key' => $newKey,
                'source_ref' => $idPohon,
                'active_target_key' => $activeKey,
                'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    public function down(): void
    {
        $targets = DB::table('work_order_location_targets')
            ->where('target_type', 'VEGETASI')
            ->where('target_key', 'like', 'POHON:%')
            ->get(['id', 'target_key', 'active_target_key', 'snapshot']);

        foreach ($targets as $target) {
            $snapshot = json_decode((string) $target->snapshot, true) ?: [];
            $legacyKey = $snapshot['legacy_target_key'] ?? null;
            if ($legacyKey === null) {
                // Target dibuat setelah migration: tidak punya format lama untuk dikembalikan.
                continue;
            }

            $activeKey = $target->active_target_key;
            if ($activeKey !== null && str_ends_with($activeKey, '|'.$target->target_key)) {
                $activeKey = substr($activeKey, 0, -strlen($target->target_key)).$legacyKey;
            }
            $legacyRef = $snapshot['legacy_source_ref'] ?? null;
            unset($snapshot['identity'], $snapshot['legacy_target_key'], $snapshot['legacy_source_ref'], $snapshot['identity_conflict']);

            DB::table('work_order_location_targets')->where('id', $target->id)->update([
                'target_key' => $legacyKey,
                'source_ref' => $legacyRef,
                'active_target_key' => $activeKey,
                'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }
};
