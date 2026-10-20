<?php

namespace App\Services;

use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderApkukTarget;
use App\Models\WorkOrderStatusLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya jalur perubahan status WO: memvalidasi transisi, melepas kunci
 * target saat WO berakhir, dan menulis log audit.
 */
class WorkOrderLifecycleService
{
    /**
     * @param  array<string, mixed>  $attributes  Kolom tambahan yang diubah bersama status.
     * @param  array<string, mixed>  $payload
     */
    public function transition(
        WorkOrder $workOrder,
        WorkOrderStatus $to,
        ?User $actor,
        string $action = WorkOrderStatusLog::ACTION_STATUS_CHANGED,
        ?string $reason = null,
        array $attributes = [],
        array $payload = [],
    ): WorkOrder {
        return DB::transaction(function () use ($workOrder, $to, $actor, $action, $reason, $attributes, $payload): WorkOrder {
            /** @var WorkOrder $locked */
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->getKey());
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw ValidationException::withMessages([
                    'status' => "WO berstatus {$from->label()} tidak dapat diubah menjadi {$to->label()}.",
                ]);
            }

            $locked->fill($attributes);
            $locked->status = $to;
            $locked->save();

            if ($to->isTerminal()) {
                // WO berakhir: tiang boleh dibuatkan WO baru (mis. sisa kebutuhan).
                WorkOrderApkukTarget::query()
                    ->where('work_order_id', $locked->getKey())
                    ->update(['active_target_key' => null]);
            }

            $this->log($locked, $action, $from, $to, $actor, $reason, $payload);

            return $locked;
        });
    }

    public function cancel(WorkOrder $workOrder, User $admin, string $reason): WorkOrder
    {
        return $this->transition(
            $workOrder,
            WorkOrderStatus::Dibatalkan,
            $admin,
            WorkOrderStatusLog::ACTION_CANCELLED,
            $reason,
            [
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $admin->getKey(),
                'cancel_reason' => $reason,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function log(
        WorkOrder $workOrder,
        string $action,
        ?WorkOrderStatus $from,
        ?WorkOrderStatus $to,
        ?User $actor,
        ?string $reason = null,
        array $payload = [],
    ): WorkOrderStatusLog {
        return WorkOrderStatusLog::create([
            'work_order_id' => $workOrder->getKey(),
            'action' => $action,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'actor_user_id' => $actor?->getKey(),
            'reason' => $reason,
            'payload' => $payload === [] ? null : $payload,
        ]);
    }
}
