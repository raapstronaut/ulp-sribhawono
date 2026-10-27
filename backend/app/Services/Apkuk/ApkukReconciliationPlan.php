<?php

namespace App\Services\Apkuk;

/**
 * Selisih snapshot APKUK_RAW terhadap tabel apkuks pada satu waktu.
 *
 * Kode rencana (id) adalah awal sidik persetujuan (fingerprint) yang mencakup isi
 * sumber lengkap, daftar identitas yang akan ditandai, isi tabel apkuks, referensi
 * WO langsung, dan dampak ke kandidat WO/peta. Persetujuan hanya berlaku bila semua
 * komponen itu persis sama saat apply.
 */
final class ApkukReconciliationPlan
{
    public const CATEGORY_ID_DIPAKAI_TIANG_LAIN = 'ID_MASIH_ADA_TIANG_BERBEDA';

    public const CATEGORY_TIANG_MASIH_ADA = 'TIANG_MASIH_PUNYA_LAPORAN_LAIN';

    public const CATEGORY_TIDAK_ADA = 'ID_DAN_TIANG_TIDAK_ADA';

    public const CATEGORY_LABELS = [
        self::CATEGORY_ID_DIPAKAI_TIANG_LAIN => 'ID masih ada di sumber dengan tiang lain (cek: tiang diedit atau ID dipakai ulang)',
        self::CATEGORY_TIANG_MASIH_ADA => 'Tiang masih punya laporan lain di sumber (laporan dihapus/digabung)',
        self::CATEGORY_TIDAK_ADA => 'ID dan tiang tidak ada lagi di sumber',
    ];

    /** Komponen sidik persetujuan, urut tampil. */
    public const FINGERPRINT_PARTS = [
        'source' => 'isi sumber',
        'missing' => 'identitas hilang',
        'database' => 'tabel apkuks',
        'work_orders' => 'referensi WO langsung',
        'impact' => 'dampak kandidat WO/peta',
    ];

    public readonly string $fingerprint;

    public readonly string $id;

    /**
     * @param  array<string, string>  $fingerprintParts  komponen => sha256 (lihat FINGERPRINT_PARTS)
     * @param  array<int, array{apkuk_id: int, source_id: ?string, no_tiang: ?string, tanggal: ?string, penyulang: ?string, status: ?string, legacy_key: string, category: string, eligible_candidate: bool}>  $missing
     * @param  array<int, array{source_id: string, no_tiang: string, sheet_row: int}>  $new
     * @param  array<int, array{apkuk_id: int, source_id: ?string, no_tiang: ?string}>  $returning
     * @param  array<string, array<int, array{no_tiang: string, sheet_row: int}>>  $duplicatedIds
     * @param  array<int, array<int, array{source_id: string, sheet_row: int}>>  $possibleDuplicateReports
     * @param  array<int, array{target_id: string, work_order_id: string, apkuk_id: int, code: string, status: string, active: bool, active_target_key: ?string}>  $workOrderRefs
     * @param  array{candidates: array{total_before: int, total_after: int, eligible_before: int, eligible_after: int}, map: array{active_before: int, active_after: int, markers_before: int, markers_after: int}, eligible_removed: array<int, array<string, mixed>>, newly_eligible: array<int, array<string, mixed>>, no_longer_eligible: array<int, array<string, mixed>>, reason_changed: array<int, array<string, mixed>>}  $impact
     */
    public function __construct(
        public readonly array $fingerprintParts,
        public readonly int $sourceReports,
        public readonly int $activeIdentities,
        public readonly int $markedIdentities,
        public readonly int $matched,
        public readonly array $missing,
        public readonly array $new,
        public readonly array $returning,
        public readonly array $duplicatedIds,
        public readonly array $possibleDuplicateReports,
        public readonly array $workOrderRefs,
        public readonly array $impact,
    ) {
        $this->fingerprint = self::fingerprintFor($fingerprintParts);
        $this->id = substr($this->fingerprint, 0, 16);
    }

    /**
     * @param  array<string, string>  $parts
     */
    public static function fingerprintFor(array $parts): string
    {
        ksort($parts);

        return hash('sha256', "apkuk-reconcile-v2\n".implode("\n", array_map(
            fn (string $name, string $digest) => "{$name}={$digest}",
            array_keys($parts),
            $parts,
        )));
    }

    /**
     * Sidik daftar identitas yang akan ditandai (id baris + legacy_key, tanpa urutan).
     *
     * @param  array<int, array{apkuk_id: int, legacy_key: string}>  $missing
     */
    public static function missingDigest(array $missing): string
    {
        $entries = array_map(fn (array $row) => $row['apkuk_id'].'|'.$row['legacy_key'], $missing);
        sort($entries);

        return hash('sha256', implode("\n", $entries));
    }

    /**
     * @return array<int, int>
     */
    public function missingApkukIds(): array
    {
        return array_column($this->missing, 'apkuk_id');
    }

    /**
     * @return array<int, string>
     */
    public function missingLegacyKeys(): array
    {
        return array_column($this->missing, 'legacy_key');
    }

    /**
     * @return array<int, array{target_id: string, work_order_id: string, apkuk_id: int, code: string, status: string, active: bool, active_target_key: ?string}>
     */
    public function activeWorkOrderRefs(): array
    {
        return array_values(array_filter($this->workOrderRefs, fn (array $ref) => $ref['active']));
    }

    /**
     * @return array<string, int>
     */
    public function missingByCategory(): array
    {
        $counts = array_fill_keys(array_keys(self::CATEGORY_LABELS), 0);
        foreach ($this->missing as $row) {
            $counts[$row['category']]++;
        }

        return $counts;
    }

    /**
     * Kode WO yang targetnya merujuk tiap baris apkuks (untuk ekspor CSV).
     *
     * @return array<int, array<int, string>>
     */
    public function workOrderCodesByApkuk(): array
    {
        $codes = [];
        foreach ($this->workOrderRefs as $ref) {
            $codes[$ref['apkuk_id']][] = $ref['code'];
        }

        return $codes;
    }
}
