<?php

namespace App\Services\Apkuk;

use App\Models\ApkukReconciliationItem;
use Illuminate\Support\Facades\DB;

/**
 * Menghitung ApkukSyncPlan dari snapshot sumber, apkuks, dan antrean rekonsiliasi.
 * Tidak menulis apa pun; apkuk:sync memanggilnya di dalam transaksi yang sudah terkunci.
 *
 * Identitas laporan tetap legacy_key (ID AppSheet + nomor tiang). Bila nomor tiang diedit,
 * identitas lama hilang dan identitas baru muncul dengan ID AppSheet yang sama. Pasangan
 * seperti itu tidak pernah ditebak: identitas baru ditahan di antrean (IDENTITY_CHANGE)
 * sampai Admin memutuskan, sehingga tidak ada laporan ganda untuk tiang yang sama dan
 * referensi WO ke laporan lama tidak terputus.
 */
class ApkukSyncPlanner
{
    /** Kolom isi laporan; perubahan salah satunya dihitung "diperbarui". */
    public const CONTENT_COLUMNS = [
        'source_id', 'tanggal', 'petugas', 'penyulang', 'section', 'no_tiang', 'naik_dari',
        'terpasang_bh', 'kurang_bh', 'status', 'koordinat', 'latitude', 'longitude', 'foto',
    ];

    /** Cap waktu yang diisi saat menulis, tidak ikut disimpan untuk baris yang ditahan. */
    public const VOLATILE_COLUMNS = ['last_synced_at', 'created_at', 'updated_at'];

