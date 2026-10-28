<?php

namespace App\Services\Apkuk;

use App\Models\Apkuk;
use App\Models\ApkukSectionOverride;
use App\Models\AuditLog;
use App\Models\Penyulang;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\PenyulangName;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Section laporan APKUK: nilai sumber (apkuks.section dari SPS BOS) dan koreksi internal Admin.
 *
 * Aturan (keputusan pengguna 28 Okt 2026):
 * - Koreksi hanya untuk laporan yang Section sumbernya kosong, "-", atau DUMMY. Section sumber yang
 *   terisi diperbaiki pemilik data di SPS BOS, bukan ditimpa aplikasi.
 * - Nilai koreksi wajib ada di referensi penyulang laporan itu (penyulang canonical yang cocok
 *   master): pemutus SECTION_PIC_RAW yang dipetakan ke penyulang tersebut (fill-down kolom PENYULANG,
 *   aturan yang sama dengan SyncCoreTopologyMasters), atau Section laporan APKUK lain pada penyulang
 *   yang sama selama SECTION_PIC_RAW tidak memetakannya ke penyulang lain. Master Section canonical
 *   belum ada, jadi batas Section tidak ditebak dan dasar referensinya dicatat.
 * - Section efektif = koreksi aktif selama sumber masih kosong/DUMMY. Bila sumber kemudian terisi,
 *   nilai sumber yang berlaku dan selisihnya ditandai untuk ditinjau.
 * - Koreksi tidak mengubah WO yang sudah ada (section_name WO adalah snapshot saat dibuat).
 */
final class ApkukSectionService
{
    public const PLACEHOLDERS = ['', '-', 'DUMMY'];

    public const ORIGIN_SOURCE = 'SUMBER';

    public const ORIGIN_OVERRIDE = 'KOREKSI_ADMIN';

    public const ORIGIN_NONE = 'TIDAK_DIKETAHUI';

    private const MIN_REASON = 10;

    public function __construct(private readonly AuditLogger $audit) {}

