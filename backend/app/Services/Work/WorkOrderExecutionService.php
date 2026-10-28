<?php

namespace App\Services\Work;

use App\Enums\WorkOrderStatus;
use App\Models\FieldPersonnel;
use App\Models\ReferenceCode;
use App\Models\WorkEscalation;
use App\Models\WorkOrder;
use App\Models\WorkOrderApkukTarget;
use App\Models\WorkOrderIssue;
use App\Models\WorkOrderMedia;
use App\Models\WorkOrderReport;
use App\Models\WorkOrderReportPersonnel;
use App\Models\WorkOrderReschedule;
use App\Models\WorkOrderStatusLog;
use App\Services\ShiftService;
use App\Services\WorkOrderLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Tindakan pelaksanaan WO oleh tim lapangan (API mobile) atau Admin atas nama tim.
 *
 * Aturan yang ditegakkan:
 * - PEMASANGAN_SELESAI (APKUK): langsung SELESAI tanpa ACC, wajib jumlah APKUK terpasang.
 * - TEMUAN_SUDAH_LENGKAP (APKUK): MENUNGGU_VERIFIKASI Admin.
 * - PEKERJAAN_SELESAI (HARSUS/ROW): MENUNGGU_VERIFIKASI Admin (aturan sementara, belum disahkan).
 * - Penyelesaian wajib foto BEFORE + AFTER; temuan minimal satu foto; minimal satu petugas.
 * - Reschedule: alasan dropdown + catatan wajib, ACC Admin.
 * - Permintaan yang diulang dengan client_request_id sama tidak membuat data ganda, termasuk
 *   bila dua request datang bersamaan (IdempotentSubmission); isi berbeda dengan kunci sama → 422.
 * - Mulai pekerjaan idempoten: WO yang sudah DIKERJAKAN dikembalikan apa adanya.
 */
class WorkOrderExecutionService
{
    private const WORKABLE = [WorkOrderStatus::Ditugaskan, WorkOrderStatus::DijadwalkanUlang, WorkOrderStatus::Dikerjakan];

    public function __construct(
        private readonly WorkOrderLifecycleService $lifecycle,
        private readonly MediaStore $media,
        private readonly ShiftService $shifts,
    ) {}

    /**
     * @return array<int, string>
     */
    public static function reportTypesFor(WorkOrder $workOrder): array
    {
        return $workOrder->isApkuk()
            ? [WorkOrderReport::TYPE_PEMASANGAN_SELESAI, WorkOrderReport::TYPE_TEMUAN_SUDAH_LENGKAP]
            : [WorkOrderReport::TYPE_PEKERJAAN_SELESAI];
    }

    public function start(WorkOrder $workOrder, FieldActor $actor): WorkOrder
    {
        // Retry setelah timeout: WO sudah dimulai → keadaan sekarang, tanpa log ganda.
        if ($workOrder->refresh()->status === WorkOrderStatus::Dikerjakan) {
            return $workOrder;
        }
        $this->assertWorkable($workOrder, [WorkOrderStatus::Ditugaskan, WorkOrderStatus::DijadwalkanUlang]);

        try {
            return $this->lifecycle->transition(
                $workOrder,
                WorkOrderStatus::Dikerjakan,
                $actor->user,
                WorkOrderStatusLog::ACTION_STARTED,
                null,
                ['started_at' => $workOrder->started_at ?? now()],
                $actor->payload(),
            );
        } catch (ValidationException $e) {
            // Request bersamaan lebih dulu memulai WO yang sama.
            if ($workOrder->refresh()->status === WorkOrderStatus::Dikerjakan) {
                return $workOrder;
            }
            throw $e;
        }
    }

