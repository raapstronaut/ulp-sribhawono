<?php

namespace App\Services\Apkuk;

use App\Models\Posko;
use App\Support\PenyulangName;
use Illuminate\Support\Facades\DB;

/**
 * Pemetaan SECTION_PIC_RAW: pemutus (Section) → penyulang → POSKO PIC.
 *
 * Kolom PENYULANG hanya terisi pada baris pertama tiap kelompok sehingga diturunkan ke baris
 * berikutnya (fill-down menurut urutan baris sumber), aturan yang sama dengan
 * SyncCoreTopologyMasters; baris sebelum penyulang pertama tidak dipetakan. Posko hanya diambil
 * dari pemetaan yang terbukti tunggal; tidak pernah ditebak dari penyulang (satu penyulang dapat
 * dilayani beberapa Posko). Nilai sumber tidak diubah.
 */
final class SectionPoskoMap
{
    public const REASON_SECTION_KOSONG = 'SECTION_KOSONG';

    public const REASON_SECTION_DUMMY = 'SECTION_DUMMY';

    public const REASON_PENYULANG_TIDAK_DIKENAL = 'PENYULANG_TIDAK_DIKENAL';

    public const REASON_SECTION_TIDAK_DIKENAL = 'SECTION_TIDAK_DIKENAL';

    public const REASON_SECTION_PENYULANG_LAIN = 'SECTION_PENYULANG_LAIN';

    public const REASON_SECTION_MULTI_PENYULANG = 'SECTION_MULTI_PENYULANG';

    public const REASON_POSKO_PIC_KOSONG = 'POSKO_PIC_KOSONG';

    public const REASON_POSKO_TIDAK_DIKENAL = 'POSKO_TIDAK_DIKENAL';

    public const REASON_POSKO_AMBIGU = 'POSKO_AMBIGU';

    public const LABELS = [
        self::REASON_SECTION_KOSONG => 'Section kosong di sumber dan belum dikoreksi',
        self::REASON_SECTION_DUMMY => 'Section DUMMY/placeholder dan belum dikoreksi',
        self::REASON_PENYULANG_TIDAK_DIKENAL => 'Penyulang tidak cocok dengan master',
        self::REASON_SECTION_TIDAK_DIKENAL => 'Section tidak ada di SECTION_PIC_RAW, sehingga Posko PIC tidak dapat dibuktikan',
        self::REASON_SECTION_PENYULANG_LAIN => 'Section dipetakan SECTION_PIC_RAW ke penyulang lain (bertentangan)',
        self::REASON_SECTION_MULTI_PENYULANG => 'Section tercatat di lebih dari satu penyulang pada SECTION_PIC_RAW (ambigu)',
        self::REASON_POSKO_PIC_KOSONG => 'POSKO PIC kosong pada SECTION_PIC_RAW',
        self::REASON_POSKO_TIDAK_DIKENAL => 'POSKO PIC tidak cocok dengan master Posko aktif',
        self::REASON_POSKO_AMBIGU => 'Section memiliki lebih dari satu POSKO PIC (ambigu)',
    ];

    /** Ejaan Posko yang dikonfirmasi pengguna (28 Okt 2026): nama resmi SRIBHAWONO. */
    private const POSKO_ALIASES = ['SRIBAWONO' => 'SRIBHAWONO'];

    /**
     * @param  array<string, array{value: string, feeders: array<string, array<string, true>>}>  $sections  section ternormalisasi
     * @param  array<string, Posko>  $poskos  nama Posko ternormalisasi
     */
    private function __construct(private readonly array $sections, private readonly array $poskos) {}

    public static function load(): self
    {
        $sections = [];
        $feeder = null;
        foreach (DB::table('section_pic_source_rows')->orderBy('source_row_number')->orderBy('id')->get(['penyulang_raw', 'pemutus_raw', 'posko_pic_raw']) as $row) {
            if (trim((string) $row->penyulang_raw) !== '') {
                $feeder = PenyulangName::canonical($row->penyulang_raw);
            }
            if ($feeder === null || ApkukSectionService::isPlaceholder($row->pemutus_raw)) {
                continue;
            }
            $key = ApkukSectionService::normalize($row->pemutus_raw);
            $sections[$key]['value'] ??= trim((string) preg_replace('/\s+/u', ' ', (string) $row->pemutus_raw));
            $sections[$key]['feeders'][$feeder][self::poskoKey($row->posko_pic_raw)] = true;
        }

        $poskos = [];
        foreach (Posko::query()->where('is_active', true)->get() as $posko) {
            $poskos[self::poskoKey($posko->nama_posko)] ??= $posko;
            $poskos[self::poskoKey($posko->kode_posko)] ??= $posko;
        }

        return new self($sections, $poskos);
    }

    /**
     * Pemutus yang dipetakan ke penyulang ini: section ternormalisasi => nilai tampilan.
     *
     * @return array<string, string>
     */
    public function sectionsOf(string $canonicalPenyulang): array
    {
        $result = [];
        foreach ($this->sections as $key => $section) {
            if (isset($section['feeders'][$canonicalPenyulang])) {
                $result[$key] = $section['value'];
            }
        }

        return $result;
    }

    public function knows(?string $section): bool
    {
        return isset($this->sections[ApkukSectionService::normalize($section)]);
    }

    /**
     * Posko penanggung jawab untuk Section efektif pada penyulang canonical (sudah cocok master).
     *
     * @return array{posko: ?Posko, reason: ?string, section: ?string}
     */
    public function resolve(?string $canonicalPenyulang, ?string $effectiveSection): array
    {
        $section = ApkukSectionService::normalize($effectiveSection);
        $fail = fn (string $reason) => ['posko' => null, 'reason' => $reason, 'section' => $effectiveSection];

        if ($canonicalPenyulang === null || $canonicalPenyulang === '') {
            return $fail(self::REASON_PENYULANG_TIDAK_DIKENAL);
        }
        if ($section === '' || $section === '-') {
            return $fail(self::REASON_SECTION_KOSONG);
        }
        if (ApkukSectionService::isPlaceholder($section)) {
            return $fail(self::REASON_SECTION_DUMMY);
        }

        $feeders = $this->sections[$section]['feeders'] ?? null;
        if ($feeders === null) {
            return $fail(self::REASON_SECTION_TIDAK_DIKENAL);
        }
        if (! isset($feeders[$canonicalPenyulang])) {
            return $fail(self::REASON_SECTION_PENYULANG_LAIN);
        }
        if (count($feeders) > 1) {
            return $fail(self::REASON_SECTION_MULTI_PENYULANG);
        }

        $pics = array_keys($feeders[$canonicalPenyulang]);
        if (count($pics) > 1) {
            return $fail(self::REASON_POSKO_AMBIGU);
        }
        if ($pics[0] === '') {
            return $fail(self::REASON_POSKO_PIC_KOSONG);
        }
        $posko = $this->poskos[$pics[0]] ?? null;
        if ($posko === null) {
            return $fail(self::REASON_POSKO_TIDAK_DIKENAL);
        }

        return ['posko' => $posko, 'reason' => null, 'section' => $this->sections[$section]['value']];
    }

    private static function poskoKey(?string $value): string
    {
        $key = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));

        return self::POSKO_ALIASES[$key] ?? $key;
    }
}
