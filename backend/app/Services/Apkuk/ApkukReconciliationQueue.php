<?php

namespace App\Services\Apkuk;

use App\Enums\WorkOrderStatus;
use App\Models\ApkukReconciliationItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Penyelesaian antrean rekonsiliasi APKUK oleh Admin terautentikasi (Web Admin).
 *
 * SPS BOS adalah sumber live: laporan yang hilang bisa memang tidak berlaku, atau hanya
 * sementara (sedang diedit/dipindahkan). Karena itu pengarsipan permanen tidak pernah otomatis
 * (bukan karena jumlah siklus atau lamanya hilang), melainkan keputusan Admin aktif yang login
 * berdasarkan konfirmasi pemilik data. Akun, alasan, konfirmasi pemilik data, dan rincian
 * tersimpan permanen di item antrean. Tidak ada hard-delete: laporan yang diarsipkan hanya
 * diberi source_missing_at (dipulihkan otomatis bila muncul kembali), dan WO tidak diubah.
 */
class ApkukReconciliationQueue
{
    /** MISSING: pemilik data mengonfirmasi laporan tidak berlaku; diarsipkan (histori). */
    public const DECISION_CONFIRM_REMOVED = 'CONFIRM_REMOVED';

    /** IDENTITY_CHANGE: laporan yang sama dengan identitas baru (mis. nomor tiang dikoreksi). */
    public const DECISION_CONFIRM_IDENTITY = 'CONFIRM_IDENTITY';

    /** IDENTITY_CHANGE: laporan berbeda; disimpan sebagai laporan baru. */
    public const DECISION_INSERT_AS_NEW = 'INSERT_AS_NEW';

    public const DECISIONS = [
        self::DECISION_CONFIRM_REMOVED => ApkukReconciliationItem::KIND_MISSING,
        self::DECISION_CONFIRM_IDENTITY => ApkukReconciliationItem::KIND_IDENTITY_CHANGE,
        self::DECISION_INSERT_AS_NEW => ApkukReconciliationItem::KIND_IDENTITY_CHANGE,
    ];

    public const MIN_REASON = 10;

    public const MAX_REASON = 1000;

    public const MIN_OWNER_CONFIRMATION = 3;

    public const MAX_OWNER_CONFIRMATION = 255;

    /** Batas antrean per konfirmasi massal (satu transaksi). */
    public const MAX_BATCH = 500;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function resolve(
        User $actor,
        int $itemId,
        string $decision,
        string $reason,
        ?int $apkukId = null,
        bool $acknowledgeActiveWorkOrders = false,
        ?string $ownerConfirmation = null,
    ): ApkukReconciliationItem {
        $reason = $this->authorizeDecision($actor, $reason);
        if (! isset(self::DECISIONS[$decision])) {
            throw ValidationException::withMessages(['decision' => 'Keputusan tidak dikenal.']);
        }
        // Wajib untuk pengarsipan; untuk keputusan identitas dicatat bila diisi.
        $ownerConfirmation = $decision === self::DECISION_CONFIRM_REMOVED || trim((string) $ownerConfirmation) !== ''
            ? self::ownerConfirmation($ownerConfirmation)
            : null;

        $item = DB::transaction(function () use ($actor, $itemId, $decision, $reason, $apkukId, $acknowledgeActiveWorkOrders, $ownerConfirmation): ApkukReconciliationItem {
            // Sama dengan apkuk:sync: keputusan dan sinkronisasi tidak pernah berjalan bersamaan.
            ApkukSyncLock::exclusive();

            return $this->resolveLocked($actor, $itemId, $decision, $reason, $ownerConfirmation, $apkukId, $acknowledgeActiveWorkOrders, Carbon::now());
        });

        Log::warning('Antrean rekonsiliasi APKUK diselesaikan Admin', [
            'item_id' => $item->id,
            'kind' => $item->kind,
            'resolution' => $item->resolution,
            'apkuk_id' => $item->apkuk_id,
            'user_id' => $actor->id,
        ]);

        return $item;
    }

