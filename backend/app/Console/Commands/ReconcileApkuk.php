<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReportsSyncResult;
use App\Services\Apkuk\ApkukReconciliationPlan;
use App\Services\Apkuk\ApkukReconciliationPlanner;
use App\Services\Apkuk\ApkukSourceException;
use App\Services\Apkuk\ApkukSourceReader;
use App\Services\Apkuk\ApkukSourceSnapshot;
use App\Services\ApkukCandidateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Rekonsiliasi identitas APKUK yang tidak lagi ada di APKUK_RAW.
 *
 * Tanpa --apply: dry-run read-only (selisih, duplikasi, dampak WO, kode rencana).
 * Dengan --apply: menandai source_missing_at hanya untuk rencana yang persis sama
 * dengan yang disetujui. Tidak ada hard-delete; WO dan histori lain tidak diubah;
 * laporan yang muncul kembali dipulihkan oleh apkuk:sync.
 *
 * --approved-by dan --reason hanya metadata audit. Perintah ini tidak mengautentikasi
 * penyetuju; kewenangan apply berasal dari akses ke kredensial database produksi.
 */
class ReconcileApkuk extends Command
{
    use ReportsSyncResult;

    protected $signature = 'apkuk:reconcile
        {--apply : Terapkan rencana yang disetujui (tanpa opsi ini: dry-run, database tidak diubah)}
        {--plan= : Kode rencana dari dry-run yang disetujui}
        {--expect-missing= : Jumlah identitas yang disetujui untuk ditandai source_missing_at}
        {--approved-by= : Nama dan jabatan penyetuju (metadata audit; tidak diverifikasi)}
        {--reason= : Alasan persetujuan (metadata audit)}
        {--allow-active-wo : Izinkan penandaan walau ada WO aktif yang merujuk langsung laporan tersebut}
        {--export= : Simpan daftar identitas hilang ke file CSV}';

    protected $description = 'Bandingkan APKUK_RAW dengan apkuks; tandai identitas yang hilang hanya setelah disetujui';

    private const ACTION = 'MARK_SOURCE_MISSING';

    private const MAX_APPROVED_BY = 120;

    private const MAX_REASON = 1000;

    private const LOCK_TIMEOUT = '10s';

