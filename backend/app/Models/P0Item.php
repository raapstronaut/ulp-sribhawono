<?php

namespace App\Models;

use App\Enums\WorkOrderStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Satu P0 POSKO: usulan SYSTEM (menunggu ACC Admin) atau P0 manual Admin, terkait sesi PASILA dan
 * sumber masalah yang tercatat. Pekerjaannya adalah WO P0 (work_order_id), dibuat saat ACC atau saat
 * P0 manual dibuat. Usulan yang belum di-ACC atau ditolak tidak pernah menjadi pekerjaan.
 */
class P0Item extends Model
{
    use HasUuids;

    public const ORIGIN_SYSTEM = 'SYSTEM';

    public const ORIGIN_ADMIN = 'ADMIN';

    public const SOURCE_PASILA_FINDING = 'PASILA_FINDING';

    public const SOURCE_INSPECTION_FINDING = 'INSPECTION_FINDING';

    public const APPROVAL_MENUNGGU = 'MENUNGGU_ACC';

    public const APPROVAL_DISETUJUI = 'DISETUJUI';

    public const APPROVAL_DITOLAK = 'DITOLAK';

    public const APPROVAL_TIDAK_PERLU = 'TIDAK_PERLU';

    public const REPLACEMENT_TIDAK_PERLU = 'TIDAK_PERLU';

    public const REPLACEMENT_WAJIB = 'WAJIB';

    public const REPLACEMENT_TERPENUHI = 'TERPENUHI';

    /** Kategori monitoring (dihitung dari persetujuan, pengganti, dan status WO). */
    public const STAGE_MENUNGGU_ACC = 'MENUNGGU_ACC';

    public const STAGE_MENUNGGU_PENGGANTI = 'MENUNGGU_PENGGANTI';

    public const STAGE_DIGANTI = 'DITOLAK_SUDAH_DIGANTI';

    public const STAGE_AKTIF = 'AKTIF';

    public const STAGE_MENUNGGU_VERIFIKASI = 'MENUNGGU_VERIFIKASI';

    public const STAGE_SELESAI = 'SELESAI';

    public const STAGE_TIDAK_DILANJUTKAN = 'TIDAK_DILANJUTKAN';

    public const ORIGIN_LABELS = [
        self::ORIGIN_SYSTEM => 'Usulan SYSTEM',
        self::ORIGIN_ADMIN => 'Manual Admin',
    ];

    public const SOURCE_LABELS = [
        self::SOURCE_PASILA_FINDING => 'Temuan PASILA',
        self::SOURCE_INSPECTION_FINDING => 'Temuan inspeksi tervalidasi',
    ];

    public const STAGE_LABELS = [
        self::STAGE_MENUNGGU_ACC => 'Menunggu ACC Admin',
        self::STAGE_MENUNGGU_PENGGANTI => 'Ditolak, menunggu P0 pengganti',
        self::STAGE_DIGANTI => 'Ditolak, pengganti sudah dibuat',
        self::STAGE_AKTIF => 'Aktif (dikerjakan POSKO)',
        self::STAGE_MENUNGGU_VERIFIKASI => 'Selesai dilaporkan, menunggu verifikasi',
        self::STAGE_SELESAI => 'Selesai',
        self::STAGE_TIDAK_DILANJUTKAN => 'WO dibatalkan/diteruskan',
    ];

    protected $fillable = [
        'code',
        'pasila_session_id',
        'sequence',
        'posko_id',
        'penyulang_id',
        'section_key',
        'section_name',
        'origin',
        'source_type',
        'pasila_finding_id',
        'inspection_finding_id',
        'active_source_key',
        'source_snapshot',
        'description',
        'recommendation',
        'approval_status',
        'replacement_status',
        'replaces_p0_item_id',
        'work_order_id',
        'created_by_user_id',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_note',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'source_snapshot' => 'array',
            'recommendation' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PasilaSession::class, 'pasila_session_id');
    }

    public function posko(): BelongsTo
    {
        return $this->belongsTo(Posko::class);
    }

    public function penyulang(): BelongsTo
    {
        return $this->belongsTo(Penyulang::class);
    }

    public function pasilaFinding(): BelongsTo
    {
        return $this->belongsTo(PasilaFinding::class);
    }

    public function inspectionFinding(): BelongsTo
    {
        return $this->belongsTo(InspectionFinding::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_p0_item_id');
    }

    public function replacement(): HasOne
    {
        return $this->hasOne(self::class, 'replaces_p0_item_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * Tahap monitoring pekerjaan P0 khusus. SELESAI berarti pekerjaan selesai,
     * bukan pemenuhan kuota (P0 tidak memiliki target minimal per shift).
     */
    public function stage(): string
    {
        if ($this->approval_status === self::APPROVAL_MENUNGGU) {
            return self::STAGE_MENUNGGU_ACC;
        }
        if ($this->approval_status === self::APPROVAL_DITOLAK) {
            return $this->replacement_status === self::REPLACEMENT_TERPENUHI ? self::STAGE_DIGANTI : self::STAGE_MENUNGGU_PENGGANTI;
        }

        return match ($this->workOrder?->status) {
            WorkOrderStatus::Selesai => self::STAGE_SELESAI,
            WorkOrderStatus::MenungguVerifikasi => self::STAGE_MENUNGGU_VERIFIKASI,
            WorkOrderStatus::Dibatalkan, WorkOrderStatus::SelesaiDiteruskan => self::STAGE_TIDAK_DILANJUTKAN,
            default => self::STAGE_AKTIF,
        };
    }

    public function stageLabel(): string
    {
        return self::STAGE_LABELS[$this->stage()];
    }

    public function originLabel(): string
    {
        return self::ORIGIN_LABELS[$this->origin] ?? $this->origin;
    }

    public function sourceLabel(): string
    {
        return self::SOURCE_LABELS[$this->source_type] ?? $this->source_type;
    }
}
