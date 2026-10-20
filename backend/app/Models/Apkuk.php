<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Histori APKUK hasil sinkronisasi APKUK_RAW (`apkuk:sync`).
 *
 * Model ini hanya-baca: aplikasi merujuk baris apkuks dari WO, tetapi tidak
 * pernah mengubahnya. Sinkronisasi memakai query builder langsung.
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
}
