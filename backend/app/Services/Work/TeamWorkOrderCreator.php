<?php

namespace App\Services\Work;

use App\Enums\WorkOrderStatus;
use App\Models\FieldTeam;
use App\Models\Penyulang;
use App\Models\Posko;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderLocationTarget;
use App\Models\WorkOrderStatusLog;
use App\Services\WorkOrderLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pembuatan WO HARSUS (PEKERJAAN_KHUSUS, wajib aset jaringan) dan ROW
 * (RABAS/PANGKAS/TEBANG, titik vegetasi) secara manual oleh Admin, juga child WO
 * limpahan dan WO tindak lanjut temuan inspeksi.
 *
 * Penugasan ganda dicegah unique index work_order_location_targets.active_target_key:
 * satu titik hanya boleh punya satu WO aktif untuk tim + jenis pekerjaan yang sama. Untuk ROW
 * titiknya adalah pohon (`POHON:{ID POHON}`), sehingga dua pohon pada satu tiang tetap terpisah
 * dan import ulang staging tidak membuka celah WO ganda.
 */
class TeamWorkOrderCreator
{
    private const CODE_ATTEMPTS = 3;

    public function __construct(
        private readonly LocationTargetResolver $targets,
        private readonly WorkOrderCodeGenerator $codes,
        private readonly WorkSchedule $schedule,
        private readonly WorkOrderLifecycleService $lifecycle,
        private readonly RowPointConflictGuard $rowPointGuard,
    ) {}

    /**
     * @param  array{team_type: string, work_type: string, penyulang_id: string, posko_id?: ?string, field_team_id?: ?string, section_name?: ?string, work_date: string, due_date: string, notes?: ?string, keypoint_id?: int|string|null, vegetation_id?: int|string|null, vegetation_id_pohon?: ?string}  $input
     */
    public function createManual(User $admin, array $input, ?CarbonImmutable $now = null): WorkOrder
    {
        $penyulang = $this->penyulang($input['penyulang_id']);
        $target = match ($input['team_type']) {
            WorkOrder::TEAM_HARSUS => filled($input['keypoint_id'] ?? null)
                ? $this->targets->keypoint((int) $input['keypoint_id'], $penyulang)
                : throw ValidationException::withMessages(['keypoint_id' => 'WO HARSUS manual wajib menunjuk aset jaringan.']),
            WorkOrder::TEAM_ROW => filled($input['vegetation_id'] ?? null)
                ? $this->targets->vegetation((int) $input['vegetation_id'], $penyulang, $input['vegetation_id_pohon'] ?? null)
                : throw ValidationException::withMessages(['vegetation_id' => 'WO ROW manual wajib menunjuk titik vegetasi.']),
            default => throw ValidationException::withMessages(['team_type' => 'Tim tidak dikenal untuk WO manual ini.']),
        };

        return $this->create($admin, $input, $penyulang, $target, WorkOrder::SOURCE_ADMIN_MANUAL, null, [], $now);
    }