    /**
     * Arsipkan banyak laporan MISSING sekaligus dengan satu alasan dan satu konfirmasi pemilik
     * data. Semua atau tidak sama sekali: bila satu item gagal (sudah selesai, WO aktif tanpa
     * konfirmasi), tidak ada yang berubah. Setiap item tetap mencatat Admin dan rinciannya sendiri.
     *
     * @param  array<int, int>  $itemIds
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function confirmRemovedMany(User $actor, array $itemIds, string $reason, ?string $ownerConfirmation, bool $acknowledgeActiveWorkOrders = false): int
    {
        $reason = $this->authorizeDecision($actor, $reason);
        $ownerConfirmation = self::ownerConfirmation($ownerConfirmation);
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
        if ($itemIds === [] || count($itemIds) > self::MAX_BATCH) {
            throw ValidationException::withMessages(['item_ids' => 'Pilih 1–'.self::MAX_BATCH.' antrean.']);
        }

        DB::transaction(function () use ($actor, $itemIds, $reason, $ownerConfirmation, $acknowledgeActiveWorkOrders): void {
            ApkukSyncLock::exclusive();
            $now = Carbon::now();
            foreach ($itemIds as $itemId) {
                try {
                    $this->resolveLocked($actor, $itemId, self::DECISION_CONFIRM_REMOVED, $reason, $ownerConfirmation, null, $acknowledgeActiveWorkOrders, $now, ['batch_size' => count($itemIds)]);
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(array_map(
                        fn (array $messages) => array_map(fn (string $message) => "Antrean #{$itemId}: {$message} Tidak ada yang diubah.", $messages),
                        $e->errors(),
                    ));
                }
            }
        });

        Log::warning('Antrean rekonsiliasi APKUK diarsipkan secara massal oleh Admin', [
            'item_ids' => $itemIds,
            'user_id' => $actor->id,
        ]);

        return count($itemIds);
    }

    /**
     * Referensi WO langsung per baris apkuks (untuk tampilan dan konfirmasi WO aktif).
     *
     * @param  array<int, int>  $apkukIds
     * @return array<int, array<int, array{code: string, status: string, active: bool}>>
     */
    public static function workOrderRefs(array $apkukIds): array
    {
        $refs = [];
        foreach (array_chunk(array_values(array_unique($apkukIds)), 1000) as $chunk) {
            $rows = DB::table('work_order_apkuk_targets as target')
                ->join('work_orders as wo', 'wo.id', '=', 'target.work_order_id')
                ->whereIn('target.apkuk_id', $chunk)
                ->orderBy('wo.code')
                ->get(['target.apkuk_id', 'wo.code', 'wo.status']);
            foreach ($rows as $row) {
                $refs[(int) $row->apkuk_id][] = [
                    'code' => $row->code,
                    'status' => $row->status,
                    'active' => WorkOrderStatus::tryFrom($row->status)?->isActive() ?? true,
                ];
            }
        }

        return $refs;
    }

