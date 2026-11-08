<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Sesi PASILA satu Posko pada satu shift. Dibuka otomatis pada awal shift (PasilaShiftOpener),
 * tidak menunggu WO APKUK selesai.
 *
 * Hal berikut dipisahkan: status sesi (status), penentuan Section (section_status), laporan patroli
 * (patrolStatus(), dihitung dari temuan dan laporan nihil), dan status WO APKUK yang dihitung dari
 * work_orders. Kewajiban P0 belum aktif (B3.3).
 */
class PasilaSession extends Model
{
    use HasUuids;

    /** Sesi tercatat dan dapat dipantau. */
    public const STATUS_DIBUKA = 'DIBUKA';

    /** Ditutup tim POSKO setelah laporan patroli diterima (B3.4); lihat PasilaClosure. */
    public const STATUS_DITUTUP = 'DITUTUP';

    public const SECTION_MENUNGGU = 'MENUNGGU_PENENTUAN';

    public const SECTION_REKOMENDASI = 'REKOMENDASI_SISTEM';

    public const SECTION_ADMIN = 'DITETAPKAN_ADMIN';

    public const HOLD_TIDAK_ADA_SECTION = 'TIDAK_ADA_SECTION_TERVERIFIKASI';

    public const TRIGGER_SCHEDULER = 'SCHEDULER';

    public const TRIGGER_CLI = 'CLI';

    public const TRIGGER_ADMIN_WEB = 'ADMIN_WEB';

    public const STATUS_LABELS = [
        self::STATUS_DIBUKA => 'Dibuka',
        self::STATUS_DITUTUP => 'Ditutup',
    ];

    public const SECTION_STATUS_LABELS = [
        self::SECTION_MENUNGGU => 'Menunggu penentuan Section',
        self::SECTION_REKOMENDASI => 'Rekomendasi sistem',
        self::SECTION_ADMIN => 'Ditetapkan Admin',
    ];

    public const HOLD_LABELS = [
        self::HOLD_TIDAK_ADA_SECTION => 'Posko belum memiliki Section terverifikasi pada referensi SECTION_PIC_RAW',
    ];

    /** Status laporan patroli, dihitung dari temuan dan laporan nihil (tidak disimpan). */
    public const PATROL_BELUM = 'BELUM_ADA_LAPORAN';

    public const PATROL_ADA_TEMUAN = 'ADA_TEMUAN';

    public const PATROL_NIHIL = 'NIHIL';

    public const PATROL_LABELS = [
        self::PATROL_BELUM => 'Belum ada laporan patroli',
        self::PATROL_ADA_TEMUAN => 'Ada temuan',
        self::PATROL_NIHIL => 'Nihil (dilaporkan petugas)',
    ];

    public const TRIGGER_LABELS = [
        self::TRIGGER_SCHEDULER => 'Scheduler',
        self::TRIGGER_CLI => 'CLI',
        self::TRIGGER_ADMIN_WEB => 'Admin Web',
    ];