    public function handle(ApkukSourceReader $reader, ApkukReconciliationPlanner $planner): int
    {
        $apply = (bool) $this->option('apply');
        $this->line($apply ? '== Rekonsiliasi APKUK (APPLY)' : '== Rekonsiliasi APKUK (DRY-RUN — database tidak diubah)');

        try {
            $source = $reader->read();
        } catch (ApkukSourceException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Pengaman sumber sama dengan apkuk:sync: tidak pernah memakai sumber kosong,
        // terpotong, atau berisi identitas ganda sebagai dasar rekonsiliasi.
        if ($source->issues !== []) {
            $this->error('Sumber berisi data tidak valid; rekonsiliasi ditolak:');
            $this->listLimited($source->issues);

            return self::FAILURE;
        }
        if (! $source->isComplete()) {
            $this->error('Jumlah data sumber kurang dari '.ApkukSourceReader::MIN_EXPECTED_ROWS.' laporan atau berbeda dari hasil validasi. Rekonsiliasi ditolak.');

            return self::FAILURE;
        }

        $plan = $planner->plan($source);
        $this->report($plan);

        if (($path = $this->option('export')) && ! $this->export($plan, (string) $path)) {
            return self::FAILURE;
        }

        if (! $apply) {
            return $this->finishDryRun($plan);
        }

        return $this->apply($source, $planner, $plan);
    }

    private function finishDryRun(ApkukReconciliationPlan $plan): int
    {
        $this->line(sprintf(
            'RECONCILE_PLAN id=%s missing=%d new=%d returning=%d wo_refs=%d active_wo_refs=%d eligible_removed=%d newly_eligible=%d',
            $plan->id,
            count($plan->missing),
            count($plan->new),
            count($plan->returning),
            count($plan->workOrderRefs),
            count($plan->activeWorkOrderRefs()),
            count($plan->impact['eligible_removed']),
            count($plan->impact['newly_eligible']),
        ));

        if ($plan->missing === []) {
            $this->info('Tidak ada identitas yang perlu ditandai.');
        } else {
            $this->line('Setelah daftar ditinjau dan disetujui, terapkan dengan:');
            $this->line(sprintf(
                '  php artisan apkuk:reconcile --apply --plan=%s --expect-missing=%d --approved-by="Nama, Jabatan" --reason="Alasan persetujuan"%s',
                $plan->id,
                count($plan->missing),
                $plan->activeWorkOrderRefs() === [] ? '' : ' --allow-active-wo',
            ));
            $this->line('Kode rencana batal otomatis bila isi sumber, tabel apkuks, referensi WO, atau dampaknya berubah sebelum apply.');
            $this->line('--approved-by dan --reason hanya dicatat sebagai metadata audit; identitas penyetuju tidak diverifikasi.');
        }
        $this->info('DRY RUN REKONSILIASI SELESAI. Database tidak diubah.');

        return self::SUCCESS;
    }

    private function apply(ApkukSourceSnapshot $source, ApkukReconciliationPlanner $planner, ApkukReconciliationPlan $plan): int
    {
        $planId = trim((string) $this->option('plan'));
        $expected = trim((string) $this->option('expect-missing'));
        $approvedBy = trim((string) $this->option('approved-by'));
        $reason = trim((string) $this->option('reason'));

        if ($planId === '' || ! ctype_digit($expected) || mb_strlen($approvedBy) < 3 || mb_strlen($reason) < 10) {
            $this->error('Persetujuan tidak lengkap: --plan, --expect-missing, --approved-by (min. 3 karakter), dan --reason (min. 10 karakter) wajib diisi. Tidak ada perubahan.');

            return self::FAILURE;
        }
        if (! self::isPlainText($approvedBy, self::MAX_APPROVED_BY) || ! self::isPlainText($reason, self::MAX_REASON)) {
            $this->error('--approved-by (maks. '.self::MAX_APPROVED_BY.' karakter) dan --reason (maks. '.self::MAX_REASON.' karakter) harus teks satu baris tanpa karakter kontrol. Tidak ada perubahan.');

            return self::FAILURE;
        }
        // Tidak ikut sidik persetujuan dan tidak membuktikan identitas: siapa pun yang memegang
        // kredensial database dapat mengisinya. Role database yang sebenarnya ikut dicatat.
        $this->line('Catatan: --approved-by dan --reason dicatat apa adanya sebagai metadata audit; identitas penyetuju tidak diverifikasi.');

        if (! hash_equals($plan->id, $planId) || (int) $expected !== count($plan->missing)) {
            $this->error(sprintf(
                'Rencana berubah sejak disetujui (disetujui %s/%d, sekarang %s/%d). Tidak ada perubahan; jalankan dry-run ulang dan bandingkan baris "Sidik persetujuan".',
                $planId,
                (int) $expected,
                $plan->id,
                count($plan->missing),
            ));

            return self::FAILURE;
        }
        if ($plan->missing === []) {
            $this->info('Tidak ada identitas yang perlu ditandai. Database tidak diubah.');

            return self::SUCCESS;
        }
        if ($plan->activeWorkOrderRefs() !== [] && ! $this->option('allow-active-wo')) {
            $this->error(count($plan->activeWorkOrderRefs()).' WO aktif merujuk laporan yang akan ditandai. Tinjau WO tersebut; ulangi dengan --allow-active-wo bila tetap disetujui. Tidak ada perubahan.');

            return self::FAILURE;
        }
        if (! Schema::hasTable('apkuk_reconciliations')) {
            $this->error('Tabel audit apkuk_reconciliations belum ada. Jalankan migration additive terlebih dahulu. Tidak ada perubahan.');

            return self::FAILURE;
        }

        $markedAt = now();
        $ids = $plan->missingApkukIds();

        try {
            $databaseUser = DB::transaction(function () use ($source, $planner, $plan, $ids, $markedAt, $approvedBy, $reason): ?string {
                $this->lockTables();

                // Validasi ulang setelah tabel terkunci. Perubahan apkuks, target WO, atau status
                // WO sejak validasi di atas, termasuk yang terjadi bersamaan, membatalkan apply.
                $current = $planner->plan($source);
                if (! hash_equals($plan->fingerprint, $current->fingerprint)) {
                    throw new RuntimeException('data berubah sejak validasi ('.$this->changedParts($plan, $current).'); jalankan dry-run ulang');
                }

                $affected = 0;
                foreach (array_chunk($ids, 500) as $chunk) {
                    // Hanya kolom penanda yang diubah; baris tidak pernah dihapus.
                    $affected += DB::table('apkuks')
                        ->whereIn('id', $chunk)
                        ->where('sumber_input', ApkukSourceReader::SOURCE)
                        ->whereNull('source_missing_at')
                        ->update(['source_missing_at' => $markedAt, 'updated_at' => $markedAt]);
                }

                if ($affected !== count($ids)) {
                    throw new RuntimeException("baris yang ditandai ({$affected}) tidak sama dengan rencana (".count($ids).')');
                }

                $databaseUser = DB::connection()->getDriverName() === 'pgsql' ? (string) DB::scalar('select current_user') : null;
                DB::table('apkuk_reconciliations')->insert([
                    'plan_id' => $plan->id,
                    'fingerprint' => $plan->fingerprint,
                    'fingerprint_parts' => json_encode($plan->fingerprintParts),
                    'action' => self::ACTION,
                    'approved_by' => $approvedBy,
                    'reason' => $reason,
                    'database_user' => $databaseUser,
                    'marked_count' => $affected,
                    'source_report_count' => $plan->sourceReports,
                    'marked_at' => $markedAt,
                    'apkuk_ids' => json_encode($ids),
                    'legacy_keys' => json_encode($plan->missingLegacyKeys()),
                    'impact' => json_encode([
                        'work_order_refs' => $plan->workOrderRefs,
                        'active_wo_acknowledged' => (bool) $this->option('allow-active-wo'),
                        'candidates' => $plan->impact['candidates'],
                        'map' => $plan->impact['map'],
                        'eligible_removed' => array_column($plan->impact['eligible_removed'], 'apkuk_id'),
                        'newly_eligible' => array_column($plan->impact['newly_eligible'], 'apkuk_id'),
                        'no_longer_eligible' => array_column($plan->impact['no_longer_eligible'], 'apkuk_id'),
                        'reason_changed' => array_column($plan->impact['reason_changed'], 'apkuk_id'),
                    ]),
                    'created_at' => $markedAt,
                ]);

                return $databaseUser;
            });
        } catch (Throwable $e) {
            $this->error('Rekonsiliasi dibatalkan dan transaksi di-rollback. Tidak ada perubahan. Penyebab: '.Str::limit($e->getMessage(), 500));

            return self::FAILURE;
        }

        Log::warning('apkuk:reconcile menandai identitas yang hilang dari sumber', [
            'plan_id' => $plan->id,
            'fingerprint' => $plan->fingerprint,
            'marked_count' => count($ids),
            'marked_at' => $markedAt->toIso8601String(),
            'approved_by' => $approvedBy,
            'database_user' => $databaseUser,
            'reason' => $reason,
        ]);

        $this->info(sprintf('RECONCILE_APPLIED plan=%s marked=%d marked_at=%s', $plan->id, count($ids), $markedAt->toIso8601String()));
        $this->info('Baris tidak dihapus dan WO tidak diubah. Laporan yang muncul kembali di sumber dipulihkan otomatis oleh apkuk:sync.');

        return self::SUCCESS;
    }

    /**
     * Mengunci tabel penentu rencana sampai commit: penulis lain (apkuk:sync, pembuatan atau
     * perubahan WO) menunggu, pembaca tetap berjalan. Urutan sama dengan alur pembuatan WO
     * (work_orders lalu target) agar tidak saling mengunci; bila kunci tidak didapat dalam
     * LOCK_TIMEOUT, transaksi gagal tanpa perubahan. SQLite (test) tidak mengenal LOCK TABLE.
     */
    private function lockTables(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SET LOCAL lock_timeout = '".self::LOCK_TIMEOUT."'");
        DB::statement('LOCK TABLE work_orders, work_order_apkuk_targets IN SHARE MODE');
        DB::statement('LOCK TABLE apkuks IN SHARE ROW EXCLUSIVE MODE');
    }

    private function changedParts(ApkukReconciliationPlan $approved, ApkukReconciliationPlan $current): string
    {
        $changed = [];
        foreach (ApkukReconciliationPlan::FINGERPRINT_PARTS as $part => $label) {
            if (($approved->fingerprintParts[$part] ?? null) !== ($current->fingerprintParts[$part] ?? null)) {
                $changed[] = $label;
            }
        }

        return implode(', ', $changed);
    }

    private static function isPlainText(string $value, int $maxLength): bool
    {
        return mb_strlen($value) <= $maxLength && preg_match('/^[^\p{Cc}]*$/u', $value) === 1;
    }

    private function report(ApkukReconciliationPlan $plan): void
    {
        $this->table(['Pemeriksaan', 'Jumlah'], [
            ['Laporan valid di sumber', $plan->sourceReports],
            ['Identitas aktif di PostgreSQL', $plan->activeIdentities],
            ['Sudah bertanda source_missing_at', $plan->markedIdentities],
            ['Cocok (tetap aktif)', $plan->matched],
            ['Hilang dari sumber (akan ditandai source_missing_at)', count($plan->missing)],
            ['Baru di sumber (disisipkan apkuk:sync berikutnya)', count($plan->new)],
            ['Muncul kembali (dipulihkan apkuk:sync berikutnya)', count($plan->returning)],
            ['ID dipakai >1 laporan di sumber', count($plan->duplicatedIds)],
            ['Kemungkinan laporan ganda di sumber (ID berbeda)', count($plan->possibleDuplicateReports)],
        ]);

        if ($plan->missing !== []) {
            $categories = $plan->missingByCategory();
            $this->table(['Kategori identitas hilang', 'Jumlah'], array_map(
                fn (string $category) => [ApkukReconciliationPlan::CATEGORY_LABELS[$category], $categories[$category]],
                array_keys($categories),
            ));
            $this->warn('Identitas hilang (akan ditandai, tidak dihapus):');
            $this->listLimited(array_map(fn (array $row) => sprintf(
                'ID %s | Tiang %s | %s | %s | %s | %s',
                $row['source_id'] ?? '(kosong)',
                $row['no_tiang'] ?? '(kosong)',
                $row['tanggal'] ?? '-',
                $row['penyulang'] ?? '-',
                $row['status'] ?? '-',
                $row['category'],
            ), $plan->missing));
        }
        if ($plan->new !== []) {
            $this->warn('Laporan baru di sumber (disisipkan apkuk:sync setelah rekonsiliasi):');
            $this->listLimited(array_map(fn (array $row) => "ID {$row['source_id']} | Tiang {$row['no_tiang']} | baris {$row['sheet_row']}", $plan->new));
        }
        if ($plan->returning !== []) {
            $this->warn('Laporan bertanda source_missing_at yang muncul kembali (dipulihkan apkuk:sync):');
            $this->listLimited(array_map(fn (array $row) => "ID {$row['source_id']} | Tiang {$row['no_tiang']} | apkuk #{$row['apkuk_id']}", $plan->returning));
        }
        if ($plan->duplicatedIds !== []) {
            $this->warn('ID dipakai >1 laporan (identitas tetap terpisah karena memakai ID + tiang; pastikan memang laporan berbeda):');
            $this->listLimited(array_map(
                fn (string $id, array $reports) => $id.': '.implode(', ', array_map(fn (array $r) => "{$r['no_tiang']} (baris {$r['sheet_row']})", $reports)),
                array_keys($plan->duplicatedIds),
                $plan->duplicatedIds,
            ));
        }
        if ($plan->possibleDuplicateReports !== []) {
            $this->warn('Kemungkinan laporan ganda di sumber (tiang, tanggal, petugas, jumlah, status sama; ID berbeda):');
            $this->listLimited(array_map(
                fn (array $group) => implode(', ', array_map(fn (array $r) => "baris {$r['sheet_row']} (ID {$r['source_id']})", $group)),
                $plan->possibleDuplicateReports,
            ));
        }

        $this->reportImpact($plan);

        $this->line('Sidik persetujuan: '.implode(', ', array_map(
            fn (string $part, string $label) => $label.'='.substr($plan->fingerprintParts[$part], 0, 12),
            array_keys(ApkukReconciliationPlan::FINGERPRINT_PARTS),
            ApkukReconciliationPlan::FINGERPRINT_PARTS,
        )));
    }

    private function reportImpact(ApkukReconciliationPlan $plan): void
    {
        $activeWo = count($plan->activeWorkOrderRefs());
        $this->line(sprintf(
            'Dampak langsung: %d target WO merujuk laporan yang akan ditandai (%d WO aktif). WO dan targetnya tidak diubah.',
            count($plan->workOrderRefs),
            $activeWo,
        ));
        if ($plan->workOrderRefs !== []) {
            $this->listLimited(array_map(
                fn (array $ref) => "{$ref['code']} | {$ref['status']} | apkuk #{$ref['apkuk_id']}".($ref['active'] ? ' | AKTIF' : ''),
                $plan->workOrderRefs,
            ));
        }

        $impact = $plan->impact;
        $this->line('Dampak tidak langsung (simulasi): tampilan yang hanya membaca laporan aktif berubah setelah penandaan.');
        $this->table(['Tampilan', 'Sebelum', 'Sesudah'], [
            ['Kandidat WO APKUK (semua laporan aktif)', $impact['candidates']['total_before'], $impact['candidates']['total_after']],
            ['Kandidat WO APKUK layak dipilih', $impact['candidates']['eligible_before'], $impact['candidates']['eligible_after']],
            ['APKUK aktif di Peta Jaringan', $impact['map']['active_before'], $impact['map']['active_after']],
            ['Marker APKUK di Peta Jaringan', $impact['map']['markers_before'], $impact['map']['markers_after']],
        ]);

        $issues = fn (?array $codes) => match (true) {
            $codes === null => 'keluar dari daftar',
            $codes === [] => 'layak',
            default => implode('+', $codes),
        };
        $describe = fn (array $row) => sprintf('ID %s | Tiang %s | kurang %s | %s -> %s', $row['source_id'] ?? '(kosong)', $row['no_tiang'] ?? '(kosong)', $row['kurang_bh'] ?? '-', $issues($row['issues_before']), $issues($row['issues_after']));
        $sections = [
            'eligible_removed' => 'Kandidat layak yang keluar dari daftar kandidat WO (laporan ditandai):',
            'newly_eligible' => 'Laporan lain yang menjadi layak (laporan lebih baru/ganda di tiang yang sama ikut ditandai):',
            'no_longer_eligible' => 'Laporan lain yang menjadi tidak layak:',
            'reason_changed' => 'Laporan lain yang alasan tidak layaknya berubah:',
        ];
        $codes = [];
        foreach ($sections as $key => $title) {
            if ($impact[$key] !== []) {
                $this->warn($title.' '.count($impact[$key]));
                $this->listLimited(array_map($describe, $impact[$key]));
                foreach ($impact[$key] as $row) {
                    $codes += array_flip(array_merge($row['issues_before'], $row['issues_after'] ?? []));
                }
            }
        }
        if ($codes !== []) {
            ksort($codes);
            $this->line('Arti kode: '.implode('; ', array_map(
                fn (string $code) => $code.' = '.(ApkukCandidateService::LABELS[$code] ?? $code),
                array_keys($codes),
            )).'.');
        }
    }

    private function export(ApkukReconciliationPlan $plan, string $path): bool
    {
        $directory = dirname($path);
        if (! is_dir($directory) || ($handle = @fopen($path, 'wb')) === false) {
            $this->error("File ekspor tidak dapat ditulis: {$path}");

            return false;
        }

        // Nilai diawali = + - @ diberi apostrof agar tidak dieksekusi sebagai formula spreadsheet.
        $safe = fn (?string $value) => $value !== null && preg_match('/^[=+\-@]/', $value) ? "'".$value : $value;
        $workOrders = $plan->workOrderCodesByApkuk();
        fputcsv($handle, ['kategori', 'apkuk_id', 'source_id', 'no_tiang', 'tanggal', 'penyulang', 'status', 'kandidat_wo_layak', 'referensi_wo', 'legacy_key']);
        foreach ($plan->missing as $row) {
            fputcsv($handle, [
                $row['category'], $row['apkuk_id'], $safe($row['source_id']), $safe($row['no_tiang']),
                $row['tanggal'], $safe($row['penyulang']), $safe($row['status']),
                $row['eligible_candidate'] ? 'YA' : 'TIDAK', implode(' ', $workOrders[$row['apkuk_id']] ?? []),
                $row['legacy_key'],
            ]);
        }
        fclose($handle);
        $this->line('Daftar '.count($plan->missing)." identitas hilang diekspor ke: {$path}");

        return true;
    }
}