    /**
     * @param  array{team_type: string, work_type: string, penyulang_id: string, posko_id?: ?string, field_team_id?: ?string, section_name?: ?string, work_date: string, due_date: string, notes?: ?string}  $input
     * @param  array<string, mixed>  $target  hasil LocationTargetResolver
     * @param  array<string, mixed>  $payload  konteks tambahan untuk log CREATED
     */
    public function create(
        User $admin,
        array $input,
        Penyulang $penyulang,
        array $target,
        string $source,
        ?WorkOrder $parent = null,
        array $payload = [],
        ?CarbonImmutable $now = null,
        bool $lockTarget = true,
    ): WorkOrder {
        $now ??= CarbonImmutable::now();
        $teamType = $input['team_type'];
        if (! in_array($input['work_type'], WorkOrder::WORK_TYPES_BY_TEAM[$teamType] ?? [], true) || $teamType === WorkOrder::TEAM_POSKO) {
            throw ValidationException::withMessages(['work_type' => 'Jenis pekerjaan tidak sesuai dengan tim.']);
        }

        $posko = filled($input['posko_id'] ?? null) ? $this->posko($input['posko_id']) : null;
        $team = filled($input['field_team_id'] ?? null) ? $this->team($input['field_team_id'], $teamType) : null;
        $schedule = $this->schedule->forDates($input['work_date'], $input['due_date'], $now);
        $activeKey = $lockTarget ? implode('|', [$teamType, $input['work_type'], $target['target_key']]) : null;

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($admin, $input, $penyulang, $target, $source, $parent, $payload, $now, $posko, $team, $schedule, $activeKey, $teamType): WorkOrder {
                    // ROW manual and ROW generator share exactly the same
                    // PostgreSQL transaction-scoped lock, before code allocation.
                    // Skip intentional lockTarget=false exception paths.
                    if ($teamType === WorkOrder::TEAM_ROW && $activeKey !== null) {
                        $this->rowPointGuard->lock();
                        $this->rowPointGuard->assertNoActiveRowAtPoint($target);
                    }
                    $this->assertNotDuplicate($activeKey);

                    $workOrder = WorkOrder::create([
                        'code' => $this->codes->next(WorkOrderCodeGenerator::kindFor($teamType), $now),
                        'team_type' => $teamType,
                        'work_type' => $input['work_type'],
                        'source' => $source,
                        'status' => WorkOrderStatus::Ditugaskan,
                        'posko_id' => $posko?->getKey(),
                        'field_team_id' => $team?->getKey(),
                        'penyulang_id' => $penyulang->getKey(),
                        'section_name' => filled($input['section_name'] ?? null) ? trim($input['section_name']) : ($target['section_source'] ?? null),
                        'work_date' => $input['work_date'],
                        'scheduled_start_at' => $schedule['scheduled_start_at'],
                        'due_at' => $schedule['due_at'],
                        'parent_work_order_id' => $parent?->getKey(),
                        'notes' => filled($input['notes'] ?? null) ? trim($input['notes']) : null,
                        'created_by_user_id' => $admin->getKey(),
                    ]);

                    WorkOrderLocationTarget::create($target + [
                        'work_order_id' => $workOrder->getKey(),
                        'active_target_key' => $activeKey,
                    ]);

                    $this->lifecycle->log($workOrder, WorkOrderStatusLog::ACTION_CREATED, null, WorkOrderStatus::Ditugaskan, $admin, null, $payload + [
                        'target_type' => $target['target_type'],
                        'target_ref' => $target['source_ref'],
                        'target_label' => $target['label'],
                        'field_team_id' => $team?->getKey(),
                        'work_date' => $input['work_date'],
                        'due_date' => $input['due_date'],
                        'parent_work_order_id' => $parent?->getKey(),
                    ]);

                    return $workOrder;
                });
            } catch (UniqueConstraintViolationException $e) {
                // Request bersamaan: lebih dulu membuat WO aktif untuk titik yang sama, atau bentrok kode.
                $this->assertNotDuplicate($activeKey);
                if ($attempt >= self::CODE_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    private function assertNotDuplicate(?string $activeKey): void
    {
        if ($activeKey === null) {
            return;
        }

        $existing = WorkOrderLocationTarget::query()
            ->where('active_target_key', $activeKey)
            ->with('workOrder:id,code')
            ->first();
        if ($existing !== null) {
            throw ValidationException::withMessages([
                'target' => "Titik ini sudah memiliki WO aktif dengan jenis yang sama ({$existing->workOrder?->code}). Selesaikan atau batalkan WO tersebut terlebih dahulu.",
            ]);
        }
    }

    private function penyulang(string $id): Penyulang
    {
        return Penyulang::query()->whereKey($id)->where('is_active', true)->first()
            ?? throw ValidationException::withMessages(['penyulang_id' => 'Penyulang tidak ditemukan atau tidak aktif.']);
    }

    private function posko(string $id): Posko
    {
        return Posko::query()->whereKey($id)->where('is_active', true)->first()
            ?? throw ValidationException::withMessages(['posko_id' => 'POSKO tidak ditemukan atau tidak aktif.']);
    }

    private function team(string $id, string $teamType): FieldTeam
    {
        $team = FieldTeam::query()->whereKey($id)->where('is_active', true)->first();
        if ($team === null || $team->role !== $teamType) {
            throw ValidationException::withMessages(['field_team_id' => "Tim pelaksana harus tim {$teamType} yang aktif."]);
        }

        return $team;
    }
}
