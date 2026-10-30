<?php

namespace App\Services\Pasila;

use App\Models\PasilaSession;
use App\Models\Posko;
use App\Models\ShiftInstance;
use Carbon\CarbonImmutable;

/**
 * Baseline rotasi: Section terverifikasi Posko yang paling lama tidak ditugaskan PASILA (belum
 * pernah ditugaskan lebih dulu), seri diurutkan penyulang lalu nama Section.
 *
 * Bukan skor risiko, bukan ML, dan tidak memakai data gangguan: bobot prioritas resmi belum ada
 * (TBD-11). Riwayat yang dibaca hanya Section sesi PASILA sebelumnya milik Posko yang sama.
 */
final class RotationSectionStrategy implements PasilaSectionStrategy
{
    public function name(): string
    {
        return 'ROTASI_SECTION';
    }

    public function version(): string
    {
        return 'baseline-1';
    }

    public function recommend(Posko $posko, array $sections, ShiftInstance $shift): array
    {
        $keys = array_column($sections, 'key');
        $last = [];
        $history = PasilaSession::query()
            ->join('shift_instances as s', 's.id', '=', 'pasila_sessions.shift_instance_id')
            ->where('pasila_sessions.posko_id', $posko->getKey())
            ->whereIn('pasila_sessions.section_key', $keys)
            ->where('s.starts_at', '<', $shift->starts_at)
            ->orderByDesc('s.starts_at')
            ->get(['pasila_sessions.section_key', 's.operational_date', 's.shift_code', 's.starts_at']);
        foreach ($history as $row) {
            $last[$row->section_key] ??= $row;
        }

        // Urutan $sections (penyulang, Section) dipertahankan untuk seri: usort stabil sejak PHP 8.
        $ordered = $sections;
        usort($ordered, function (array $a, array $b) use ($last): int {
            $atA = isset($last[$a['key']]) ? (string) $last[$a['key']]->starts_at : '';
            $atB = isset($last[$b['key']]) ? (string) $last[$b['key']]->starts_at : '';

            return $atA <=> $atB;
        });
        $chosen = $ordered[0];
        $count = count($sections);

        $previous = $last[$chosen['key']] ?? null;
        $reasons = [$previous === null
            ? "Rotasi: Section ini belum pernah ditugaskan PASILA untuk Posko {$posko->nama_posko}."
            : sprintf('Rotasi: terakhir ditugaskan PASILA pada Shift %s hari operasional %s, paling lama di antara Section Posko ini.',
                $previous->shift_code, CarbonImmutable::parse($previous->operational_date)->format('d/m/Y'))];
        $reasons[] = "{$count} Section terverifikasi dipertimbangkan.";
        $reasons[] = 'Baseline rotasi; bukan skor risiko, bukan ML, dan tidak memakai data gangguan.';

        return ['entry' => $chosen, 'reasons' => $reasons];
    }
}
