<?php

namespace App\Models;

use App\Services\Apkuk\ApkukSourceState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Histori APKUK hasil sinkronisasi APKUK_RAW (`apkuk:sync`).
 *
 * Model ini hanya-baca: aplikasi merujuk baris apkuks dari WO, tetapi tidak
 * pernah mengubahnya. Sinkronisasi dan penyelesaian antrean rekonsiliasi memakai
 * query builder langsung.
 *
 * Status sumber (definisi tunggal di ApkukSourceState, dipakai kandidat WO, peta, dan halaman lain):
 * - aktif: ada di snapshot sumber terakhir (source_missing_at kosong, tanpa antrean terbuka);
 * - menunggu rekonsiliasi: tidak ada di snapshot terakhir; tetap tersimpan dan terlihat
 *   bertanda, tetapi tidak dihitung aktif dan tidak boleh menjadi target WO baru;
 * - histori: diarsipkan (source_missing_at) atas keputusan Admin; baris tidak pernah dihapus.
 */
class Apkuk extends Model
{
    protected $table = 'apkuks';

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'terpasang_bh' => 'integer',
            'kurang_bh' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'last_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (): never {
            throw new LogicException('Tabel apkuks adalah histori sinkronisasi dan tidak boleh diubah dari aplikasi.');
        };

        static::saving($guard);
        static::deleting($guard);
    }

    public function workOrderTargets(): HasMany
    {
        return $this->hasMany(WorkOrderApkukTarget::class);
    }

    public function reconciliationItems(): HasMany
    {
        return $this->hasMany(ApkukReconciliationItem::class);
    }

    /** Laporan yang ada di snapshot sumber terakhir (lihat ApkukSourceState). */
    public function scopeSourceActive(Builder $query): Builder
    {
        return ApkukSourceState::active($query);
    }

    /** Laporan yang tidak ada di snapshot sumber terakhir dan menunggu keputusan. */
    public function scopeAwaitingReconciliation(Builder $query): Builder
    {
        return ApkukSourceState::pending($query);
    }

    /** Laporan yang sudah diarsipkan; keputusan tercatat di antrean atau apkuk_reconciliations. */
    public function scopeSourceHistory(Builder $query): Builder
    {
        return ApkukSourceState::history($query);
    }
}
