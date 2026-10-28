<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApkukReconciliationItem;
use App\Support\PenyulangName;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Data read-only untuk Peta Jaringan Admin. Tidak melakukan geocoding/tebakan lokasi.
 * `penyulang` berisi nama canonical (filter dan dropdown satu entri per penyulang, alias
 * TANGGAMUS → TANGGAMUS 2); `penyulang_sumber` berisi nilai mentah sumber.
 */
class MapDataController extends Controller
{
    public function index(): JsonResponse
    {
        $feeder = fn (?string $raw): array => ['penyulang' => PenyulangName::canonical($raw) ?: null, 'penyulang_sumber' => $raw];

        $keypoints = DB::table('keypoints')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [-90, 90])
            ->whereBetween('longitude', [-180, 180])
            ->orderBy('id')
            ->get(['id', 'penyulang', 'jenis', 'nama', 'latitude', 'longitude', 'note'])
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

        $apkuks = (clone $apkukTampil)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [-90, 90])
            ->whereBetween('longitude', [-180, 180])
            ->orderByDesc('tanggal')
            ->orderByDesc('id')
            ->get([
                'id', 'source_id', 'tanggal', 'penyulang', 'no_tiang',
                'status', 'kurang_bh', 'terpasang_bh', 'latitude', 'longitude',
            ])
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

        return response()->json([
            'keypoints' => $keypoints,
            'apkuks' => $apkuks,
            'gangguans' => $gangguans,
            'meta' => [
                'keypoint_mapped' => $keypoints->count(),
                'apkuk_active_total' => $apkukTotal,
                'apkuk_mapped' => $apkukMapped = $apkuks->where('reconciliation_pending', false)->count(),
                'apkuk_without_coordinates' => $apkukTotal - $apkukMapped,
                'apkuk_pending_reconciliation' => $pendingTotal,
                'apkuk_pending_mapped' => $apkuks->count() - $apkukMapped,
                'gangguan_total' => $gangguanTotal,
                'gangguan_mapped' => 0,
                'generated_at' => now('Asia/Jakarta')->format('d/m/Y H:i').' WIB',
            ],
        ]);
    }
}
