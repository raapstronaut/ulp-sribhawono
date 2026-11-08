<?php

namespace App\Console\Commands;

use App\Models\ApkukGenerationRun;
use App\Services\Apkuk\Generation\ApkukShiftGenerator;
use App\Services\ShiftService;
use Illuminate\Console\Command;

/**
 * Generator WO APKUK per Posko per shift. Default PRATINJAU (tidak menulis WO). --generate hanya
 * berjalan bila ApkukGeneratorGuard mengizinkan: APKUK_GENERATOR_ENABLED dan APP_ENV local/testing
 * dengan database lokal. Lingkungan lain selalu ditolak sampai model ML dan prosedur readiness ada.
 */
class GenerateApkukShiftWorkOrders extends Command
{
    protected $signature = 'apkuk:generate-shift
                            {--date= : Tanggal operasional Y-m-d (hari operasional mulai 08:00 WIB)}
                            {--shift= : Kode shift A/B/C}
                            {--current : Pakai shift yang sedang berjalan}
                            {--generate : Buat WO sungguhan (default: pratinjau tanpa WO)}
                            {--trigger=CLI : CLI atau SCHEDULER}';

    protected $description = 'Hitung kuota 6 WO APKUK per Posko per shift; pratinjau atau generate (terkunci pengaman aktivasi)';

    public function handle(ApkukShiftGenerator $generator, ShiftService $shifts): int
    {
        if ($this->option('current')) {
            $instance = $shifts->instanceAt();
            $date = $instance['operational_date'];
            $code = $instance['code'];
        } else {
            $date = (string) $this->option('date');
            $code = mb_strtoupper((string) $this->option('shift'));
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! in_array($code, $shifts->codes(), true)) {
                $this->error('Isi --date=Y-m-d dan --shift=A|B|C, atau pakai --current.');

                return self::FAILURE;
            }
        }

        $trigger = $this->option('trigger') === ApkukGenerationRun::TRIGGER_SCHEDULER ? ApkukGenerationRun::TRIGGER_SCHEDULER : ApkukGenerationRun::TRIGGER_CLI;
        $mode = $this->option('generate') ? ApkukGenerationRun::MODE_GENERATE : ApkukGenerationRun::MODE_PREVIEW;
        $run = $generator->run($date, $code, $mode, $trigger);

        $this->line(sprintf('APKUK_GENERATOR run=%d mode=%s status=%s shift=%s/%s strategi=%s %s ml=%s',
            $run->id, $run->mode, $run->status, $date, $code, $run->strategy, $run->strategy_version, $run->is_machine_learning ? 'ya' : 'tidak'));
        if ($run->message) {
            $this->warn($run->message);
        }
        if ($run->summary) {
            $this->table(
                ['Posko', 'Status', 'Limpahan', 'WO lain', 'Baru', 'Total', 'Kekurangan', 'Kelebihan', 'Kandidat'],
                array_map(fn (array $row) => [$row['posko'], $row['status'], $row['carry_over'], $row['existing'], $row['new'], $row['total'], $row['shortfall'], $row['overload'], $row['eligible_candidates']], $run->summary),
            );
        }
        if ($breakdown = $run->breakdown) {
            $this->line(sprintf('Laporan APKUK: aktif=%d histori=%d | sah=%d (terpetakan=%d, ditahan Section–Posko=%d) | tidak sah=%d',
                $breakdown['reports_active'], $breakdown['reports_archived'], $breakdown['eligible'], $breakdown['eligible_mapped'], $breakdown['eligible_held'], $breakdown['not_eligible']));
            foreach ($breakdown['not_eligible_by_primary'] as $code => $count) {
                $this->line("  tidak sah · {$code}: {$count}");
            }
            foreach ($breakdown['eligible_held_by_reason'] as $code => $count) {
                $this->line("  ditahan · {$code}: {$count}");
            }
        }

        return $run->status === ApkukGenerationRun::STATUS_COMPLETED ? self::SUCCESS : self::FAILURE;
    }
}
