<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Foto bukti temuan inspeksi. Setiap file satu baris; foto lama tidak ditimpa.
 */
class InspectionFindingMedia extends Model
{
    use HasUuids;

    protected $table = 'inspection_finding_media';

    protected $fillable = [
        'inspection_finding_id',
        'capture_source',
        'disk',
        'storage_path',
        'sha256',
        'mime_type',
        'size_bytes',
        'captured_at',
        'latitude',
        'longitude',
        'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'captured_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'uploaded_at' => 'datetime',
        ];
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(InspectionFinding::class, 'inspection_finding_id');
    }
}
