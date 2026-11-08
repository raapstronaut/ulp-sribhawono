<?php

namespace App\Services\Work;

use App\Models\InspectionFinding;
use App\Models\Keypoint;
use App\Models\Penyulang;
use App\Models\WorkOrder;
use App\Models\WorkOrderLocationTarget;
use App\Support\PenyulangName;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menyusun snapshot target lokasi WO dari sumber yang ada: keypoint (aset jaringan),
 * pohon (ID POHON VEGETASI_RAW), lokasi WO asal (limpahan), atau lokasi temuan inspeksi.
 * Lokasi tidak ditebak: penyulang sumber harus cocok dengan penyulang WO.
 */
class LocationTargetResolver
{
    /** Namespace kunci target pohon; dipisah dari format lama `VEGETASI:{id baris staging}`. */
    public const VEGETATION_KEY_PREFIX = 'POHON:';

    /** Batas kolom source_ref; ID POHON sumber saat ini 6 digit. */
    private const ID_POHON_MAX_LENGTH = 64;

    public static function vegetationTargetKey(?string $idPohon): ?string
    {
        $id = trim((string) $idPohon);

        return $id === '' ? null : self::VEGETATION_KEY_PREFIX.$id;
    }

    /**
     * @return array<string, mixed> atribut WorkOrderLocationTarget tanpa work_order_id dan active_target_key
     */
    public function keypoint(int $keypointId, Penyulang $penyulang): array
    {
        $keypoint = Keypoint::query()->find($keypointId);
        if ($keypoint === null) {
            throw ValidationException::withMessages(['keypoint_id' => 'Aset jaringan (keypoint) tidak ditemukan.']);
        }
        $this->assertPenyulang($keypoint->penyulang, $penyulang, 'keypoint_id', 'Aset');

        return [
            'target_type' => WorkOrderLocationTarget::TYPE_KEYPOINT,
            'source_ref' => (string) $keypoint->id,
            'target_key' => 'KEYPOINT:'.$keypoint->id,
            'label' => trim(($keypoint->nama ?: 'Keypoint #'.$keypoint->id)),
            'asset_type' => $keypoint->jenis,
            'penyulang_source' => $keypoint->penyulang,
            'section_source' => null,
            'latitude' => $keypoint->hasCoordinates() ? $keypoint->latitude : null,
            'longitude' => $keypoint->hasCoordinates() ? $keypoint->longitude : null,
            'snapshot' => [
                'keypoint_id' => $keypoint->id,
                'legacy_key' => $keypoint->legacy_key,
                'jenis' => $keypoint->jenis,
                'nama' => $keypoint->nama,
                'note' => $keypoint->note,
                'sumber_input' => $keypoint->sumber_input,
            ],
        ];
    }

