<?php

namespace App\Services\Apkuk;

/**
 * Selisih satu snapshot APKUK_RAW terhadap apkuks dan antrean rekonsiliasi.
 *
 * Laporan sumber dibagi menjadi: baru (disisipkan), diperbarui (isi berubah), pindah baris
 * (hanya nomor baris sheet berubah), muncul kembali (sebelumnya source_missing_at), tidak
 * berubah, dan ditahan (identitas baru yang ID AppSheet-nya sama dengan laporan yang hilang).
 * Laporan aktif yang tidak ada di sumber menjadi antrean, tidak pernah dihapus atau ditandai.
 */
final class ApkukSyncPlan
{
    /**
     * @param  array<int, array<string, mixed>>  $inserts  baris sumber baru
     * @param  array<int, array{record: array<string, mixed>, apkuk_id: int, columns: array<int, string>}>  $updates  isi berubah
     * @param  array<int, array{record: array<string, mixed>, apkuk_id: int}>  $moved  hanya source_row_number berubah
     * @param  array<int, array{record: array<string, mixed>, apkuk_id: int}>  $returned  bertanda source_missing_at, ada lagi di sumber
     * @param  array<int, array{apkuk_id: int, legacy_key: string, source_id: ?string, no_tiang: ?string, tanggal: ?string, penyulang: ?string, status: ?string, kurang_bh: ?int}>  $missing  aktif, tidak ada di sumber
     * @param  array<int, array{record: array<string, mixed>, apkuk_id: ?int, candidate_apkuk_ids: array<int, int>}>  $held  identitas baru yang ditahan
     * @param  array<string, array<string, mixed>>  $desiredItems  open_key => atribut item antrean yang harus terbuka
     * @param  array<string, object>  $openItems  open_key => item antrean yang terbuka sebelum eksekusi
     * @param  array<int, int>  $newlyMissingIds  laporan hilang yang belum ada di antrean
     */
    public function __construct(
        public readonly int $sourceReports,
        public readonly int $activeBefore,
        public readonly array $inserts,
        public readonly array $updates,
        public readonly array $moved,
        public readonly array $returned,
        public readonly int $unchanged,
        public readonly array $missing,
        public readonly array $held,
        public readonly array $desiredItems,
        public readonly array $openItems,
        public readonly array $newlyMissingIds,
    ) {}

    /**
     * Laporan yang ada di sumber dan apkuks (aktif atau bertanda).
     */
    public function matched(): int
    {
        return count($this->updates) + count($this->moved) + count($this->returned) + $this->unchanged;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function itemsToOpen(): array
    {
        return array_diff_key($this->desiredItems, $this->openItems);
    }

    /**
     * @return array<string, object>
     */
    public function itemsToRefresh(): array
    {
        return array_intersect_key($this->openItems, $this->desiredItems);
    }

    /**
     * @return array<string, object>
     */
    public function itemsToResolve(): array
    {
        return array_diff_key($this->openItems, $this->desiredItems);
    }

    /**
     * Ringkasan untuk log, SYNC_RESULT, dan apkuk_sync_runs.counts.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            'source_reports' => $this->sourceReports,
            'inserted' => count($this->inserts),
            'updated' => count($this->updates),
            'moved' => count($this->moved),
            'returned' => count($this->returned),
            'unchanged' => $this->unchanged,
            'missing' => count($this->missing),
            'identity_changes' => count($this->held),
            'pending_new' => count($this->itemsToOpen()),
            'pending_resolved' => count($this->itemsToResolve()),
            'pending_open' => count($this->desiredItems),
        ];
    }
}
