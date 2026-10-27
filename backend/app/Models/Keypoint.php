<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Aset jaringan hasil sinkronisasi KEYPOINT (`keypoint:sync`). Hanya-baca dari aplikasi.
 */
class Keypoint extends Model
{
    protected $table = 'keypoints';

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'last_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (): never {
            throw new LogicException('Tabel keypoints adalah hasil sinkronisasi dan tidak boleh diubah dari aplikasi.');
        };

        static::saving($guard);
        static::deleting($guard);
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null
            && abs((float) $this->latitude) <= 90 && abs((float) $this->longitude) <= 180;
    }
}