    public static function normalize(?string $value): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));
    }

    public static function isPlaceholder(?string $value): bool
    {
        return in_array(self::normalize($value), self::PLACEHOLDERS, true);
    }

    /**
     * @return array{value: ?string, origin: string, source_conflict: bool}
     */
    public static function effective(?string $sourceSection, ?string $activeOverride): array
    {
        if (! self::isPlaceholder($sourceSection)) {
            return [
                'value' => trim((string) $sourceSection),
                'origin' => self::ORIGIN_SOURCE,
                'source_conflict' => $activeOverride !== null && self::normalize($activeOverride) !== self::normalize($sourceSection),
            ];
        }

        return $activeOverride !== null
            ? ['value' => $activeOverride, 'origin' => self::ORIGIN_OVERRIDE, 'source_conflict' => false]
            : ['value' => null, 'origin' => self::ORIGIN_NONE, 'source_conflict' => false];
    }

    /**
     * Koreksi aktif: apkuk_id => Section koreksi.
     *
     * @param  array<int, int>|null  $apkukIds
     * @return array<int, string>
     */
    public static function activeOverrides(?array $apkukIds = null): array
    {
        return ApkukSectionOverride::query()
            ->where('status', ApkukSectionOverride::STATUS_ACTIVE)
            ->when($apkukIds !== null, fn ($query) => $query->whereIn('apkuk_id', $apkukIds))
            ->pluck('section_name', 'apkuk_id')
            ->all();
    }

    /**
     * Section yang sah sebagai nilai koreksi untuk laporan ini, dikunci dengan nilai ternormalisasi.
     *
     * @return array<string, array{value: string, basis: string}>
     */
    public function references(Apkuk $apkuk): array
    {
        // Tanpa penyulang master yang cocok tidak ada referensi yang dapat divalidasi.
        $penyulang = PenyulangName::canonical($apkuk->penyulang);
        $isMaster = $penyulang !== '' && Penyulang::query()->where('is_active', true)->get()
            ->contains(fn (Penyulang $master) => in_array($penyulang, PenyulangName::sourceNames($master), true));
        if (! $isMaster) {
            return [];
        }

        $references = [];
        $add = function (?string $raw, string $basis) use (&$references): void {
            if (self::isPlaceholder($raw)) {
                return;
            }
            $references[self::normalize($raw)] ??= ['value' => trim((string) preg_replace('/\s+/u', ' ', (string) $raw)), 'basis' => $basis];
        };

        $sectionPic = self::sectionPicFeeders();
        foreach ($sectionPic as $mapping) {
            if (isset($mapping['feeders'][$penyulang])) {
                $add($mapping['value'], ApkukSectionOverride::BASIS_SECTION_PIC);
            }
        }

        DB::table('apkuks')
            ->whereIn(DB::raw(PenyulangName::sqlNormalized('penyulang')), PenyulangName::spellings($penyulang))
            ->whereNotNull('section')
            ->distinct()
            ->pluck('section')
            // Pemutus yang dikenal SECTION_PIC_RAW hanya berlaku untuk penyulang pemetaannya (yang cocok
            // sudah ditambahkan di atas); bila dipetakan ke penyulang lain, nilai sumber ini bertentangan.
            ->reject(fn (?string $section) => isset($sectionPic[self::normalize($section)]))
            ->each(fn (?string $section) => $add($section, ApkukSectionOverride::BASIS_SAME_PENYULANG));

        ksort($references);

        return $references;
    }

    /**
     * Pemutus SECTION_PIC_RAW beserta penyulang canonical-nya. Kolom PENYULANG hanya terisi pada
     * baris pertama tiap kelompok, sehingga diturunkan ke baris berikutnya (fill-down, urutan baris
     * sumber); baris sebelum penyulang pertama tidak dipetakan.
     *
     * @return array<string, array{value: string, feeders: array<string, true>}>
     */
    private static function sectionPicFeeders(): array
    {
        $mapping = [];
        $feeder = null;
        foreach (DB::table('section_pic_source_rows')->orderBy('source_row_number')->orderBy('id')->get(['penyulang_raw', 'pemutus_raw']) as $row) {
            if (trim((string) $row->penyulang_raw) !== '') {
                $feeder = PenyulangName::canonical($row->penyulang_raw);
            }
            if ($feeder === null || self::isPlaceholder($row->pemutus_raw)) {
                continue;
            }
            $key = self::normalize($row->pemutus_raw);
            $mapping[$key]['value'] ??= trim((string) preg_replace('/\s+/u', ' ', (string) $row->pemutus_raw));
            $mapping[$key]['feeders'][$feeder] = true;
        }

        return $mapping;
    }

    public function set(Apkuk $apkuk, User $admin, string $section, string $reason): ApkukSectionOverride
    {
        $reason = $this->reason($reason);
        if (! self::isPlaceholder($apkuk->section)) {
            throw ValidationException::withMessages([
                'section_name' => "Section sumber sudah terisi ({$apkuk->section}). Koreksi internal hanya untuk Section kosong/DUMMY; perbaikan nilai yang terisi dilakukan pemilik data di SPS BOS.",
            ]);
        }

        $reference = self::isPlaceholder($section) ? null : ($this->references($apkuk)[self::normalize($section)] ?? null);
        if ($reference === null) {
            throw ValidationException::withMessages([
                'section_name' => 'Pilih Section dari referensi yang tersedia (pemutus SECTION_PIC_RAW atau Section laporan lain pada penyulang yang sama).',
            ]);
        }

        try {
            return DB::transaction(function () use ($apkuk, $admin, $reason, $reference): ApkukSectionOverride {
                $current = ApkukSectionOverride::query()->where('apkuk_id', $apkuk->id)->lockForUpdate()->first();
                if ($current?->isActive() && self::normalize($current->section_name) === self::normalize($reference['value'])) {
                    throw ValidationException::withMessages(['section_name' => 'Section ini sudah menjadi koreksi yang berlaku.']);
                }

                $before = $current ? $this->state($current) : null;
                $override = $current ?? new ApkukSectionOverride(['apkuk_id' => $apkuk->id, 'created_by_user_id' => $admin->getKey()]);
                $override->fill([
                    'apkuk_source_id' => $apkuk->source_id,
                    'section_name' => $reference['value'],
                    'source_section_at_change' => $apkuk->section,
                    'reference_basis' => $reference['basis'],
                    'status' => ApkukSectionOverride::STATUS_ACTIVE,
                    'reason' => $reason,
                    'updated_by_user_id' => $admin->getKey(),
                ])->save();

                $this->audit->record('apkuk_section_override', $apkuk->id, $this->label($apkuk), $current === null ? AuditLog::ACTION_CREATED : AuditLog::ACTION_UPDATED, $before, $this->state($override), $admin, $reason);

                return $override;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['section_name' => 'Koreksi Section laporan ini baru saja disimpan Admin lain. Muat ulang halaman untuk melihat nilai terbaru.']);
        }
    }

    public function revoke(Apkuk $apkuk, User $admin, string $reason): ApkukSectionOverride
    {
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($apkuk, $admin, $reason): ApkukSectionOverride {
            $current = ApkukSectionOverride::query()->where('apkuk_id', $apkuk->id)->lockForUpdate()->first();
            if (! $current?->isActive()) {
                throw ValidationException::withMessages(['reason' => 'Tidak ada koreksi Section yang berlaku untuk laporan ini.']);
            }

            $before = $this->state($current);
            $current->fill([
                'section_name' => null,
                'source_section_at_change' => $apkuk->section,
                'reference_basis' => null,
                'status' => ApkukSectionOverride::STATUS_REVOKED,
                'reason' => $reason,
                'updated_by_user_id' => $admin->getKey(),
            ])->save();

            $this->audit->record('apkuk_section_override', $apkuk->id, $this->label($apkuk), AuditLog::ACTION_REVOKED, $before, $this->state($current), $admin, $reason);

            return $current;
        });
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < self::MIN_REASON) {
            throw ValidationException::withMessages(['reason' => 'Alasan koreksi wajib diisi (minimal '.self::MIN_REASON.' karakter), mis. sumber informasi Section.']);
        }

        return $reason;
    }

    /**
     * @return array<string, mixed>
     */
    private function state(ApkukSectionOverride $override): array
    {
        return [
            'section_koreksi' => $override->section_name,
            'status' => $override->status,
            'dasar_referensi' => $override->reference_basis,
            'section_sumber' => $override->source_section_at_change,
        ];
    }

    private function label(Apkuk $apkuk): string
    {
        return "Tiang {$apkuk->no_tiang} · ID {$apkuk->source_id}";
    }
}
