<?php

namespace App\Console\Commands;

use App\Models\ApkukReconciliationItem;
use App\Models\ApkukSyncRun;
use App\Services\Apkuk\ApkukReconciliationQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Laporan antrean rekonsiliasi APKUK untuk operator (read-only).
 *
 * Penyelesaian antrean hanya lewat Web Admin oleh Admin yang login, agar setiap keputusan
 * tercatat atas akun terautentikasi, bukan nama yang diketik di CLI.
 */
class ListApkukPending extends Command
{
    protected $signature = 'apkuk:pending
        {--export= : Simpan seluruh antrean terbuka ke file CSV}
        {--limit=50 : Jumlah baris yang ditampilkan}';

    protected $description = 'Tampilkan antrean rekonsiliasi APKUK dan riwayat apkuk:sync terakhir (read-only)';

    public function handle(): int
    {
        if (! Schema::hasTable('apkuk_reconciliation_items')) {
            $this->error('Tabel apkuk_reconciliation_items belum ada. Jalankan migration additive 2026_10_28_100001.');

            return self::FAILURE;
        }

        $runs = ApkukSyncRun::query()->orderByDesc('id')->limit(5)->get();
        $this->table(['Sync', 'Mulai (UTC)', 'Status', 'Laporan sumber', 'Baru', 'Diperbarui', 'Antrean', 'Keterangan'], $runs->map(fn (ApkukSyncRun $run) => [
            $run->id,
            $run->started_at?->format('Y-m-d H:i:s'),
            $run->status,
            $run->source_report_count ?? '-',
            $run->counts['inserted'] ?? '-',
            $run->counts['updated'] ?? '-',
            $run->counts['pending_open'] ?? '-',
            $run->failure_stage ? $run->failure_stage.': '.mb_strimwidth((string) $run->failure_reason, 0, 80, '…') : '',
        ])->all());

        $items = ApkukReconciliationItem::query()->open()->with('apkuk')->orderBy('first_detected_at')->orderBy('id')->get();
        $refs = ApkukReconciliationQueue::workOrderRefs($items->flatMap(fn (ApkukReconciliationItem $item) => $item->identityCandidates() ?: [(int) $item->apkuk_id])->filter()->all());
        $rows = $items->map(fn (ApkukReconciliationItem $item) => [
            'id' => $item->id,
            'jenis' => $item->kind,
            'apkuk_id' => $item->apkuk_id,
            'source_id' => $item->source_id,
            'no_tiang' => $item->no_tiang,
            'tanggal' => $item->kind === ApkukReconciliationItem::KIND_MISSING ? $item->apkuk?->tanggal?->toDateString() : ($item->source_record['tanggal'] ?? null),
            'status_sumber' => $item->kind === ApkukReconciliationItem::KIND_MISSING ? $item->apkuk?->status : ($item->source_record['status'] ?? null),
            'kandidat_lama' => implode(' ', array_map(fn (int $id) => '#'.$id, $item->kind === ApkukReconciliationItem::KIND_IDENTITY_CHANGE ? $item->identityCandidates() : [])),
            'referensi_wo' => implode(' ', array_map(
                fn (array $ref) => $ref['code'].($ref['active'] ? '(AKTIF)' : ''),
                array_merge(...array_map(fn (int $id) => $refs[$id] ?? [], $item->identityCandidates() ?: array_filter([(int) $item->apkuk_id]))),
            )),
            'terdeteksi' => $item->first_detected_at?->format('Y-m-d H:i:s'),
            'terlihat' => $item->detection_count,
        ]);

        $this->info("Antrean terbuka: {$rows->count()} (hilang: ".$items->where('kind', ApkukReconciliationItem::KIND_MISSING)->count()
            .', dugaan perubahan identitas: '.$items->where('kind', ApkukReconciliationItem::KIND_IDENTITY_CHANGE)->count().').');
        if ($rows->isNotEmpty()) {
            $limit = max(1, (int) $this->option('limit'));
            $this->table(array_keys($rows->first()), $rows->take($limit)->all());
            if ($rows->count() > $limit) {
                $this->line('… dan '.($rows->count() - $limit).' lainnya (gunakan --export untuk daftar lengkap).');
            }
        }
        $this->line('Keputusan per laporan: Web Admin → /admin/apkuk-rekonsiliasi (Admin yang login; tercatat atas akunnya).');

        if (($path = $this->option('export')) !== null) {
            if (! is_dir(dirname((string) $path)) || ($handle = @fopen((string) $path, 'wb')) === false) {
                $this->error("File ekspor tidak dapat ditulis: {$path}");

                return self::FAILURE;
            }
            // Nilai diawali = + - @ diberi apostrof agar tidak dieksekusi sebagai formula spreadsheet.
            $safe = fn ($value) => is_string($value) && preg_match('/^[=+\-@]/', $value) ? "'".$value : $value;
            fputcsv($handle, $rows->isEmpty() ? ['id'] : array_keys($rows->first()));
            foreach ($rows as $row) {
                fputcsv($handle, array_map($safe, $row));
            }
            fclose($handle);
            $this->line("Antrean diekspor ke: {$path}");
        }

        return self::SUCCESS;
    }
}