    public function plan(ApkukSourceSnapshot $source): ApkukSyncPlan
    {
        $rows = DB::table('apkuks')
            ->where('sumber_input', ApkukSourceReader::SOURCE)
            ->whereNotNull('legacy_key')
            ->orderBy('id')
            ->get(array_merge(['id', 'legacy_key', 'source_missing_at', 'source_row_number'], self::CONTENT_COLUMNS));

        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row->legacy_key] = $row;
        }
        unset($rows);

        $new = [];
        $updates = [];
        $moved = [];
        $returned = [];
        $unchanged = 0;
        foreach ($source->records as $record) {
            $row = $byKey[$record['legacy_key']] ?? null;
            if ($row === null) {
                $new[] = $record;

                continue;
            }
            if ($row->source_missing_at !== null) {
                $returned[] = ['record' => $record, 'apkuk_id' => (int) $row->id];

                continue;
            }

            $columns = self::changedColumns($row, $record);
            if ($columns !== []) {
                $updates[] = ['record' => $record, 'apkuk_id' => (int) $row->id, 'columns' => $columns];
            } elseif ((int) $row->source_row_number !== (int) $record['source_row_number']) {
                $moved[] = ['record' => $record, 'apkuk_id' => (int) $row->id];
            } else {
                $unchanged++;
            }
        }

        $missing = [];
        $activeBefore = 0;
        foreach ($byKey as $key => $row) {
            if ($row->source_missing_at !== null) {
                continue;
            }
            $activeBefore++;
            if (! $source->hasKey($key)) {
                $missing[] = [
                    'apkuk_id' => (int) $row->id,
                    'legacy_key' => $key,
                    'source_id' => $row->source_id,
                    'no_tiang' => $row->no_tiang,
                    'tanggal' => $row->tanggal === null ? null : substr((string) $row->tanggal, 0, 10),
                    'penyulang' => $row->penyulang,
                    'status' => $row->status,
                    'kurang_bh' => $row->kurang_bh === null ? null : (int) $row->kurang_bh,
                ];
            }
        }

        [$inserts, $held, $paired] = $this->separateIdentityChanges($new, $missing);

        $desired = [];
        foreach ($held as $entry) {
            $record = $entry['record'];
            $desired[ApkukReconciliationItem::openKey(ApkukReconciliationItem::KIND_IDENTITY_CHANGE, $record['legacy_key'])] = [
                'kind' => ApkukReconciliationItem::KIND_IDENTITY_CHANGE,
                'apkuk_id' => $entry['apkuk_id'],
                'legacy_key' => $record['legacy_key'],
                'source_id' => $record['source_id'],
                'no_tiang' => $record['no_tiang'],
                'source_record' => self::storableRecord($record),
                'candidate_apkuk_ids' => $entry['candidate_apkuk_ids'],
            ];
        }
        foreach ($missing as $row) {
            if (isset($paired[$row['apkuk_id']])) {
                continue; // diwakili item IDENTITY_CHANGE pasangannya
            }
            $desired[ApkukReconciliationItem::openKey(ApkukReconciliationItem::KIND_MISSING, $row['legacy_key'])] = [
                'kind' => ApkukReconciliationItem::KIND_MISSING,
                'apkuk_id' => $row['apkuk_id'],
                'legacy_key' => $row['legacy_key'],
                'source_id' => $row['source_id'],
                'no_tiang' => $row['no_tiang'],
                'source_record' => null,
                'candidate_apkuk_ids' => null,
            ];
        }

        $openItems = [];
        $alreadyQueued = [];
        foreach ($this->openItems() as $item) {
            $openItems[$item->open_key] = $item;
            if ($item->apkuk_id !== null) {
                $alreadyQueued[(int) $item->apkuk_id] = true;
            }
            foreach (json_decode((string) $item->candidate_apkuk_ids, true) ?: [] as $id) {
                $alreadyQueued[(int) $id] = true;
            }
        }

        $newlyMissing = [];
        foreach ($missing as $row) {
            if (! isset($alreadyQueued[$row['apkuk_id']])) {
                $newlyMissing[] = $row['apkuk_id'];
            }
        }

        return new ApkukSyncPlan(
            sourceReports: $source->reportCount,
            activeBefore: $activeBefore,
            inserts: $inserts,
            updates: $updates,
            moved: $moved,
            returned: $returned,
            unchanged: $unchanged,
            missing: $missing,
            held: $held,
            desiredItems: $desired,
            openItems: $openItems,
            newlyMissingIds: $newlyMissing,
        );
    }

    /**
     * Rencana untuk dry-run. Di PostgreSQL dibaca dalam transaksi READ ONLY lalu di-rollback.
     */
    public function planReadOnly(ApkukSourceSnapshot $source): ApkukSyncPlan
    {
        if (DB::connection()->getDriverName() !== 'pgsql' || DB::transactionLevel() > 0) {
            return $this->plan($source);
        }

        DB::beginTransaction();
        try {
            DB::statement('SET TRANSACTION READ ONLY');

            return $this->plan($source);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @return iterable<int, object>
     */
    protected function openItems(): iterable
    {
        return DB::table('apkuk_reconciliation_items')
            ->where('status', ApkukReconciliationItem::STATUS_OPEN)
            ->orderBy('id')
            ->get();
    }

    /**
     * Baris sumber siap disimpan sebagai JSON antrean (tanpa cap waktu pembacaan).
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public static function storableRecord(array $record): array
    {
        return array_diff_key($record, array_flip(self::VOLATILE_COLUMNS));
    }

    /**
     * Identitas baru yang ID AppSheet-nya sama dengan laporan yang hilang ditahan.
     * Pasangan 1:1 (satu hilang, satu baru) dicatat sebagai laporan lama yang diduga; selain
     * itu (mis. ID dipakai beberapa laporan) semua laporan hilang ber-ID sama menjadi kandidat.
     *
     * @param  array<int, array<string, mixed>>  $new
     * @param  array<int, array<string, mixed>>  $missing
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array{record: array<string, mixed>, apkuk_id: ?int, candidate_apkuk_ids: array<int, int>}>, 2: array<int, true>}
     */
    private function separateIdentityChanges(array $new, array $missing): array
    {
        $missingById = [];
        foreach ($missing as $row) {
            $missingById[ApkukSourceReader::normalizeId(trim((string) $row['source_id']))][] = $row['apkuk_id'];
        }

        $inserts = [];
        $heldById = [];
        foreach ($new as $record) {
            $id = ApkukSourceReader::normalizeId($record['source_id']);
            if (isset($missingById[$id])) {
                $heldById[$id][] = $record;
            } else {
                $inserts[] = $record;
            }
        }

        $held = [];
        $paired = [];
        foreach ($heldById as $id => $records) {
            $candidates = $missingById[$id];
            $pairedId = count($records) === 1 && count($candidates) === 1 ? $candidates[0] : null;
            if ($pairedId !== null) {
                $paired[$pairedId] = true;
            }
            foreach ($records as $record) {
                $held[] = ['record' => $record, 'apkuk_id' => $pairedId, 'candidate_apkuk_ids' => $candidates];
            }
        }

        return [$inserts, $held, $paired];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<int, string>
     */
    private static function changedColumns(object $row, array $record): array
    {
        $changed = [];
        foreach (self::CONTENT_COLUMNS as $column) {
            if (! self::same($column, $row->{$column}, $record[$column] ?? null)) {
                $changed[] = $column;
            }
        }

        return $changed;
    }

    /**
     * Membandingkan nilai database dengan nilai sumber tanpa terpengaruh tipe driver
     * (PostgreSQL mengembalikan numeric sebagai string, SQLite apa adanya).
     */
    private static function same(string $column, mixed $stored, mixed $incoming): bool
    {
        if ($stored === null || $incoming === null) {
            return $stored === null && $incoming === null;
        }

        return match ($column) {
            'tanggal' => substr((string) $stored, 0, 10) === (string) $incoming,
            'terpasang_bh', 'kurang_bh' => (int) $stored === (int) $incoming,
            'latitude', 'longitude' => round((float) $stored, 7) === round((float) $incoming, 7),
            default => (string) $stored === (string) $incoming,
        };
    }
}