    /**
     * Target ROW diidentifikasi dengan `ID POHON` (VEGETASI_RAW kolom AB), bukan ID baris staging
     * yang berubah setiap import `--replace`. Baris staging dipakai untuk memilih titik dan
     * disimpan di snapshot sebagai jejak asal.
     *
     * @param  string|null  $expectedIdPohon  ID POHON yang dilihat Admin saat memilih; menolak bila baris staging sudah berganti isi
     * @return array<string, mixed>
     */
    public function vegetation(int $vegetationId, Penyulang $penyulang, ?string $expectedIdPohon = null): array
    {
        $row = DB::table('vegetasi_source_rows')->where('id', $vegetationId)->first();
        if ($row === null) {
            throw ValidationException::withMessages(['vegetation_id' => 'Titik vegetasi tidak ditemukan.']);
        }

        $idPohon = trim((string) $row->id_pohon_raw);
        if ($idPohon === '') {
            throw ValidationException::withMessages(['vegetation_id' => 'Titik vegetasi ini tidak memiliki ID POHON di sumber, sehingga tidak dapat menjadi target ROW yang stabil.']);
        }
        if (mb_strlen($idPohon) > self::ID_POHON_MAX_LENGTH) {
            throw ValidationException::withMessages(['vegetation_id' => 'ID POHON di sumber terlalu panjang untuk dijadikan identitas target.']);
        }
        if ($expectedIdPohon !== null && trim($expectedIdPohon) !== $idPohon) {
            throw ValidationException::withMessages(['vegetation_id' => 'Data vegetasi berubah sejak halaman dibuka (ID POHON berbeda). Muat ulang halaman lalu pilih titik kembali.']);
        }
        $this->assertPenyulang($row->penyulang_raw, $penyulang, 'vegetation_id', 'Titik vegetasi');
        $this->assertSingleTree($idPohon);

        [$latitude, $longitude] = self::coordinates($row->latitude_raw, $row->longitude_raw);
        $label = collect([$row->id_pohon_raw, $row->nama_pohon_raw, $row->nama_tiang_raw])
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->implode(' · ');

        return [
            'target_type' => WorkOrderLocationTarget::TYPE_VEGETASI,
            'source_ref' => $idPohon,
            'target_key' => self::vegetationTargetKey($idPohon),
            'label' => $label !== '' ? $label : 'Pohon '.$idPohon,
            'asset_type' => $row->jenis_pohon_raw,
            'penyulang_source' => $row->penyulang_raw,
            'section_source' => $row->section_raw,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'snapshot' => [
                'identity' => 'ID_POHON',
                'vegetasi_source_row_id' => $row->id,
                'vegetasi_source_row_number' => $row->source_row_number,
                'id_pohon' => $row->id_pohon_raw,
                'id_tiang' => $row->id_tiang_raw,
                'nama_tiang' => $row->nama_tiang_raw,
                'kondisi' => $row->kondisi_raw,
                'pertumbuhan' => $row->pertumbuhan_pohon_raw,
                'jarak_pohon' => $row->jarak_pohon_saat_ini_raw ?: $row->jarak_pohon_raw,
                'jarak_aman' => $row->jarak_aman_raw,
                'tanggal_periksa' => $row->tanggal_periksa_raw,
                'tanggal_perkiraan_bahaya' => $row->tanggal_perkiraan_bahaya_raw,
            ],
        ];
    }

    /**
     * Lokasi child WO limpahan disalin dari WO asal.
     *
     * @return array<string, mixed>
     */
    public function fromWorkOrder(WorkOrder $source): array
    {
        $source->loadMissing(['apkukTarget', 'locationTarget']);
        $apkuk = $source->apkukTarget;
        $location = $source->locationTarget;

        return [
            'target_type' => WorkOrderLocationTarget::TYPE_WORK_ORDER,
            'source_ref' => (string) $source->getKey(),
            'target_key' => 'WO:'.$source->getKey(),
            'label' => $apkuk?->no_tiang ?? $location?->label ?? $source->code,
            'asset_type' => $apkuk ? 'TIANG' : $location?->asset_type,
            'penyulang_source' => $apkuk?->penyulang_source ?? $location?->penyulang_source,
            'section_source' => $apkuk?->section_source ?? $location?->section_source ?? $source->section_name,
            'latitude' => $apkuk?->latitude ?? $location?->latitude,
            'longitude' => $apkuk?->longitude ?? $location?->longitude,
            'snapshot' => [
                'source_work_order_id' => $source->getKey(),
                'source_work_order_code' => $source->code,
                'source_team_type' => $source->team_type,
                'source_work_type' => $source->work_type,
                'source_target_type' => $apkuk ? 'APKUK' : $location?->target_type,
                'source_target_ref' => $apkuk ? (string) $apkuk->apkuk_id : $location?->source_ref,
            ],
        ];
    }

