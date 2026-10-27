<?php

namespace App\Services\Apkuk;

/**
 * Hasil baca + validasi APKUK_RAW pada satu waktu.
 */
final class ApkukSourceSnapshot
{
    /**
     * @param  array<int, array<string, mixed>>  $records  baris siap upsert ke apkuks
     * @param  array<string, int>  $keyToSheetRow  legacy_key => nomor baris sheet
     * @param  array<string, int>  $sourceIds  ID ternormalisasi => jumlah laporan
     * @param  array<int, string>  $issues  kesalahan validasi (memblokir sync)
     * @param  array<int, string>  $negativeCounts  jumlah negatif (tetap disimpan)
     */
    public function __construct(
        public readonly int $reportCount,
        public readonly array $records,
        public readonly array $keyToSheetRow,
        public readonly array $sourceIds,
        public readonly array $issues,
        public readonly array $negativeCounts,
    ) {}

    /**
     * @return array<string, int>
     */
    public function duplicatedSourceIds(): array
    {
        return array_filter($this->sourceIds, fn (int $count) => $count > 1);
    }

    /**
     * Sumber dianggap lengkap bila tidak ada kesalahan validasi, tidak terpotong
     * (minimal MIN_EXPECTED_ROWS), dan setiap laporan siap diproses.
     */
    public function isComplete(): bool
    {
        return $this->issues === []
            && $this->reportCount >= ApkukSourceReader::MIN_EXPECTED_ROWS
            && count($this->records) === $this->reportCount;
    }

    public function hasKey(string $legacyKey): bool
    {
        return isset($this->keyToSheetRow[$legacyKey]);
    }

    /**
     * Sidik isi seluruh laporan sumber, berurutan per baris sheet. Berubah bila laporan
     * ditambah, dihapus, dipindah baris, atau isinya diedit. Cap waktu pembacaan diabaikan.
     */
    public function digest(): string
    {
        $volatile = ['last_synced_at' => true, 'created_at' => true, 'updated_at' => true];
        $hash = hash_init('sha256');
        hash_update($hash, "reports={$this->reportCount}\n");
        foreach ($this->records as $record) {
            hash_update($hash, serialize(array_diff_key($record, $volatile))."\n");
        }

        return hash_final($hash);
    }
}
