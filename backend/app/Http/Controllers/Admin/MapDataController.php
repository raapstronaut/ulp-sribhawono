<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApkukReconciliationItem;
use App\Support\OperationalArea;
use App\Support\PenyulangName;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Data read-only untuk Peta Jaringan Admin. Tidak melakukan geocoding/tebakan lokasi.
 * `penyulang` berisi nama canonical (filter dan dropdown satu entri per penyulang, alias
 * TANGGAMUS → TANGGAMUS 2); `penyulang_sumber` berisi nilai mentah sumber.
 *
 * Titik dengan koordinat bernilai 0 atau di luar wilayah operasional tidak dikirim sebagai marker,
 * sehingga tidak ikut menentukan batas tampilan peta. Nilainya dilaporkan apa adanya di
 * `coordinate_issues`; database dan sumber tidak diubah.
 */
class MapDataController extends Controller
{
    public function index(): JsonResponse
    {
        $feeder = fn (?string $raw): array => ['penyulang' => PenyulangName::canonical($raw) ?: null, 'penyulang_sumber' => $raw];
        $mappable = fn (object $point): bool => OperationalArea::issue((float) $point->latitude, (float) $point->longitude) === null;
        $issue = function (object $point) use ($feeder): array {
            $code = OperationalArea::issue((float) $point->latitude, (float) $point->longitude);

            return [
                ...$feeder($point->penyulang),
                'latitude' => (float) $point->latitude,
                'longitude' => (float) $point->longitude,
                'issue' => $code,
                'issue_label' => OperationalArea::ISSUE_LABELS[$code],
                'source_row_number' => $point->source_row_number,
            ];
        };

        [$keypointRows, $keypointIssues] = DB::table('keypoints')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('id')
            ->get(['id', 'penyulang', 'jenis', 'nama', 'latitude', 'longitude', 'note', 'source_row_number'])
            ->partition($mappable);

        $keypoints = $keypointRows
            ->map(fn ($point): array => [
                'id' => (string) $point->id,
                ...$feeder($point->penyulang),
                'jenis' => $point->jenis,
                'nama' => $point->nama,
                'latitude' => (float) $point->latitude,
                'longitude' => (float) $point->longitude,
                'note' => $point->note,
            ])
            ->values();

        // source_missing_at menandai laporan yang sudah diarsipkan (histori). Laporan yang menunggu
        // rekonsiliasi (tidak ada di snapshot sumber terakhir) tetap tampil sebagai marker bertanda,
        // tetapi tidak dihitung sebagai APKUK aktif.
        $apkukTampil = DB::table('apkuks')->whereNull('source_missing_at');
        $pending = array_flip(ApkukReconciliationItem::openApkukIds());
        $pendingTotal = $pending === [] ? 0 : (clone $apkukTampil)->whereIn('id', array_keys($pending))->count();
        $apkukTotal = (clone $apkukTampil)->count() - $pendingTotal;

        [$apkukRows, $apkukIssues] = (clone $apkukTampil)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderByDesc('tanggal')
            ->orderByDesc('id')
            ->get([
                'id', 'source_id', 'tanggal', 'penyulang', 'no_tiang',
                'status', 'kurang_bh', 'terpasang_bh', 'latitude', 'longitude', 'source_row_number',
            ])
            ->partition($mappable);

        $apkuks = $apkukRows
            ->map(fn ($point): array => [
                'id' => (string) $point->id,
                'reconciliation_pending' => isset($pending[(int) $point->id]),
                'source_id' => $point->source_id,
                'tanggal' => $point->tanggal,
                ...$feeder($point->penyulang),
                'no_tiang' => $point->no_tiang,
                'status' => $point->status,
                'kurang_bh' => $point->kurang_bh,
                'terpasang_bh' => $point->terpasang_bh,
                'latitude' => (float) $point->latitude,
                'longitude' => (float) $point->longitude,
            ])
            ->values();

        // Gangguan belum menyimpan koordinat. Tampilkan dalam daftar, bukan marker semu.
        $gangguanTotal = DB::table('gangguans')->count();
        $gangguans = DB::table('gangguans')
            ->orderByDesc('tanggal_kejadian')
            ->orderBy('id_gangguan')
            ->get([
                'id_gangguan', 'tanggal_kejadian', 'penyulang', 'peralatan',
                'no_tiang', 'sifat_gangguan', 'penyebab',
            ])
            ->map(fn ($row): array => [
                'id' => $row->id_gangguan,
                'tanggal' => $row->tanggal_kejadian,
                ...$feeder($row->penyulang),
                'peralatan' => $row->peralatan,
                'no_tiang' => $row->no_tiang,
                'sifat' => $row->sifat_gangguan,
                'penyebab' => $row->penyebab,
            ])
            ->values();

        $coordinateIssues = $keypointIssues
            ->map(fn ($point): array => [
                'uid' => "keypoint-{$point->id}",
                'layer' => 'keypoint',
                'label' => $point->nama,
                'jenis' => $point->jenis,
                ...$issue($point),
            ])
            ->concat($apkukIssues->map(fn ($point): array => [
                'uid' => "apkuk-{$point->id}",
                'layer' => 'apkuk',
                'label' => $point->no_tiang ?: ($point->source_id ?: "APKUK {$point->id}"),
                'source_id' => $point->source_id,
                'no_tiang' => $point->no_tiang,
                'status' => $point->status,
                'tanggal' => $point->tanggal,
                'reconciliation_pending' => isset($pending[(int) $point->id]),
                ...$issue($point),
            ]))
            ->values();
        $apkukInvalid = $apkukIssues->reject(fn ($point) => isset($pending[(int) $point->id]))->count();

        return response()->json([
            'keypoints' => $keypoints,
            'apkuks' => $apkuks,
            'gangguans' => $gangguans,
            'coordinate_issues' => $coordinateIssues,
            'meta' => [
                'keypoint_mapped' => $keypoints->count(),
                'keypoint_invalid_coordinates' => $keypointIssues->count(),
                'apkuk_active_total' => $apkukTotal,
                'apkuk_mapped' => $apkukMapped = $apkuks->where('reconciliation_pending', false)->count(),
                'apkuk_invalid_coordinates' => $apkukInvalid,
                'apkuk_without_coordinates' => $apkukTotal - $apkukMapped - $apkukInvalid,
                'operational_bounds' => OperationalArea::bounds(),
                'apkuk_pending_reconciliation' => $pendingTotal,
                'apkuk_pending_mapped' => $apkuks->count() - $apkukMapped,
                'gangguan_total' => $gangguanTotal,
                'gangguan_mapped' => 0,
                'generated_at' => now('Asia/Jakarta')->format('d/m/Y H:i').' WIB',
            ],
        ]);
    }
}