    /**
     * WO tindak lanjut temuan inspeksi: memakai aset keypoint bila temuan menyebutnya,
     * selain itu koordinat temuan.
     *
     * @return array<string, mixed>
     */
    public function fromFinding(InspectionFinding $finding, Penyulang $penyulang): array
    {
        if ($finding->keypoint_id !== null) {
            $target = $this->keypoint($finding->keypoint_id, $penyulang);
            $target['snapshot']['inspection_finding_id'] = $finding->getKey();

            return $target;
        }

        if ($finding->latitude === null || $finding->longitude === null) {
            throw ValidationException::withMessages(['follow_up' => 'Temuan tidak memiliki aset maupun koordinat, sehingga lokasi WO tindak lanjut tidak dapat ditentukan.']);
        }

        return [
            'target_type' => WorkOrderLocationTarget::TYPE_INSPEKSI,
            'source_ref' => (string) $finding->getKey(),
            'target_key' => 'INSPEKSI:'.$finding->getKey(),
            'label' => $finding->asset_label ?: ('Temuan '.$finding->finding_code),
            'asset_type' => null,
            'penyulang_source' => $penyulang->kode_penyulang,
            'section_source' => $finding->inspection?->section_name,
            'latitude' => $finding->latitude,
            'longitude' => $finding->longitude,
            'snapshot' => [
                'inspection_finding_id' => $finding->getKey(),
                'finding_code' => $finding->finding_code,
                'description' => $finding->description,
            ],
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    public static function coordinates(?string $latitude, ?string $longitude): array
    {
        $parse = function (?string $value): ?float {
            $value = trim((string) $value);
            if ($value === '') {
                return null;
            }
            // Format sumber kadang memakai koma desimal ("-5,2").
            if (! str_contains($value, '.') && substr_count($value, ',') === 1) {
                $value = str_replace(',', '.', $value);
            }

            return preg_match('/^[+-]?\d+(\.\d+)?$/', $value) ? (float) $value : null;
        };

        $lat = $parse($latitude);
        $lon = $parse($longitude);
        if ($lat === null || $lon === null || abs($lat) > 90 || abs($lon) > 180 || ($lat == 0.0 && $lon == 0.0)) {
            return [null, null];
        }

        return [number_format($lat, 7, '.', ''), number_format($lon, 7, '.', '')];
    }

    public static function penyulangMatches(?string $sourceName, Penyulang $penyulang): bool
    {
        $source = PenyulangName::normalize($sourceName);

        return $source !== '' && in_array($source, PenyulangName::sourceNames($penyulang), true);
    }

    /**
     * ID POHON berulang di staging hanya sah bila semua barisnya menyatakan pohon yang sama
     * (mis. import tanpa --replace). Bila penyulang/ID tiang berbeda, identitasnya ambigu dan
     * tidak ditebak.
     */
    private function assertSingleTree(string $idPohon): void
    {
        $rows = DB::table('vegetasi_source_rows')
            ->where(DB::raw('trim(id_pohon_raw)'), $idPohon)
            ->get(['penyulang_raw', 'id_tiang_raw']);
        $variants = $rows->map(fn ($row) => PenyulangName::canonical($row->penyulang_raw).'|'.trim((string) $row->id_tiang_raw))->unique();

        if ($variants->count() > 1) {
            throw ValidationException::withMessages([
                'vegetation_id' => "ID POHON {$idPohon} tercatat pada {$rows->count()} baris sumber dengan penyulang/ID tiang berbeda. Klarifikasi ke pemilik data SPS BOS sebelum dibuatkan WO.",
            ]);
        }
    }

    private function assertPenyulang(?string $sourceName, Penyulang $penyulang, string $field, string $what): void
    {
        if (! self::penyulangMatches($sourceName, $penyulang)) {
            throw ValidationException::withMessages([
                $field => "{$what} tercatat pada penyulang '".($sourceName ?: '-')."', bukan {$penyulang->nama_penyulang}. Pilih aset pada penyulang yang sama.",
            ]);
        }
    }
}
