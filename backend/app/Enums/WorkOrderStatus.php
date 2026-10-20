<?php

namespace App\Enums;

/**
 * WO lifecycle. "Terlambat" bukan status tersimpan: dihitung dari due_at
 * pada WO yang masih aktif (lihat WorkOrder::isOverdue()).
 */
enum WorkOrderStatus: string
{
    case Ditugaskan = 'DITUGASKAN';
    case Dikerjakan = 'DIKERJAKAN';
    // Petugas melaporkan APKUK di tiang sudah lengkap; menunggu ACC Admin.
    case MenungguVerifikasi = 'MENUNGGU_VERIFIKASI';
    case RescheduleDiajukan = 'RESCHEDULE_DIAJUKAN';
    case DijadwalkanUlang = 'DIJADWALKAN_ULANG';
    // Pemasangan dilaporkan selesai; tidak membutuhkan ACC Admin.
    case Selesai = 'SELESAI';
    case SelesaiDiteruskan = 'SELESAI_DITERUSKAN';
    case Dibatalkan = 'DIBATALKAN';

    public function label(): string
    {
        return match ($this) {
            self::Ditugaskan => 'Ditugaskan',
            self::Dikerjakan => 'Dikerjakan',
            self::MenungguVerifikasi => 'Menunggu Verifikasi',
            self::RescheduleDiajukan => 'Reschedule Diajukan',
            self::DijadwalkanUlang => 'Dijadwalkan Ulang',
            self::Selesai => 'Selesai',
            self::SelesaiDiteruskan => 'Selesai Diteruskan',
            self::Dibatalkan => 'Dibatalkan',
        };
    }

    /**
     * Status aktif mengunci target tiang dari WO ganda.
     */
    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Selesai, self::SelesaiDiteruskan, self::Dibatalkan], true);
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Ditugaskan, self::DijadwalkanUlang => [
                self::Dikerjakan,
                self::Selesai,
                self::MenungguVerifikasi,
                self::RescheduleDiajukan,
                self::SelesaiDiteruskan,
                self::Dibatalkan,
            ],
            self::Dikerjakan => [
                self::Selesai,
                self::MenungguVerifikasi,
                self::RescheduleDiajukan,
                self::SelesaiDiteruskan,
                self::Dibatalkan,
            ],
            // ACC → Selesai; tolak → kembali ditugaskan.
            self::MenungguVerifikasi => [self::Selesai, self::Ditugaskan, self::Dibatalkan],
            // ACC → Dijadwalkan ulang; tolak → kembali ke status kerja semula.
            self::RescheduleDiajukan => [self::DijadwalkanUlang, self::Ditugaskan, self::Dikerjakan, self::Dibatalkan],
            self::Selesai, self::SelesaiDiteruskan, self::Dibatalkan => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * @return array<int, self>
     */
    public static function active(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status) => $status->isActive()));
    }
}
