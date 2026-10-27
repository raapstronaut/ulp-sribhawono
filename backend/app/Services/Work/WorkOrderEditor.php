<?php

namespace App\Services\Work;

use App\Enums\WorkOrderStatus;
use App\Models\FieldTeam;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderStatusLog;
use App\Services\WorkOrderLifecycleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Koreksi data dan jadwal WO oleh Admin tanpa mengubah status (state machine WO tidak
 * mengenal "dijadwalkan ulang" tanpa pengajuan petugas). Alasan wajib; nilai sebelum
 * dan sesudah tercatat di work_order_status_logs.
 */
class WorkOrderEditor
{
    public const EDITABLE_STATUSES = [WorkOrderStatus::Ditugaskan, WorkOrderStatus::Dikerjakan, WorkOrderStatus::DijadwalkanUlang];

    public function __construct(
        private readonly WorkSchedule $schedule,
        private readonly WorkOrderLifecycleService $lifecycle,
    ) {}

    public static function isEditable(WorkOrder $workOrder): bool
    {
        return in_array($workOrder->status, self::EDITABLE_STATUSES, true);
    }

    /**
     * @param  array{field_team_id?: ?string, section_name?: ?string, notes?: ?string, work_date: string, shift_code?: ?string, due_date?: ?string}  $input
     */
    public function update(WorkOrder $workOrder, User $admin, array $input, string $reason): WorkOrder
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'Alasan perubahan wajib diisi (minimal 5 karakter).']);
        }

        return DB::transaction(function () use ($workOrder, $admin, $input, $reason): WorkOrder {
            /** @var WorkOrder $locked */
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->getKey());
            if (! self::isEditable($locked)) {
                throw new WorkflowConflictException("WO berstatus {$locked->status->label()} tidak dapat diubah.");
            }

            $team = null;
            if (filled($input['field_team_id'] ?? null)) {
                $team = FieldTeam::query()->whereKey($input['field_team_id'])->where('is_active', true)->first();
                if ($team === null || $team->role !== $locked->team_type) {
                    throw ValidationException::withMessages(['field_team_id' => "Tim pelaksana harus tim {$locked->team_type} yang aktif."]);
                }
                if ($locked->posko_id !== null && $team->posko_id !== null && $team->posko_id !== $locked->posko_id && $locked->team_type === WorkOrder::TEAM_POSKO) {
                    throw ValidationException::withMessages(['field_team_id' => 'Tim POSKO harus berasal dari POSKO penanggung jawab WO.']);
                }
            }

            $attributes = [
                'field_team_id' => $team?->getKey(),
                'section_name' => filled($input['section_name'] ?? null) ? trim($input['section_name']) : null,
                'notes' => filled($input['notes'] ?? null) ? trim($input['notes']) : null,
                'work_date' => $input['work_date'],
            ];

            $scheduleChanged = $input['work_date'] !== $locked->work_date?->toDateString();
            if ($locked->shift_code !== null) {
                $shift = $input['shift_code'] ?? $locked->shift_code;
                $scheduleChanged = $scheduleChanged || $shift !== $locked->shift_code;
                $attributes['shift_code'] = $shift;
                if ($scheduleChanged) {
                    $attributes += $this->schedule->forShift($input['work_date'], $shift);
                }
            } else {
                $dueDate = $input['due_date'] ?? $locked->due_at?->timezone('Asia/Jakarta')->toDateString();
                if ($dueDate === null) {
                    throw ValidationException::withMessages(['due_date' => 'Isi target selesai.']);
                }
                $currentDue = $locked->due_at?->timezone('Asia/Jakarta')->toDateString();
                $scheduleChanged = $scheduleChanged || $dueDate !== $currentDue;
                if ($scheduleChanged) {
                    $attributes += $this->schedule->forDates($input['work_date'], $dueDate);
                }
            }

            $original = $locked->only(array_keys($attributes));
            $locked->fill($attributes);
            $dirty = $locked->getDirty();
            if ($dirty === []) {
                throw ValidationException::withMessages(['reason' => 'Tidak ada data yang berubah.']);
            }
            $locked->save();

            $before = [];
            $after = [];
            foreach (array_keys($dirty) as $key) {
                $before[$key] = $this->plain($original[$key] ?? null);
                $after[$key] = $this->plain($locked->getAttribute($key));
            }

            $this->lifecycle->log($locked, WorkOrderStatusLog::ACTION_UPDATED, null, null, $admin, $reason, [
                'before' => $before,
                'after' => $after,
            ]);

            return $locked;
        });
    }

    private function plain(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return $value;
    }
}
