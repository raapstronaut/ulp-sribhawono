<?php

namespace App\Services;

use App\Models\Apkuk;
use App\Models\ApkukReconciliationItem;
use App\Models\Penyulang;
use App\Models\WorkOrderApkukTarget;
use App\Services\Apkuk\ApkukSectionService;
use App\Support\PenyulangName;
use Illuminate\Support\Collection;

/**
 * Menilai baris apkuks sebagai kandidat WO PEMASANGAN_APKUK.
 *
 * Aturan:
 * - Satu tiang (penyulang + no. tiang ternormalisasi) bisa dilaporkan berkali-kali;
 *   hanya laporan dengan tanggal terbaru yang dinilai. Jika ada >1 laporan pada
 *   tanggal terbaru, kebutuhan dianggap ambigu.
 * - Kebutuhan valid hanya bila kurang_bh > 0 dan status sumber menyatakan belum
 *   lengkap (BELUM EKSEKUSI / KURANG). Kosong, nol, negatif, atau status yang
 *   bertentangan tidak dianggap kebutuhan pemasangan.
 * - Penyulang harus cocok dengan master. Normalisasi hanya kapital, spasi, titik di
 *   akhir (contoh "CAKALELE." → CAKALELE), dan alias terkonfirmasi di PenyulangName
 *   (TANGGAMUS → TANGGAMUS 2); selain itu tidak ditebak.
 * - Section belum punya master canonical; nilai kosong/placeholder hanya peringatan. Section
 *   efektif memakai koreksi Admin (ApkukSectionService) bila Section sumber kosong/DUMMY.
 * - Laporan yang menunggu rekonsiliasi sumber (tidak ada di snapshot APKUK_RAW terakhir)
 *   tidak dapat dipilih, tetapi tetap ikut menentukan laporan terbaru per tiang agar
 *   laporan lama di tiang yang sama tidak mendadak menjadi layak.
 */
class ApkukCandidateService
{
    public const ISSUE_MENUNGGU_REKONSILIASI = 'MENUNGGU_REKONSILIASI_SUMBER';
    public const ISSUE_KURANG_KOSONG = 'KURANG_KOSONG';
    public const ISSUE_KURANG_NEGATIF = 'KURANG_NEGATIF';
    public const ISSUE_TIDAK_ADA_KEBUTUHAN = 'TIDAK_ADA_KEBUTUHAN';
    public const ISSUE_STATUS_BERTENTANGAN = 'STATUS_BERTENTANGAN';
    public const ISSUE_STATUS_TIDAK_DIKENAL = 'STATUS_TIDAK_DIKENAL';
    public const ISSUE_PENYULANG_TIDAK_DIKENAL = 'PENYULANG_TIDAK_DIKENAL';
    public const ISSUE_NO_TIANG_TIDAK_VALID = 'NO_TIANG_TIDAK_VALID';
    public const ISSUE_ADA_LAPORAN_LEBIH_BARU = 'ADA_LAPORAN_LEBIH_BARU';
    public const ISSUE_LAPORAN_GANDA_AMBIGU = 'LAPORAN_GANDA_AMBIGU';
    public const ISSUE_WO_AKTIF = 'WO_AKTIF';

    public const WARNING_SECTION_TIDAK_DIKETAHUI = 'SECTION_TIDAK_DIKETAHUI';
    public const WARNING_KOORDINAT_KOSONG = 'KOORDINAT_KOSONG';

    public const LABELS = [
        self::ISSUE_MENUNGGU_REKONSILIASI => 'Tidak ada di sumber terbaru; menunggu rekonsiliasi APKUK',
        self::ISSUE_KURANG_KOSONG => 'Kurang bh kosong',
        self::ISSUE_KURANG_NEGATIF => 'Kurang bh bernilai negatif',
        self::ISSUE_TIDAK_ADA_KEBUTUHAN => 'Kurang bh = 0 (tidak ada kebutuhan)',
        self::ISSUE_STATUS_BERTENTANGAN => 'Status sumber SELESAI tetapi kurang bh > 0',
        self::ISSUE_STATUS_TIDAK_DIKENAL => 'Status sumber kosong/tidak dikenal',
        self::ISSUE_PENYULANG_TIDAK_DIKENAL => 'Penyulang tidak cocok dengan master',
        self::ISSUE_NO_TIANG_TIDAK_VALID => 'No. tiang kosong/placeholder',
        self::ISSUE_ADA_LAPORAN_LEBIH_BARU => 'Ada laporan lebih baru untuk tiang yang sama',
        self::ISSUE_LAPORAN_GANDA_AMBIGU => 'Beberapa laporan terbaru (tanggal sama) untuk tiang yang sama',
        self::ISSUE_WO_AKTIF => 'Sudah ada WO APKUK aktif untuk tiang ini',
        self::WARNING_SECTION_TIDAK_DIKETAHUI => 'Section kosong/placeholder',
        self::WARNING_KOORDINAT_KOSONG => 'Koordinat kosong',
    ];

