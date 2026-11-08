<?php

namespace App\Services\Work;

use App\Enums\WorkOrderStatus;
use App\Models\AuditLog;
use App\Models\Penyulang;
use App\Models\User;
use App\Models\WorkEscalation;
use App\Models\WorkOrder;
use App\Models\WorkOrderApkukTarget;
use App\Models\WorkOrderIssue;
use App\Models\WorkOrderReport;
use App\Models\WorkOrderReschedule;
use App\Models\WorkOrderStatusLog;
use App\Services\Audit\AuditLogger;
use App\Services\WorkOrderLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Keputusan Admin atas laporan, reschedule, limpahan, dan kendala WO. Setiap keputusan
 * mencatat pengambil keputusan, waktu, dan alasan; status WO berubah hanya lewat
 * WorkOrderLifecycleService.
 */
class WorkOrderReviewService
{
    public function __construct(
        private readonly WorkOrderLifecycleService $lifecycle,
        private readonly WorkSchedule $schedule,
        private readonly TeamWorkOrderCreator $creator,
        private readonly LocationTargetResolver $targets,
        private readonly AuditLogger $audit,
    ) {}

    public function approveReport(WorkOrderReport $report, User $admin, ?string $note = null): WorkOrderReport
    {
        return DB::transaction(function () use ($report, $admin, $note): WorkOrderReport {
            $report = $this->pendingReport($report);
            $workOrder = $report->workOrder;
            $now = now();

            $report->forceFill([
                'verification_status' => WorkOrderReport::VERIFICATION_DISETUJUI,
                'verified_by_user_id' => $admin->getKey(),
                'verified_at' => $now,
                'verification_note' => $note,
            ])->save();

            if ($report->report_type === WorkOrderReport::TYPE_TEMUAN_SUDAH_LENGKAP) {
                // APKUK di tiang ternyata sudah lengkap: tidak ada pemasangan dan tidak ada sisa.
                WorkOrderApkukTarget::query()->where('work_order_id', $workOrder->getKey())
                    ->update(['installed_quantity' => 0, 'remaining_quantity' => 0]);
            }

            $this->lifecycle->transition($workOrder, WorkOrderStatus::Selesai, $admin, WorkOrderStatusLog::ACTION_REPORT_APPROVED, $note, [
                'completed_at' => $report->occurred_at ?? $now,
            ], ['report_id' => $report->getKey(), 'report_type' => $report->report_type]);

            return $report;
        });
    }

    public function rejectReport(WorkOrderReport $report, User $admin, string $note): WorkOrderReport
    {
        $note = $this->requiredNote($note);

        return DB::transaction(function () use ($report, $admin, $note): WorkOrderReport {
            $report = $this->pendingReport($report);

            $report->forceFill([
                'verification_status' => WorkOrderReport::VERIFICATION_DITOLAK,
                'verified_by_user_id' => $admin->getKey(),
                'verified_at' => now(),
                'verification_note' => $note,
            ])->save();

            // Laporan dikembalikan: WO kembali ditugaskan agar tim mengirim laporan yang benar.
            $this->lifecycle->transition($report->workOrder, WorkOrderStatus::Ditugaskan, $admin, WorkOrderStatusLog::ACTION_REPORT_REJECTED, $note, [
                'completed_at' => null,
            ], ['report_id' => $report->getKey(), 'report_type' => $report->report_type]);

            return $report;
        });
    }

