<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * Antrean rekonsiliasi APKUK.
 *
 * - MISSING: laporan aktif di apkuks yang tidak ada di snapshot sumber terakhir. Baris tetap
 *   aktif (histori dan referensi WO utuh) tetapi tidak boleh menjadi target WO baru.
 * - IDENTITY_CHANGE: identitas baru di sumber yang ID AppSheet-nya sama dengan laporan yang
 *   hilang (kemungkinan nomor tiang diedit). Baris sumber ditahan, tidak disisipkan, sampai
 *   Admin memutuskan apakah laporan yang sama (ganti identitas) atau laporan baru.
 *
 * Item tidak pernah dihapus. Penyelesaian otomatis (RETURNED, SUPERSEDED) dilakukan
 * apkuk:sync; penyelesaian manual hanya oleh Admin terautentikasi lewat Web Admin.
 */
class ApkukReconciliationItem extends Model
{
    public const KIND_MISSING = 'MISSING';

    public const KIND_IDENTITY_CHANGE = 'IDENTITY_CHANGE';

    public const STATUS_OPEN = 'OPEN';

    public const STATUS_RESOLVED = 'RESOLVED';

    // Otomatis oleh apkuk:sync.
    public const RESOLUTION_RETURNED = 'RETURNED';

    public const RESOLUTION_SUPERSEDED = 'SUPERSEDED';

    // Keputusan Admin di Web Admin.
    public const RESOLUTION_CONFIRMED_REMOVED = 'CONFIRMED_REMOVED';

    public const RESOLUTION_IDENTITY_CONFIRMED = 'IDENTITY_CONFIRMED';

    public const RESOLUTION_INSERTED_AS_NEW = 'INSERTED_AS_NEW';

    // Rencana apkuk:reconcile --apply yang disetujui.
    public const RESOLUTION_RECONCILED = 'RECONCILED';

    public const KIND_LABELS = [
        self::KIND_MISSING => 'Hilang dari sumber',
        self::KIND_IDENTITY_CHANGE => 'Dugaan perubahan identitas',
    ];

    public const RESOLUTION_LABELS = [
        self::RESOLUTION_RETURNED => 'Muncul kembali di sumber',
        self::RESOLUTION_SUPERSEDED => 'Digantikan kondisi sumber terbaru',
        self::RESOLUTION_CONFIRMED_REMOVED => 'Dikonfirmasi dihapus (menjadi histori)',
        self::RESOLUTION_IDENTITY_CONFIRMED => 'Dikonfirmasi laporan yang sama (identitas diperbarui)',
        self::RESOLUTION_INSERTED_AS_NEW => 'Disimpan sebagai laporan baru',
        self::RESOLUTION_RECONCILED => 'Ditandai lewat apkuk:reconcile',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'source_record' => 'array',
            'candidate_apkuk_ids' => 'array',
            'resolution_details' => 'array',
            'detection_count' => 'integer',
            'first_detected_at' => 'datetime',
            'last_detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public static function openKey(string $kind, string $legacyKey): string
    {
        return $kind.':'.$legacyKey;
    }

    /**
     * ID apkuks yang sedang menunggu rekonsiliasi. Kosong bila migration antrean belum
     * dijalankan (tanpa tabel itu memang belum ada laporan yang tertahan).
     *
     * @return array<int, int>
     */
    public static function openApkukIds(): array
    {
        if (! Schema::hasTable('apkuk_reconciliation_items')) {
            return [];
        }

        return static::query()->open()->whereNotNull('apkuk_id')->distinct()->pluck('apkuk_id')->map(fn ($id) => (int) $id)->all();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function kindLabel(): string
    {
        return self::KIND_LABELS[$this->kind] ?? $this->kind;
    }

    public function resolutionLabel(): ?string
    {
        return $this->resolution === null ? null : (self::RESOLUTION_LABELS[$this->resolution] ?? $this->resolution);
    }

    /**
     * Laporan apkuks yang dapat dipilih Admin sebagai laporan lama dari identitas baru ini.
     *
     * @return array<int, int>
     */
    public function identityCandidates(): array
    {
        $ids = array_map('intval', $this->candidate_apkuk_ids ?? []);
        if ($this->apkuk_id !== null && ! in_array((int) $this->apkuk_id, $ids, true)) {
            array_unshift($ids, (int) $this->apkuk_id);
        }

        return $ids;
    }

    public function apkuk(): BelongsTo
    {
        return $this->belongsTo(Apkuk::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function firstSeenRun(): BelongsTo
    {
        return $this->belongsTo(ApkukSyncRun::class, 'first_seen_run_id');
    }

    public function lastSeenRun(): BelongsTo
    {
        return $this->belongsTo(ApkukSyncRun::class, 'last_seen_run_id');
    }
}