    /** Status sumber yang menyatakan APKUK belum lengkap. */
    public const NEED_STATUSES = ['BELUM EKSEKUSI', 'KURANG'];

    private const DONE_STATUS = 'SELESAI';

    private const PLACEHOLDERS = ['', '-', 'DUMMY'];

    private const KEY_MAX_LENGTH = 191;

    /**
     * @param  array<int, int>  $excludeApkukIds  diperlakukan seolah sudah bertanda source_missing_at
     *                                            (simulasi dampak apkuk:reconcile; tidak menulis apa pun)
     * @return Collection<int, ApkukCandidate> keyed by apkuks.id
     */
    public function evaluate(array $excludeApkukIds = []): Collection
    {
        $penyulangs = $this->penyulangLookup();
        $activeCodes = $this->activeWorkOrderCodes();
        $pending = array_flip(ApkukReconciliationItem::openApkukIds());
        $sectionOverrides = ApkukSectionService::activeOverrides();

        $rows = Apkuk::query()
            ->whereNull('source_missing_at')
            ->orderBy('id')
            ->get([
                'id', 'source_id', 'tanggal', 'petugas', 'penyulang', 'section', 'no_tiang',
                'naik_dari', 'terpasang_bh', 'kurang_bh', 'status', 'latitude', 'longitude',
                'source_row_number', 'sumber_input',
            ]);

        if ($excludeApkukIds !== []) {
            $excluded = array_flip($excludeApkukIds);
            $rows = $rows->reject(fn (Apkuk $row) => isset($excluded[$row->id]));
        }

        $prepared = $rows->map(function (Apkuk $row) use ($penyulangs): array {
            $rawPenyulang = self::normalizePenyulang($row->penyulang);
            $penyulang = $penyulangs[$rawPenyulang] ?? null;
            $pole = self::normalizePole($row->no_tiang);

            return [
                'row' => $row,
                'penyulang' => $penyulang,
                'pole' => $pole,
                'key' => self::targetKey($penyulang?->kode_penyulang ?? 'RAW:'.$rawPenyulang, $pole),
                'date' => $row->tanggal?->toDateString() ?? '',
            ];
        });

        // Tanggal terbaru dan jumlah laporan pada tanggal itu, per tiang.
        $latestByKey = [];
        foreach ($prepared as $item) {
            $current = $latestByKey[$item['key']] ?? null;
            if ($current === null || $item['date'] > $current['date']) {
                $latestByKey[$item['key']] = ['date' => $item['date'], 'count' => 1];
            } elseif ($item['date'] === $current['date']) {
                $latestByKey[$item['key']]['count']++;
            }
        }

        return $prepared->mapWithKeys(function (array $item) use ($latestByKey, $activeCodes, $pending, $sectionOverrides): array {
            /** @var Apkuk $row */
            $row = $item['row'];
            $issues = [];
            $warnings = [];

            if (isset($pending[$row->id])) {
                $issues[] = self::ISSUE_MENUNGGU_REKONSILIASI;
            }

            if (in_array($item['pole'], self::PLACEHOLDERS, true)) {
                $issues[] = self::ISSUE_NO_TIANG_TIDAK_VALID;
            }
            if ($item['penyulang'] === null) {
                $issues[] = self::ISSUE_PENYULANG_TIDAK_DIKENAL;
            }

            $kurang = $row->kurang_bh;
            $status = self::normalizeText($row->status);
            if ($kurang === null) {
                $issues[] = self::ISSUE_KURANG_KOSONG;
            } elseif ($kurang < 0) {
                $issues[] = self::ISSUE_KURANG_NEGATIF;
            } elseif ($kurang === 0) {
                $issues[] = self::ISSUE_TIDAK_ADA_KEBUTUHAN;
            } elseif ($status === self::DONE_STATUS) {
                $issues[] = self::ISSUE_STATUS_BERTENTANGAN;
            } elseif (! in_array($status, self::NEED_STATUSES, true)) {
                $issues[] = self::ISSUE_STATUS_TIDAK_DIKENAL;
            }

            $latest = $latestByKey[$item['key']];
            if ($item['date'] < $latest['date']) {
                $issues[] = self::ISSUE_ADA_LAPORAN_LEBIH_BARU;
            } elseif ($latest['count'] > 1) {
                $issues[] = self::ISSUE_LAPORAN_GANDA_AMBIGU;
            }

            $activeCode = $activeCodes[$item['key']] ?? null;
            if ($activeCode !== null) {
                $issues[] = self::ISSUE_WO_AKTIF;
            }

            // Section efektif: nilai sumber, atau koreksi Admin bila sumber kosong/DUMMY.
            $section = ApkukSectionService::effective($row->section, $sectionOverrides[$row->id] ?? null);
            if ($section['value'] === null) {
                $warnings[] = self::WARNING_SECTION_TIDAK_DIKETAHUI;
            }
            if ($row->latitude === null || $row->longitude === null) {
                $warnings[] = self::WARNING_KOORDINAT_KOSONG;
            }

            return [$row->id => new ApkukCandidate(
                apkuk: $row,
                targetKey: $item['key'],
                normalizedPole: $item['pole'],
                penyulang: $item['penyulang'],
                sectionName: $section['value'],
                issues: $issues,
                warnings: $warnings,
                activeWorkOrderCode: $activeCode,
                sectionOrigin: $section['origin'],
            )];
        });
    }

