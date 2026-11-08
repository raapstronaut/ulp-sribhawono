<?php

namespace App\Console\Commands;

use App\Models\PasilaSession;
use App\Services\Pasila\PasilaShiftOpener;
use Illuminate\Console\Command;

/**
 * Membuka sesi PASILA shift yang sedang berjalan untuk setiap Posko aktif. Idempoten dan aman
 * dijalankan bersamaan; shift lampau tidak dibuka mundur. Ditolak bila PASILA_OPENER_ENABLED=false.
 */
class OpenPasilaShift extends Command
{
    protected $signature = 'pasila:open-shift
                            {--trigger=CLI : CLI atau SCHEDULER}';

    protected $description = 'Buka sesi PASILA shift berjalan (satu per Posko aktif, sekali per shift; terkunci flag aktivasi)';

    public function handle(PasilaShiftOpener $opener): int
    {
        $trigger = $this->option('trigger') === PasilaSession::TRIGGER_SCHEDULER ? PasilaSession::TRIGGER_SCHEDULER : PasilaSession::TRIGGER_CLI;
        $result = $opener->openCurrent($trigger);
        $shift = $result['shift'];

        $this->line(sprintf('PASILA_OPENER status=%s shift=%s dibuat=%d sudah_ada=%d strategi=%s %s',
            $result['status'],
            $shift ? $shift->operational_date->toDateString().'/'.$shift->shift_code : '-',
            count($result['created']),
            $result['existing'],
            $opener->strategy()->name(),
            $opener->strategy()->version(),
        ));
        if ($result['message']) {
            $this->warn($result['message']);
        }
        if ($result['created'] !== []) {
            $this->table(
                ['Kode', 'Posko', 'Penentuan Section', 'Penyulang', 'Section'],
                array_map(fn (PasilaSession $session) => [
                    $session->code,
                    $session->posko->nama_posko,
                    $session->sectionStatusLabel(),
                    $session->penyulang?->kode_penyulang ?? '-',
                    $session->section_name ?? '-',
                ], $result['created']),
            );
        }

        return $result['status'] === 'COMPLETED' ? self::SUCCESS : self::FAILURE;
    }
}