    /**
     * @param  array{report_type: string, installed_quantity?: int|string|null, notes?: ?string, latitude?: float|string|null, longitude?: float|string|null, gps_accuracy_m?: float|string|null, occurred_at?: ?string, personnel_ids?: array<int, string>, personnel_names?: array<int, string>, client_request_id?: ?string}  $data
     * @param  array{before?: array<int, UploadedFile>, after?: array<int, UploadedFile>, evidence?: array<int, UploadedFile>}  $photos
     */
    public function submitReport(WorkOrder $workOrder, FieldActor $actor, array $data, array $photos, string $captureSource = WorkOrderMedia::SOURCE_CAMERA): WorkOrderReport
    {
        $installed = $data['report_type'] === WorkOrderReport::TYPE_PEMASANGAN_SELESAI
            ? filter_var($data['installed_quantity'] ?? null, FILTER_VALIDATE_INT)
            : null;
        // Seluruh isi yang disimpan; foto bukti (evidence) disimpan sebagai AFTER.
        $fingerprint = [
            'report_type' => $data['report_type'],
            'installed_quantity' => $installed === false ? null : $installed,
            'notes' => IdempotentSubmission::text($data['notes'] ?? null),
            'latitude' => IdempotentSubmission::decimal($data['latitude'] ?? null, 7),
            'longitude' => IdempotentSubmission::decimal($data['longitude'] ?? null, 7),
            'gps_accuracy_m' => IdempotentSubmission::decimal($data['gps_accuracy_m'] ?? null, 2),
            'personnel' => fn (WorkOrderReport $report) => IdempotentSubmission::samePersonnel($report->personnel, $data['personnel_ids'] ?? [], $data['personnel_names'] ?? []),
            'photos' => fn (WorkOrderReport $report) => IdempotentSubmission::sameList($report->media->where('photo_type', WorkOrderMedia::PHOTO_BEFORE)->pluck('sha256')->all(), IdempotentSubmission::hashes($photos['before'] ?? []))
                && IdempotentSubmission::sameList($report->media->where('photo_type', WorkOrderMedia::PHOTO_AFTER)->pluck('sha256')->all(), IdempotentSubmission::hashes(array_merge($photos['after'] ?? [], $photos['evidence'] ?? []))),
        ];
        if (filled($data['occurred_at'] ?? null)) {
            $fingerprint['occurred_at'] = self::occurredAt($data['occurred_at']);
        }

        return IdempotentSubmission::run(
            WorkOrderReport::class,
            $data['client_request_id'] ?? null,
            ['work_order_id', $workOrder->getKey(), 'WO lain'],
            $fingerprint,
            fn () => $this->createReport($workOrder, $actor, $data, $photos, $captureSource),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{before?: array<int, UploadedFile>, after?: array<int, UploadedFile>, evidence?: array<int, UploadedFile>}  $photos
     */
    private function createReport(WorkOrder $workOrder, FieldActor $actor, array $data, array $photos, string $captureSource): WorkOrderReport
    {
        $type = $data['report_type'];
        if (! in_array($type, self::reportTypesFor($workOrder), true)) {
            throw ValidationException::withMessages(['report_type' => 'Jenis laporan tidak sesuai dengan jenis WO.']);
        }
        $this->assertWorkable($workOrder, self::WORKABLE);

        $isCompletion = $type !== WorkOrderReport::TYPE_TEMUAN_SUDAH_LENGKAP;
        $before = $photos['before'] ?? [];
        $after = $photos['after'] ?? [];
        $evidence = $photos['evidence'] ?? [];
        if ($isCompletion && ($before === [] || $after === [])) {
            throw ValidationException::withMessages(['photos' => 'Penyelesaian pekerjaan wajib menyertakan foto BEFORE dan AFTER.']);
        }
        if (! $isCompletion && $before === [] && $after === [] && $evidence === []) {
            throw ValidationException::withMessages(['photos' => 'Temuan wajib menyertakan minimal satu foto bukti.']);
        }

        $installed = null;
        $remaining = null;
        if ($type === WorkOrderReport::TYPE_PEMASANGAN_SELESAI) {
            $target = $workOrder->apkukTarget;
            $installed = filter_var($data['installed_quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($installed === false || $installed < 1 || $installed > 1000) {
                throw ValidationException::withMessages(['installed_quantity' => 'Isi jumlah APKUK yang benar-benar terpasang (1–1000 bh).']);
            }
            $remaining = max(0, (int) $target?->target_quantity - $installed);
        }

        $personnel = $this->personnel($workOrder, $actor, $data['personnel_ids'] ?? [], $data['personnel_names'] ?? []);
        $occurredAt = filled($data['occurred_at'] ?? null) ? self::occurredAt($data['occurred_at']) : CarbonImmutable::now();
        if ($occurredAt->gt(CarbonImmutable::now()->addMinutes(10))) {
            throw ValidationException::withMessages(['occurred_at' => 'Waktu pelaksanaan tidak boleh di masa depan.']);
        }

        $stored = [];
        try {
            $directory = 'work-orders/'.$workOrder->getKey();
            foreach (['BEFORE' => $before, 'AFTER' => $after, 'AFTER_EVIDENCE' => $evidence] as $kind => $files) {
                foreach ($files as $file) {
                    $stored[] = ['photo_type' => $kind === 'AFTER_EVIDENCE' ? WorkOrderMedia::PHOTO_AFTER : $kind] + $this->media->store($file, $directory);
                }
            }

            return DB::transaction(function () use ($workOrder, $actor, $data, $type, $installed, $remaining, $personnel, $occurredAt, $stored, $captureSource, $isCompletion): WorkOrderReport {
                $verification = $type === WorkOrderReport::TYPE_PEMASANGAN_SELESAI
                    ? WorkOrderReport::VERIFICATION_TIDAK_PERLU
                    : WorkOrderReport::VERIFICATION_MENUNGGU;

                $report = WorkOrderReport::create([
                    'work_order_id' => $workOrder->getKey(),
                    'report_type' => $type,
                    'verification_status' => $verification,
                    'installed_quantity' => $installed,
                    'remaining_quantity' => $remaining,
                    'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
                    'latitude' => $data['latitude'] ?? null,
                    'longitude' => $data['longitude'] ?? null,
                    'gps_accuracy_m' => $data['gps_accuracy_m'] ?? null,
                    'occurred_at' => $occurredAt,
                    'received_at' => now(),
                    'submitted_by_field_team_id' => $actor->team?->getKey(),
                    'submitted_by_user_id' => $actor->user->getKey(),
                    'client_request_id' => $data['client_request_id'] ?? null,
                ]);

                foreach ($personnel as $person) {
                    WorkOrderReportPersonnel::create(['work_order_report_id' => $report->getKey()] + $person);
                }
                foreach ($stored as $file) {
                    WorkOrderMedia::create($file + [
                        'work_order_id' => $workOrder->getKey(),
                        'work_order_report_id' => $report->getKey(),
                        'capture_source' => $captureSource,
                        'captured_at' => $occurredAt,
                        'latitude' => $data['latitude'] ?? null,
                        'longitude' => $data['longitude'] ?? null,
                        'gps_accuracy_m' => $data['gps_accuracy_m'] ?? null,
                        'uploaded_at' => now(),
                    ]);
                }

                if ($type === WorkOrderReport::TYPE_PEMASANGAN_SELESAI) {
                    WorkOrderApkukTarget::query()->where('work_order_id', $workOrder->getKey())
                        ->update(['installed_quantity' => $installed, 'remaining_quantity' => $remaining]);
                }

                $to = $verification === WorkOrderReport::VERIFICATION_TIDAK_PERLU ? WorkOrderStatus::Selesai : WorkOrderStatus::MenungguVerifikasi;
                $this->lifecycle->transition(
                    $workOrder,
                    $to,
                    $actor->user,
                    WorkOrderStatusLog::ACTION_REPORT_SUBMITTED,
                    null,
                    array_filter([
                        'started_at' => $workOrder->started_at ?? $occurredAt,
                        'completed_at' => $to === WorkOrderStatus::Selesai ? $occurredAt : null,
                    ]),
                    $actor->payload() + [
                        'report_id' => $report->getKey(),
                        'report_type' => $type,
                        'installed_quantity' => $installed,
                        'remaining_quantity' => $remaining,
                        'photo_count' => count($stored),
                        'completion' => $isCompletion,
                    ],
                );

                return $report;
            });
        } catch (Throwable $e) {
            $this->media->discard($stored);
            throw $e;
        }
    }

    /**
     * @param  array{reason_code: string, note: string, proposed_work_date: string, proposed_shift_code?: ?string, client_request_id?: ?string}  $data
     */
    public function requestReschedule(WorkOrder $workOrder, FieldActor $actor, array $data): WorkOrderReschedule
    {
        return IdempotentSubmission::run(
            WorkOrderReschedule::class,
            $data['client_request_id'] ?? null,
            ['work_order_id', $workOrder->getKey(), 'WO lain'],
            [
                'reason_code' => $data['reason_code'],
                'note' => IdempotentSubmission::text($data['note']),
                'proposed_work_date' => $data['proposed_work_date'],
                'proposed_shift_code' => $workOrder->shift_code !== null ? ($data['proposed_shift_code'] ?? null) : null,
            ],
            fn () => $this->createReschedule($workOrder, $actor, $data),
        );
    }

    /**
     * @param  array{reason_code: string, note: string, proposed_work_date: string, proposed_shift_code?: ?string, client_request_id?: ?string}  $data
     */
    private function createReschedule(WorkOrder $workOrder, FieldActor $actor, array $data): WorkOrderReschedule
    {
        $this->assertWorkable($workOrder, self::WORKABLE);

        $role = $workOrder->team_type;
        if (! ReferenceCode::isValid(ReferenceCode::CATEGORY_RESCHEDULE_REASON, (string) $data['reason_code'], $role)) {
            throw ValidationException::withMessages(['reason_code' => 'Pilih alasan reschedule dari daftar yang tersedia.']);
        }
        $note = trim((string) $data['note']);
        if (mb_strlen($note) < 5) {
            throw ValidationException::withMessages(['note' => 'Catatan reschedule wajib diisi (minimal 5 karakter).']);
        }
        $proposedShift = $workOrder->shift_code !== null ? ($data['proposed_shift_code'] ?? null) : null;
        if ($workOrder->shift_code !== null) {
            if (! in_array($proposedShift, $this->shifts->codes(), true)) {
                throw ValidationException::withMessages(['proposed_shift_code' => 'Pilih shift tujuan.']);
            }
            $window = $this->shifts->window($data['proposed_work_date'], $proposedShift);
            if ($window['end']->lte(CarbonImmutable::now())) {
                throw ValidationException::withMessages(['proposed_work_date' => 'Jadwal tujuan sudah lewat.']);
            }
        } elseif ($data['proposed_work_date'] < $this->shifts->today()) {
            throw ValidationException::withMessages(['proposed_work_date' => 'Tanggal tujuan tidak boleh sebelum hari ini.']);
        }

        return DB::transaction(function () use ($workOrder, $actor, $data, $note, $proposedShift): WorkOrderReschedule {
            $reschedule = WorkOrderReschedule::create([
                'work_order_id' => $workOrder->getKey(),
                'requested_by_field_team_id' => $actor->team?->getKey(),
                'requested_by_user_id' => $actor->user->getKey(),
                'reason_code' => $data['reason_code'],
                'note' => $note,
                'from_work_date' => $workOrder->work_date,
                'from_shift_code' => $workOrder->shift_code,
                'proposed_work_date' => $data['proposed_work_date'],
                'proposed_shift_code' => $proposedShift,
                'status' => WorkOrderReschedule::STATUS_MENUNGGU_ACC,
                'client_request_id' => $data['client_request_id'] ?? null,
            ]);

            $this->lifecycle->transition(
                $workOrder,
                WorkOrderStatus::RescheduleDiajukan,
                $actor->user,
                WorkOrderStatusLog::ACTION_RESCHEDULE_REQUESTED,
                $note,
                [],
                $actor->payload() + [
                    'reschedule_id' => $reschedule->getKey(),
                    'reason_code' => $data['reason_code'],
                    'proposed_work_date' => $data['proposed_work_date'],
                    'proposed_shift_code' => $proposedShift,
                ],
            );

            return $reschedule;
        });
    }

    /**
     * @param  array{target_team_type: string, target_work_type: string, reason_code: string, note: string, client_request_id?: ?string}  $data
     */
    public function requestEscalation(WorkOrder $workOrder, FieldActor $actor, array $data): WorkEscalation
    {
        return IdempotentSubmission::run(
            WorkEscalation::class,
            $data['client_request_id'] ?? null,
            ['source_work_order_id', $workOrder->getKey(), 'WO lain'],
            ['target_team_type' => $data['target_team_type'], 'target_work_type' => $data['target_work_type'], 'reason_code' => $data['reason_code'], 'note' => IdempotentSubmission::text($data['note'])],
            fn () => $this->createEscalation($workOrder, $actor, $data),
        );
    }

    /**
     * @param  array{target_team_type: string, target_work_type: string, reason_code: string, note: string, client_request_id?: ?string}  $data
     */
    private function createEscalation(WorkOrder $workOrder, FieldActor $actor, array $data): WorkEscalation
    {
        $this->assertWorkable($workOrder, self::WORKABLE);

        $targetTeam = $data['target_team_type'];
        if (! in_array($targetTeam, [WorkOrder::TEAM_HARSUS, WorkOrder::TEAM_ROW], true) || $targetTeam === $workOrder->team_type) {
            throw ValidationException::withMessages(['target_team_type' => 'Limpahan hanya ke tim lain (HARSUS atau ROW).']);
        }
        if (! in_array($data['target_work_type'], WorkOrder::WORK_TYPES_BY_TEAM[$targetTeam], true)) {
            throw ValidationException::withMessages(['target_work_type' => 'Jenis pekerjaan tidak sesuai dengan tim tujuan.']);
        }
        if (! ReferenceCode::isValid(ReferenceCode::CATEGORY_ESCALATION_REASON, (string) $data['reason_code'], $workOrder->team_type)) {
            throw ValidationException::withMessages(['reason_code' => 'Pilih alasan limpahan dari daftar yang tersedia.']);
        }
        $note = trim((string) $data['note']);
        if (mb_strlen($note) < 5) {
            throw ValidationException::withMessages(['note' => 'Catatan limpahan wajib diisi (minimal 5 karakter).']);
        }

        return DB::transaction(function () use ($workOrder, $actor, $data, $note, $targetTeam): WorkEscalation {
            WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->getKey());
            if (WorkEscalation::query()->where('source_work_order_id', $workOrder->getKey())
                ->where('status', WorkEscalation::STATUS_MENUNGGU_ACC)->exists()) {
                throw ValidationException::withMessages(['target_team_type' => 'WO ini sudah memiliki permintaan limpahan yang menunggu ACC.']);
            }

            $escalation = WorkEscalation::create([
                'source_work_order_id' => $workOrder->getKey(),
                'target_team_type' => $targetTeam,
                'target_work_type' => $data['target_work_type'],
                'reason_code' => $data['reason_code'],
                'note' => $note,
                'requested_by_field_team_id' => $actor->team?->getKey(),
                'requested_by_user_id' => $actor->user->getKey(),
                'status' => WorkEscalation::STATUS_MENUNGGU_ACC,
                'client_request_id' => $data['client_request_id'] ?? null,
            ]);

            $this->lifecycle->log($workOrder, WorkOrderStatusLog::ACTION_ESCALATION_REQUESTED, null, null, $actor->user, $note, $actor->payload() + [
                'escalation_id' => $escalation->getKey(),
                'target_team_type' => $targetTeam,
                'target_work_type' => $data['target_work_type'],
                'reason_code' => $data['reason_code'],
            ]);

            return $escalation;
        });
    }

    /**
     * @param  array{issue_type: string, description: string, client_request_id?: ?string}  $data
     */
    public function reportIssue(WorkOrder $workOrder, FieldActor $actor, array $data): WorkOrderIssue
    {
        return IdempotentSubmission::run(
            WorkOrderIssue::class,
            $data['client_request_id'] ?? null,
            ['work_order_id', $workOrder->getKey(), 'WO lain'],
            ['issue_type' => $data['issue_type'], 'description' => IdempotentSubmission::text($data['description'])],
            fn () => $this->createIssue($workOrder, $actor, $data),
        );
    }

    /**
     * @param  array{issue_type: string, description: string, client_request_id?: ?string}  $data
     */
    private function createIssue(WorkOrder $workOrder, FieldActor $actor, array $data): WorkOrderIssue
    {
        if (! $workOrder->refresh()->status->isActive()) {
            throw ValidationException::withMessages(['issue_type' => 'Kendala hanya dapat dilaporkan untuk WO yang masih aktif.']);
        }
        if (! array_key_exists($data['issue_type'], WorkOrderIssue::TYPE_LABELS)) {
            throw ValidationException::withMessages(['issue_type' => 'Jenis kendala tidak dikenal.']);
        }
        $description = trim((string) $data['description']);
        if (mb_strlen($description) < 10) {
            throw ValidationException::withMessages(['description' => 'Uraikan kendala (minimal 10 karakter).']);
        }

        return DB::transaction(function () use ($workOrder, $actor, $data, $description): WorkOrderIssue {
            $issue = WorkOrderIssue::create([
                'work_order_id' => $workOrder->getKey(),
                'issue_type' => $data['issue_type'],
                'description' => $description,
                'status' => WorkOrderIssue::STATUS_TERBUKA,
                'reported_by_field_team_id' => $actor->team?->getKey(),
                'reported_by_user_id' => $actor->user->getKey(),
                'client_request_id' => $data['client_request_id'] ?? null,
            ]);

            $this->lifecycle->log($workOrder, WorkOrderStatusLog::ACTION_ISSUE_REPORTED, null, null, $actor->user, $description, $actor->payload() + [
                'issue_id' => $issue->getKey(),
                'issue_type' => $data['issue_type'],
            ]);

            return $issue;
        });
    }

    /**
     * Petugas aktual wajib minimal satu; ID harus petugas aktif tim pelaksana.
     *
     * @param  array<int, string>  $ids
     * @param  array<int, string>  $names
     * @return array<int, array{field_personnel_id: ?string, personnel_name: string}>
     */
    private function personnel(WorkOrder $workOrder, FieldActor $actor, array $ids, array $names): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
        $query = FieldPersonnel::query()->whereIn('id', $ids)->where('is_active', true)
            ->whereHas('team', fn ($team) => $team->where('role', $workOrder->team_type));
        if ($actor->team !== null) {
            $query->where('field_team_id', $actor->team->getKey());
        }
        $found = $query->get();
        if ($found->count() !== count($ids)) {
            throw ValidationException::withMessages(['personnel_ids' => 'Petugas yang dipilih harus petugas aktif tim pelaksana.']);
        }

        $rows = $found->map(fn (FieldPersonnel $person) => ['field_personnel_id' => $person->getKey(), 'personnel_name' => $person->name])->all();
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                // Roster belum lengkap: nama manual tetap dicatat tanpa tautan master.
                $rows[] = ['field_personnel_id' => null, 'personnel_name' => mb_substr($name, 0, 255)];
            }
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['personnel_ids' => 'Pilih minimal satu petugas yang melaksanakan pekerjaan.']);
        }

        return $rows;
    }

    /**
     * Waktu kejadian lapangan: tanpa offset dibaca sebagai WIB (seperti visited_at inspeksi),
     * dengan offset/Z dihormati; disimpan dalam UTC sehingga offset tidak hilang saat disimpan.
     */
    private static function occurredAt(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, ShiftService::TIMEZONE)->utc();
    }

    /**
     * @param  array<int, WorkOrderStatus>  $allowed
     */
    private function assertWorkable(WorkOrder $workOrder, array $allowed): void
    {
        $workOrder->refresh();
        if (! in_array($workOrder->status, $allowed, true)) {
            throw new WorkflowConflictException("WO berstatus {$workOrder->status->label()}; tindakan ini tidak dapat dilakukan.");
        }
    }
}