    public function find(int $apkukId): ?ApkukCandidate
    {
        return $this->evaluate()->get($apkukId);
    }

    /**
     * Kandidat valid diurutkan dari kebutuhan terbesar dan laporan tertua.
     *
     * @param  Collection<int, ApkukCandidate>  $candidates
     * @return Collection<int, ApkukCandidate>
     */
    public function filter(Collection $candidates, ?string $penyulangId, ?string $search, bool $eligibleOnly = true): Collection
    {
        $search = self::normalizeText($search);

        return $candidates
            ->filter(fn (ApkukCandidate $c) => ! $eligibleOnly || $c->isEligible())
            ->filter(fn (ApkukCandidate $c) => $penyulangId === null || $c->penyulang?->id === $penyulangId)
            ->filter(fn (ApkukCandidate $c) => $search === '' || str_contains($c->normalizedPole, $search)
                || str_contains(self::normalizeText($c->apkuk->section), $search)
                || str_contains(self::normalizeText($c->sectionName), $search))
            ->sortBy([
                fn (ApkukCandidate $a, ApkukCandidate $b) => $b->isEligible() <=> $a->isEligible(),
                fn (ApkukCandidate $a, ApkukCandidate $b) => ($b->apkuk->kurang_bh ?? PHP_INT_MIN) <=> ($a->apkuk->kurang_bh ?? PHP_INT_MIN),
                fn (ApkukCandidate $a, ApkukCandidate $b) => (string) $a->apkuk->tanggal <=> (string) $b->apkuk->tanggal,
                fn (ApkukCandidate $a, ApkukCandidate $b) => $a->normalizedPole <=> $b->normalizedPole,
            ])
            ->values();
    }

    /**
     * @param  Collection<int, ApkukCandidate>  $candidates
     * @return array{total: int, eligible: int, issues: array<string, int>}
     */
    public function summary(Collection $candidates): array
    {
        $issues = [];
        foreach ($candidates as $candidate) {
            foreach ($candidate->issues as $code) {
                $issues[$code] = ($issues[$code] ?? 0) + 1;
            }
        }
        arsort($issues);

        return [
            'total' => $candidates->count(),
            'eligible' => $candidates->filter(fn (ApkukCandidate $c) => $c->isEligible())->count(),
            'issues' => $issues,
        ];
    }

    public static function normalizeText(?string $value): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));
    }

    public static function normalizePole(?string $value): string
    {
        return self::normalizeText($value);
    }

    public static function normalizePenyulang(?string $value): string
    {
        return PenyulangName::normalize($value);
    }

    public static function targetKey(string $penyulangCode, string $pole): string
    {
        $key = self::normalizeText($penyulangCode).'|'.$pole;

        return mb_strlen($key) > self::KEY_MAX_LENGTH ? 'SHA256:'.hash('sha256', $key) : $key;
    }

    /**
     * @return array<string, Penyulang>
     */
    private function penyulangLookup(): array
    {
        $lookup = [];
        foreach (Penyulang::query()->where('is_active', true)->get() as $penyulang) {
            foreach (PenyulangName::sourceNames($penyulang) as $name) {
                $lookup[$name] ??= $penyulang;
            }
        }

        return $lookup;
    }

    /**
     * @return array<string, string> active_target_key => kode WO
     */
    private function activeWorkOrderCodes(): array
    {
        return WorkOrderApkukTarget::query()
            ->join('work_orders', 'work_orders.id', '=', 'work_order_apkuk_targets.work_order_id')
            ->whereNotNull('work_order_apkuk_targets.active_target_key')
            ->pluck('work_orders.code', 'work_order_apkuk_targets.active_target_key')
            ->all();
    }
}
