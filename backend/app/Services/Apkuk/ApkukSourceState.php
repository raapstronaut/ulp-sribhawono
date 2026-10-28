<?php

namespace App\Services\Apkuk;

use App\Models\ApkukReconciliationItem;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/**
 * Definisi tunggal status sumber laporan apkuks untuk query builder maupun Eloquent.
 *
 * - aktif: ada di snapshot APKUK_RAW terakhir; satu-satunya yang dihitung sebagai laporan
 *   aktif untuk penugasan, dashboard operasional, kandidat WO, dan analitik;
 * - menunggu rekonsiliasi: tidak ada di snapshot terakhir dan belum diputuskan (bisa sedang
 *   diedit/dipindahkan di SPS BOS); tetap tersimpan, tidak dihitung aktif;
 * - histori: diarsipkan (source_missing_at) atas keputusan Admin; baris tidak pernah dihapus.
 *
 * Membutuhkan tabel apkuk_reconciliation_items (migration 2026_10_28_100001).
 */
final class ApkukSourceState
{
    /**
     * @template TQuery of Builder|EloquentBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function active(Builder|EloquentBuilder $query, string $table = 'apkuks'): Builder|EloquentBuilder
    {
        return $query->whereNull("{$table}.source_missing_at")->whereNotExists(self::openItem($table));
    }

    /**
     * @template TQuery of Builder|EloquentBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function pending(Builder|EloquentBuilder $query, string $table = 'apkuks'): Builder|EloquentBuilder
    {
        return $query->whereNull("{$table}.source_missing_at")->whereExists(self::openItem($table));
    }

    /**
     * @template TQuery of Builder|EloquentBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function history(Builder|EloquentBuilder $query, string $table = 'apkuks'): Builder|EloquentBuilder
    {
        return $query->whereNotNull("{$table}.source_missing_at");
    }

    private static function openItem(string $table): \Closure
    {
        return fn (Builder $item) => $item->selectRaw('1')
            ->from('apkuk_reconciliation_items')
            ->whereColumn('apkuk_reconciliation_items.apkuk_id', "{$table}.id")
            ->where('apkuk_reconciliation_items.status', ApkukReconciliationItem::STATUS_OPEN);
    }
}