    /**
     * Konfirmasi pemilik data SPS BOS (siapa dan rujukannya). Dicatat apa adanya; kewenangan
     * tetap berasal dari akun Admin yang login.
     *
     * @throws ValidationException
     */
    public static function ownerConfirmation(?string $value): string
    {
        $value = trim((string) $value);
        if (mb_strlen($value) < self::MIN_OWNER_CONFIRMATION || mb_strlen($value) > self::MAX_OWNER_CONFIRMATION || preg_match('/^[^\p{Cc}]*$/u', $value) !== 1) {
            throw ValidationException::withMessages(['owner_confirmation' => 'Konfirmasi pemilik data SPS BOS wajib diisi untuk pengarsipan (nama/jabatan dan rujukan, '.self::MIN_OWNER_CONFIRMATION.'–'.self::MAX_OWNER_CONFIRMATION.' karakter, satu baris).']);
        }

        return $value;
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function authorizeDecision(User $actor, string $reason): string
    {
        if (! $actor->canAccessAdmin()) {
            throw new AuthorizationException('Hanya Admin aktif yang dapat menyelesaikan antrean rekonsiliasi APKUK.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < self::MIN_REASON || mb_strlen($reason) > self::MAX_REASON || preg_match('/^[^\p{Cc}]*$/u', $reason) !== 1) {
            throw ValidationException::withMessages(['reason' => 'Alasan wajib diisi '.self::MIN_REASON.'–'.self::MAX_REASON.' karakter, satu baris.']);
        }

        return $reason;
    }

    /**
     * Dipanggil di dalam transaksi yang sudah memegang ApkukSyncLock::exclusive().
     *
     * @param  array<string, mixed>  $extraDetails
     */
    private function resolveLocked(User $actor, int $itemId, string $decision, string $reason, ?string $ownerConfirmation, ?int $apkukId, bool $acknowledgeActiveWorkOrders, Carbon $now, array $extraDetails = []): ApkukReconciliationItem
    {
        $item = ApkukReconciliationItem::query()->lockForUpdate()->find($itemId);
        if ($item === null) {
            throw ValidationException::withMessages(['item' => 'Antrean rekonsiliasi tidak ditemukan.']);
        }
        if (! $item->isOpen()) {
            throw ValidationException::withMessages(['item' => 'Antrean ini sudah diselesaikan ('.$item->resolutionLabel().'). Muat ulang halaman.']);
        }
        if (self::DECISIONS[$decision] !== $item->kind) {
            throw ValidationException::withMessages(['decision' => 'Keputusan tidak sesuai dengan jenis antrean ('.$item->kindLabel().').']);
        }

        $details = match ($decision) {
            self::DECISION_CONFIRM_REMOVED => $this->confirmRemoved($item, $acknowledgeActiveWorkOrders, $now),
            self::DECISION_CONFIRM_IDENTITY => $this->confirmIdentity($item, $apkukId, $now),
            self::DECISION_INSERT_AS_NEW => $this->insertAsNew($item, $now),
        };
        $resolution = match ($decision) {
            self::DECISION_CONFIRM_REMOVED => ApkukReconciliationItem::RESOLUTION_CONFIRMED_REMOVED,
            self::DECISION_CONFIRM_IDENTITY => ApkukReconciliationItem::RESOLUTION_IDENTITY_CONFIRMED,
            self::DECISION_INSERT_AS_NEW => ApkukReconciliationItem::RESOLUTION_INSERTED_AS_NEW,
        };

        DB::table('apkuk_reconciliation_items')->where('id', $item->id)->update([
            'status' => ApkukReconciliationItem::STATUS_RESOLVED,
            'open_key' => null,
            'resolution' => $resolution,
            'resolved_at' => $now,
            'resolved_by_user_id' => $actor->id,
            'resolution_reason' => $reason,
            'owner_confirmation' => $ownerConfirmation,
            'resolution_details' => json_encode($details + $extraDetails + ['decision' => $decision]),
            'updated_at' => $now,
        ]);

        return $item->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function confirmRemoved(ApkukReconciliationItem $item, bool $acknowledgeActiveWorkOrders, Carbon $now): array
    {
        $row = $this->activeRow((int) $item->apkuk_id);
        $refs = self::workOrderRefs([(int) $row->id])[(int) $row->id] ?? [];
        $active = array_values(array_filter($refs, fn (array $ref) => $ref['active']));
        if ($active !== [] && ! $acknowledgeActiveWorkOrders) {
            throw ValidationException::withMessages([
                'acknowledge_active_wo' => 'WO aktif '.implode(', ', array_column($active, 'code')).' merujuk laporan ini. Centang konfirmasi bila tetap disetujui; WO tidak diubah.',
            ]);
        }

        // Hanya kolom penanda yang diubah; baris dan WO yang merujuknya tetap utuh.
        $affected = DB::table('apkuks')->where('id', $row->id)->whereNull('source_missing_at')
            ->update(['source_missing_at' => $now, 'updated_at' => $now]);
        if ($affected !== 1) {
            throw ValidationException::withMessages(['item' => 'Laporan berubah saat diproses. Muat ulang halaman.']);
        }

        return [
            'apkuk_id' => (int) $row->id,
            'work_orders' => array_column($refs, 'code'),
            'active_work_orders_acknowledged' => $active !== [],
        ];
    }

    /**
     * Laporan lama mengambil identitas dan isi baris sumber yang ditahan. ID baris apkuks
     * tidak berubah, sehingga seluruh WO yang merujuknya tetap tersambung.
     *
     * @return array<string, mixed>
     */
    private function confirmIdentity(ApkukReconciliationItem $item, ?int $apkukId, Carbon $now): array
    {
        $targetId = $apkukId ?? ($item->apkuk_id === null ? null : (int) $item->apkuk_id);
        if ($targetId === null || ! in_array($targetId, $item->identityCandidates(), true)) {
            throw ValidationException::withMessages(['apkuk_id' => 'Pilih laporan lama dari daftar kandidat dengan ID AppSheet yang sama.']);
        }
        $row = $this->activeRow($targetId);
        $this->ensureKeyUnused($item->legacy_key);

        $record = (array) $item->source_record;
        $values = ['legacy_key' => $item->legacy_key, 'source_row_number' => $record['source_row_number'] ?? null];
        foreach (ApkukSyncPlanner::CONTENT_COLUMNS as $column) {
            $values[$column] = $record[$column] ?? null;
        }
        DB::table('apkuks')->where('id', $row->id)->update($values + ['last_synced_at' => $now, 'updated_at' => $now]);

        // Antrean lain untuk laporan ini (mis. MISSING saat ID dipakai beberapa laporan) ikut selesai.
        DB::table('apkuk_reconciliation_items')
            ->where('status', ApkukReconciliationItem::STATUS_OPEN)
            ->where('apkuk_id', $row->id)
            ->where('id', '<>', $item->id)
            ->update([
                'status' => ApkukReconciliationItem::STATUS_RESOLVED,
                'open_key' => null,
                'resolution' => ApkukReconciliationItem::RESOLUTION_SUPERSEDED,
                'resolution_reason' => "Diselesaikan bersama antrean #{$item->id} (identitas dikonfirmasi).",
                'resolved_at' => $now,
                'updated_at' => $now,
            ]);

        $previous = ['legacy_key' => $row->legacy_key];
        foreach (ApkukSyncPlanner::CONTENT_COLUMNS as $column) {
            $previous[$column] = $row->{$column};
        }

        return [
            'apkuk_id' => (int) $row->id,
            'previous' => $previous,
            'work_orders' => array_column(self::workOrderRefs([(int) $row->id])[(int) $row->id] ?? [], 'code'),
        ];
    }

    /**
     * Baris sumber yang ditahan disimpan sebagai laporan baru. Laporan lama ber-ID sama tetap
     * menunggu keputusan sendiri (antrean MISSING dibuka bila belum ada).
     *
     * @return array<string, mixed>
     */
    private function insertAsNew(ApkukReconciliationItem $item, Carbon $now): array
    {
        $this->ensureKeyUnused($item->legacy_key);
        $record = (array) $item->source_record;
        $insertedId = DB::table('apkuks')->insertGetId(array_merge($record, [
            'legacy_key' => $item->legacy_key,
            'sumber_input' => ApkukSourceReader::SOURCE,
            'source_missing_at' => null,
            'last_synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]));

        $opened = [];
        foreach ($item->identityCandidates() as $candidateId) {
            $row = DB::table('apkuks')->where('id', $candidateId)->whereNull('source_missing_at')->first();
            $queued = DB::table('apkuk_reconciliation_items')->where('status', ApkukReconciliationItem::STATUS_OPEN)
                ->where('apkuk_id', $candidateId)->where('id', '<>', $item->id)->exists();
            if ($row === null || $queued) {
                continue;
            }
            DB::table('apkuk_reconciliation_items')->insert([
                'kind' => ApkukReconciliationItem::KIND_MISSING,
                'status' => ApkukReconciliationItem::STATUS_OPEN,
                'open_key' => ApkukReconciliationItem::openKey(ApkukReconciliationItem::KIND_MISSING, $row->legacy_key),
                'apkuk_id' => $row->id,
                'legacy_key' => $row->legacy_key,
                'source_id' => $row->source_id,
                'no_tiang' => $row->no_tiang,
                'first_seen_run_id' => $item->first_seen_run_id,
                'last_seen_run_id' => $item->last_seen_run_id,
                'first_detected_at' => $item->first_detected_at,
                'last_detected_at' => $item->last_detected_at,
                'detection_count' => $item->detection_count,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $opened[] = (int) $row->id;
        }

        return ['inserted_apkuk_id' => (int) $insertedId, 'missing_opened_for' => $opened];
    }

    private function activeRow(int $apkukId): object
    {
        $row = DB::table('apkuks')->where('id', $apkukId)->where('sumber_input', ApkukSourceReader::SOURCE)->lockForUpdate()->first();
        if ($row === null) {
            throw ValidationException::withMessages(['item' => 'Laporan APKUK tidak ditemukan.']);
        }
        if ($row->source_missing_at !== null) {
            throw ValidationException::withMessages(['item' => 'Laporan sudah menjadi histori (source_missing_at). Muat ulang halaman.']);
        }

        return $row;
    }

    private function ensureKeyUnused(string $legacyKey): void
    {
        if (DB::table('apkuks')->where('legacy_key', $legacyKey)->exists()) {
            throw ValidationException::withMessages(['item' => 'Identitas baru sudah ada di apkuks. Muat ulang halaman setelah sinkronisasi berikutnya.']);
        }
    }
}
