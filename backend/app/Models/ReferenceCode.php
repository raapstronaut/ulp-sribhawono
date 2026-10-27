<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Pilihan dropdown terpusat (alasan reschedule, alasan limpahan, kode temuan, dll),
 * dikelola Admin di Master Data dan dipakai Web maupun mobile.
 */
class ReferenceCode extends Model
{
    use HasUuids;

    public const CATEGORY_RESCHEDULE_REASON = 'RESCHEDULE_REASON';

    public const CATEGORY_ESCALATION_REASON = 'ESCALATION_REASON';

    public const CATEGORY_INSPECTION_FINDING = 'INSPECTION_FINDING';

    // Kategori yang dipakai alur aplikasi. Kategori lain boleh ada (mis. hasil sinkron lama).
    public const CATEGORIES = [
        self::CATEGORY_RESCHEDULE_REASON => 'Alasan reschedule',
        self::CATEGORY_ESCALATION_REASON => 'Alasan limpahan',
        self::CATEGORY_INSPECTION_FINDING => 'Jenis temuan inspeksi',
    ];

    public const ROLE_SCOPES = ['POSKO', 'HARSUS', 'INSPEKSI', 'ROW'];

    protected $fillable = [
        'category',
        'code',
        'label',
        'role_scope',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActiveFor(Builder $query, string $category, ?string $role = null): Builder
    {
        return $query->where('category', $category)
            ->where('is_active', true)
            ->when($role !== null, fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->whereNull('role_scope')
                ->orWhere('role_scope', $role)))
            ->orderBy('sort_order')
            ->orderBy('label');
    }

    /**
     * @return array<string, string> code => label
     */
    public static function options(string $category, ?string $role = null): array
    {
        return self::query()->activeFor($category, $role)->pluck('label', 'code')->all();
    }

    public static function isValid(string $category, string $code, ?string $role = null): bool
    {
        return self::query()->activeFor($category, $role)->where('code', $code)->exists();
    }

    public static function labelFor(string $category, ?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return self::query()->where('category', $category)->where('code', $code)->value('label');
    }
}