    protected $fillable = [
        'code',
        'shift_instance_id',
        'posko_id',
        'status',
        'section_status',
        'penyulang_id',
        'section_key',
        'section_name',
        'section_hold_reason',
        'recommendation',
        'section_set_by_user_id',
        'section_set_at',
        'section_set_reason',
        'opened_at',
        'opened_trigger',
        'opened_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'recommendation' => 'array',
            'section_set_at' => 'datetime',
            'opened_at' => 'datetime',
        ];
    }

    public function shiftInstance(): BelongsTo
    {
        return $this->belongsTo(ShiftInstance::class);
    }

    public function posko(): BelongsTo
    {
        return $this->belongsTo(Posko::class);
    }

    public function penyulang(): BelongsTo
    {
        return $this->belongsTo(Penyulang::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function sectionSetBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'section_set_by_user_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(PasilaFinding::class);
    }

    public function nilReport(): HasOne
    {
        return $this->hasOne(PasilaNilReport::class);
    }

    public function p0Items(): HasMany
    {
        return $this->hasMany(P0Item::class);
    }

    public function closure(): HasOne
    {
        return $this->hasOne(PasilaClosure::class);
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_DITUTUP;
    }

    /**
     * Ketidaksesuaian setelah penutupan, dihitung dari record asli (penutupan tidak diubah):
     * temuan yang diterima setelah ditutup, penutupan nihil yang kemudian berisi temuan, dan
     * penutupan yang dilakukan setelah shift berakhir.
     *
     * @return array<string, string> kode => keterangan
     */
    public function closureFlags(): array
    {
        $closure = $this->relationLoaded('closure') ? $this->closure : $this->closure()->first();
        if ($closure === null) {
            return [];
        }

        $flags = [];
        $late = $this->findings()->where('received_at', '>', $closure->received_at)->count();
        if ($late > 0) {
            $flags['TEMUAN_SETELAH_DITUTUP'] = "{$late} temuan diterima setelah sesi ditutup";
        }
        if ($closure->outcome === PasilaClosure::OUTCOME_NIHIL && $this->findings()->exists()) {
            $flags['NIHIL_TERBANTAH'] = 'Ditutup nihil, kemudian ada temuan (laporan nihil digantikan)';
        }
        if ($closure->occurred_at->gte($this->shiftInstance->ends_at)) {
            $flags['DITUTUP_SETELAH_SHIFT'] = 'Ditutup setelah shift berakhir';
        }

        return $flags;
    }

    /**
     * Ada temuan → ADA_TEMUAN; laporan nihil aktif → NIHIL; selain itu BELUM_ADA_LAPORAN.
     * Daftar temuan kosong tanpa laporan nihil tidak pernah dianggap nihil.
     */
    public function patrolStatus(): string
    {
        $findings = $this->findings_count ?? $this->findings()->count();
        if ($findings > 0) {
            return self::PATROL_ADA_TEMUAN;
        }
        $nil = $this->relationLoaded('nilReport') ? $this->nilReport : $this->nilReport()->first();

        return $nil?->isActive() ? self::PATROL_NIHIL : self::PATROL_BELUM;
    }

    public function patrolStatusLabel(): string
    {
        return self::PATROL_LABELS[$this->patrolStatus()];
    }

    public function hasSection(): bool
    {
        return $this->section_key !== null;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function sectionStatusLabel(): string
    {
        return self::SECTION_STATUS_LABELS[$this->section_status] ?? $this->section_status;
    }

    public function holdReasonLabel(): ?string
    {
        return $this->section_hold_reason === null ? null : (self::HOLD_LABELS[$this->section_hold_reason] ?? $this->section_hold_reason);
    }

    public function triggerLabel(): string
    {
        return self::TRIGGER_LABELS[$this->opened_trigger] ?? $this->opened_trigger;
    }

    /**
     * Menit keterlambatan pembukaan sesi terhadap awal shift (0 bila tepat waktu).
     */
    public function openedLateMinutes(): int
    {
        return max(0, (int) floor($this->shiftInstance->starts_at->diffInSeconds($this->opened_at, false) / 60));
    }

    public function shiftHasEnded(?CarbonInterface $now = null): bool
    {
        return $this->shiftInstance->ends_at->lte($now ?? now());
    }

    /**
     * Sesi yang shift-nya sudah berakhir tetapi belum ada penutupan yang diterima server (B3.5).
     * Hanya untuk pemantauan: status tetap DIBUKA dan sistem tidak menutup sesi otomatis.
     */
    public function scopeUnclosedAfterShift(Builder $query, ?CarbonInterface $now = null): Builder
    {
        // shift_instances.ends_at disimpan UTC.
        $at = CarbonImmutable::instance($now ?? now())->utc();

        return $query->where($this->qualifyColumn('status'), self::STATUS_DIBUKA)
            ->whereHas('shiftInstance', fn (Builder $shift) => $shift->where('ends_at', '<=', $at));
    }

    /**
     * Menit sejak shift berakhir tanpa penutupan; null bila sudah ditutup atau shift belum berakhir.
     */
    public function minutesUnclosedAfterShift(?CarbonInterface $now = null): ?int
    {
        if ($this->isClosed() || ! $this->shiftHasEnded($now)) {
            return null;
        }

        return (int) floor($this->shiftInstance->ends_at->diffInSeconds($now ?? now(), false) / 60);
    }

    public function unclosedAfterShiftLabel(?CarbonInterface $now = null): ?string
    {
        $minutes = $this->minutesUnclosedAfterShift($now);

        return $minutes === null ? null : 'Belum ditutup '.self::durationLabel($minutes).' setelah shift berakhir';
    }

    /**
     * `45 menit`, `3 jam 20 menit`, `2 hari 4 jam` (menit tidak ditampilkan bila sudah berhari-hari).
     */
    public static function durationLabel(int $minutes): string
    {
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $rest = $minutes % 60;
        $parts = array_filter([
            $days > 0 ? "{$days} hari" : null,
            $hours > 0 ? "{$hours} jam" : null,
            $days === 0 && $rest > 0 ? "{$rest} menit" : null,
        ]);

        return $parts === [] ? 'kurang dari 1 menit' : implode(' ', $parts);
    }
}