    public function approveReschedule(WorkOrderReschedule $reschedule, User $admin, ?string $note = null): WorkOrderReschedule
    {
        return DB::transaction(function () use ($reschedule, $admin, $note): WorkOrderReschedule {
            $reschedule = $this->pendingReschedule($reschedule);
            $workOrder = $reschedule->workOrder;
            $proposedDate = $reschedule->proposed_work_date->toDateString();

            $schedule = $reschedule->proposed_shift_code !== null
                ? $this->schedule->forShift($proposedDate, $reschedule->proposed_shift_code, null, 'reschedule')
                : $this->schedule->forDates($proposedDate, $proposedDate, null, 'reschedule');

            $reschedule->forceFill([
                'status' => WorkOrderReschedule::STATUS_DISETUJUI,
                'reviewed_by_user_id' => $admin->getKey(),
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            $this->lifecycle->transition($workOrder, WorkOrderStatus::DijadwalkanUlang, $admin, WorkOrderStatusLog::ACTION_RESCHEDULE_APPROVED, $note, [
                'work_date' => $proposedDate,
                'shift_code' => $reschedule->proposed_shift_code ?? $workOrder->shift_code,
            ] + $schedule, [
                'reschedule_id' => $reschedule->getKey(),
                'from_work_date' => $reschedule->from_work_date?->toDateString(),
                'from_shift_code' => $reschedule->from_shift_code,
                'to_work_date' => $proposedDate,
                'to_shift_code' => $reschedule->proposed_shift_code,
            ]);

            return $reschedule;
        });
    }

    public function rejectReschedule(WorkOrderReschedule $reschedule, User $admin, string $note): WorkOrderReschedule
    {
        $note = $this->requiredNote($note);

        return DB::transaction(function () use ($reschedule, $admin, $note): WorkOrderReschedule {
            $reschedule = $this->pendingReschedule($reschedule);
            $workOrder = $reschedule->workOrder;

            $reschedule->forceFill([
                'status' => WorkOrderReschedule::STATUS_DITOLAK,
                'reviewed_by_user_id' => $admin->getKey(),
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            // Kembali ke status kerja semula: dikerjakan bila sudah dimulai, selain itu ditugaskan.
            $back = $workOrder->started_at !== null ? WorkOrderStatus::Dikerjakan : WorkOrderStatus::Ditugaskan;
            $this->lifecycle->transition($workOrder, $back, $admin, WorkOrderStatusLog::ACTION_RESCHEDULE_REJECTED, $note, [], [
                'reschedule_id' => $reschedule->getKey(),
            ]);

            return $reschedule;
        });
    }

    /**
     * ACC limpahan: secara atomik membuat child WO pada tim tujuan dan menandai WO
     * sumber SELESAI_DITERUSKAN dengan tautan ke child.
     *
     * @param  array{field_team_id?: ?string, work_date: string, due_date: string, notes?: ?string}  $input
     */
    public function approveEscalation(WorkEscalation $escalation, User $admin, array $input, ?string $note = null): WorkEscalation
    {
        return DB::transaction(function () use ($escalation, $admin, $input, $note): WorkEscalation {
            $escalation = $this->pendingEscalation($escalation);
            $source = WorkOrder::query()->lockForUpdate()->findOrFail($escalation->source_work_order_id);

            if (! $source->status->canTransitionTo(WorkOrderStatus::SelesaiDiteruskan)) {
                throw new WorkflowConflictException("WO sumber berstatus {$source->status->label()} sehingga tidak dapat diteruskan.");
            }
            $penyulang = $source->penyulang_id ? Penyulang::query()->find($source->penyulang_id) : null;
            if ($penyulang === null) {
                throw ValidationException::withMessages(['escalation' => 'WO sumber tidak memiliki penyulang; child WO tidak dapat dibuat.']);
            }

            $child = $this->creator->create($admin, [
                'team_type' => $escalation->target_team_type,
                'work_type' => $escalation->target_work_type,
                'penyulang_id' => $penyulang->getKey(),
                'posko_id' => $source->posko_id,
                'field_team_id' => $input['field_team_id'] ?? null,
                'section_name' => $source->section_name,
                'work_date' => $input['work_date'],
                'due_date' => $input['due_date'],
                'notes' => $input['notes'] ?? $escalation->note,
            ], $penyulang, $this->targets->fromWorkOrder($source), WorkOrder::SOURCE_ESCALATION, $source, [
                'escalation_id' => $escalation->getKey(),
                'source_work_order_code' => $source->code,
            ], lockTarget: false);

            $this->lifecycle->transition($source, WorkOrderStatus::SelesaiDiteruskan, $admin, WorkOrderStatusLog::ACTION_ESCALATION_APPROVED, $note, [
                'completed_at' => now(),
            ], [
                'escalation_id' => $escalation->getKey(),
                'child_work_order_id' => $child->getKey(),
                'child_work_order_code' => $child->code,
            ]);

            $escalation->forceFill([
                'status' => WorkEscalation::STATUS_DISETUJUI,
                'reviewed_by_user_id' => $admin->getKey(),
                'reviewed_at' => now(),
                'review_note' => $note,
                'child_work_order_id' => $child->getKey(),
            ])->save();

            $this->audit->record('work_escalation', $escalation->getKey(), $source->code.' → '.$child->code, AuditLog::ACTION_APPROVED,
                ['status' => WorkEscalation::STATUS_MENUNGGU_ACC],
                ['status' => WorkEscalation::STATUS_DISETUJUI, 'child_work_order_id' => $child->getKey()],
                $admin, $note);

            return $escalation;
        });
    }

    public function rejectEscalation(WorkEscalation $escalation, User $admin, string $note): WorkEscalation
    {
        $note = $this->requiredNote($note);

        return DB::transaction(function () use ($escalation, $admin, $note): WorkEscalation {
            $escalation = $this->pendingEscalation($escalation);

            $escalation->forceFill([
                'status' => WorkEscalation::STATUS_DITOLAK,
                'reviewed_by_user_id' => $admin->getKey(),
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            $source = $escalation->sourceWorkOrder;
            $this->lifecycle->log($source, WorkOrderStatusLog::ACTION_ESCALATION_REJECTED, null, null, $admin, $note, [
                'escalation_id' => $escalation->getKey(),
            ]);
            $this->audit->record('work_escalation', $escalation->getKey(), $source->code, AuditLog::ACTION_REJECTED,
                ['status' => WorkEscalation::STATUS_MENUNGGU_ACC], ['status' => WorkEscalation::STATUS_DITOLAK], $admin, $note);

            return $escalation;
        });
    }

    public function resolveIssue(WorkOrderIssue $issue, User $admin, string $action, string $note): WorkOrderIssue
    {
        $note = $this->requiredNote($note);
        if (! array_key_exists($action, WorkOrderIssue::RESOLUTION_LABELS)) {
            throw ValidationException::withMessages(['resolution_action' => 'Pilih tindak lanjut kendala.']);
        }

        return DB::transaction(function () use ($issue, $admin, $action, $note): WorkOrderIssue {
            $issue = WorkOrderIssue::query()->lockForUpdate()->findOrFail($issue->getKey());
            if ($issue->status !== WorkOrderIssue::STATUS_TERBUKA) {
                throw new WorkflowConflictException('Kendala ini sudah ditindaklanjuti.');
            }

            $issue->forceFill([
                'status' => WorkOrderIssue::STATUS_SELESAI,
                'resolution_action' => $action,
                'resolution_note' => $note,
                'resolved_by_user_id' => $admin->getKey(),
                'resolved_at' => now(),
            ])->save();

            $this->lifecycle->log($issue->workOrder, WorkOrderStatusLog::ACTION_ISSUE_RESOLVED, null, null, $admin, $note, [
                'issue_id' => $issue->getKey(),
                'resolution_action' => $action,
            ]);
            $this->audit->record('work_order_issue', $issue->getKey(), $issue->workOrder->code.' · '.$issue->typeLabel(), AuditLog::ACTION_RESOLVED,
                ['status' => WorkOrderIssue::STATUS_TERBUKA], ['status' => WorkOrderIssue::STATUS_SELESAI, 'resolution_action' => $action], $admin, $note);

            return $issue;
        });
    }

    private function pendingReport(WorkOrderReport $report): WorkOrderReport
    {
        $report = WorkOrderReport::query()->lockForUpdate()->findOrFail($report->getKey());
        if ($report->verification_status !== WorkOrderReport::VERIFICATION_MENUNGGU) {
            throw new WorkflowConflictException('Laporan ini sudah diputuskan sebelumnya.');
        }
        if ($report->workOrder->status !== WorkOrderStatus::MenungguVerifikasi) {
            throw new WorkflowConflictException("WO berstatus {$report->workOrder->status->label()}, bukan menunggu verifikasi.");
        }

        return $report;
    }

    private function pendingReschedule(WorkOrderReschedule $reschedule): WorkOrderReschedule
    {
        $reschedule = WorkOrderReschedule::query()->lockForUpdate()->findOrFail($reschedule->getKey());
        if ($reschedule->status !== WorkOrderReschedule::STATUS_MENUNGGU_ACC) {
            throw new WorkflowConflictException('Pengajuan reschedule ini sudah diputuskan sebelumnya.');
        }
        if ($reschedule->workOrder->status !== WorkOrderStatus::RescheduleDiajukan) {
            throw new WorkflowConflictException("WO berstatus {$reschedule->workOrder->status->label()}, bukan reschedule diajukan.");
        }

        return $reschedule;
    }

    private function pendingEscalation(WorkEscalation $escalation): WorkEscalation
    {
        $escalation = WorkEscalation::query()->lockForUpdate()->findOrFail($escalation->getKey());
        if ($escalation->status !== WorkEscalation::STATUS_MENUNGGU_ACC) {
            throw new WorkflowConflictException('Permintaan limpahan ini sudah diputuskan sebelumnya.');
        }

        return $escalation;
    }

    private function requiredNote(string $note): string
    {
        $note = trim($note);
        if (mb_strlen($note) < 5) {
            throw ValidationException::withMessages(['review_note' => 'Alasan keputusan wajib diisi (minimal 5 karakter).']);
        }

        return $note;
    }

    /**
     * Tanggal hari ini WIB (untuk default form).
     */
    public static function todayWib(): string
    {
        return CarbonImmutable::now('Asia/Jakarta')->toDateString();
    }
}
