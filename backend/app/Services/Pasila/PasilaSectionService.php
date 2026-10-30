<?php

namespace App\Services\Pasila;

use App\Models\PasilaSession;
use App\Models\Penyulang;
use App\Models\Posko;
use App\Models\User;
use App\Services\Apkuk\SectionPoskoMap;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penentuan/penggantian Section sesi PASILA oleh Admin.
 *
 * Section hanya boleh salah satu Section terverifikasi milik Posko sesi itu (SectionPoskoMap).
 * Section milik Posko lain, tidak dikenal, atau ambigu ditolak dengan alasannya; tidak ada
 * override. Alasan wajib, perubahan dicatat di audit_logs (sebelum/sesudah). Sesi shift yang
 * sudah berakhir tidak diubah.
 */
class PasilaSectionService
{
    private const MIN_REASON = 10;

    public function __construct(private readonly AuditLogger $audit) {}

    public function assign(PasilaSession $session, User $admin, string $section, string $reason): PasilaSession
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < self::MIN_REASON) {
            throw ValidationException::withMessages(['reason' => 'Alasan wajib diisi minimal '.self::MIN_REASON.' karakter.']);
        }

        return DB::transaction(function () use ($session, $admin, $section, $reason): PasilaSession {
            $session = PasilaSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
            $session->loadMissing(['posko', 'shiftInstance']);
            if ($session->shiftHasEnded()) {
                throw ValidationException::withMessages(['section' => 'Shift sesi '.$session->code.' sudah berakhir; Section tidak diubah agar riwayat tetap utuh.']);
            }

            $entry = $this->validEntry(SectionPoskoMap::load(), $session, $section);
            if ($entry['key'] === $session->section_key) {
                throw ValidationException::withMessages(['section' => 'Section '.$entry['section'].' sudah menjadi Section sesi ini.']);
            }

            $original = $session->getOriginal();
            $session->forceFill([
                'section_status' => PasilaSession::SECTION_ADMIN,
                'penyulang_id' => $entry['penyulang_model']->getKey(),
                'section_key' => $entry['key'],
                'section_name' => $entry['section'],
                'section_hold_reason' => null,
                'section_set_by_user_id' => $admin->getKey(),
                'section_set_at' => now(),
                'section_set_reason' => $reason,
            ])->save();

            $this->audit->recordChanges('pasila_session', $session, $original, $session->code, $admin, $reason);

            return $session;
        });
    }

    /**
     * @return array{key: string, section: string, penyulang: string, penyulang_model: Penyulang, posko: Posko, reason: null, posko_pic: array<int, string>}
     */
    private function validEntry(SectionPoskoMap $map, PasilaSession $session, string $section): array
    {
        $entry = $map->entry($section);
        if ($entry === null) {
            throw ValidationException::withMessages(['section' => 'Section "'.trim($section).'" tidak ditemukan pada referensi SECTION_PIC_RAW.']);
        }
        if ($entry['posko'] === null) {
            throw ValidationException::withMessages(['section' => 'Section '.$entry['section'].' ditahan: '.(SectionPoskoMap::LABELS[$entry['reason']] ?? $entry['reason']).'.']);
        }
        if ($entry['posko']->getKey() !== $session->posko_id) {
            throw ValidationException::withMessages(['section' => 'Section '.$entry['section'].' ('.$entry['penyulang'].') milik Posko '.$entry['posko']->nama_posko.', bukan Posko '.$session->posko->nama_posko.'. Kepemilikan Section tidak berpindah antar-Posko.']);
        }

        return $entry;
    }
}
