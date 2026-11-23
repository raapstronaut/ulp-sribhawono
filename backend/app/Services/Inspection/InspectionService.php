<?php

namespace App\Services\Inspection;

use App\Models\AuditLog;
use App\Models\FieldPersonnel;
use App\Models\FieldTeam;
use App\Models\Inspection;
use App\Models\InspectionFinding;
use App\Models\InspectionFindingMedia;
use App\Models\InspectionPersonnel;
use App\Models\Keypoint;
use App\Models\Penyulang;
use App\Models\ReferenceCode;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Audit\AuditLogger;
use App\Services\ShiftService;
use App\Services\Work\IdempotentSubmission;
use App\Services\Work\LocationTargetResolver;
use App\Services\Work\MediaStore;
use App\Services\Work\TeamWorkOrderCreator;
use App\Services\Work\WorkflowConflictException;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pencatatan kunjungan INSPEKSI dan validasi temuan oleh Admin.
 *
 * - "Tidak Ada Temuan" sah dicatat dan tidak dihitung sebagai temuan.
 * - Satu temuan satu lokasi (aset keypoint atau koordinat) dan foto sendiri.
 * - Temuan baru menjadi dasar tindakan setelah ACC Admin; WO tindak lanjut opsional.
 * - Rekomendasi tim penerima otomatis belum ada (algoritma TBD): saran diisi petugas/Admin.
 * - Satu form INSPEKSI; target APKUK dicatat pada temuan dengan saran tim POSKO.
 */
class InspectionService
{
    public const TEAM_ROLE = 'INSPEKSI';

    public const FOLLOW_UP_TEAMS = [WorkOrder::TEAM_HARSUS, WorkOrder::TEAM_ROW];

    public function __construct(
        private readonly MediaStore $media,
        private readonly AuditLogger $audit,
        private readonly TeamWorkOrderCreator $creator,
        private readonly LocationTargetResolver $targets,
    ) {}

    /**
     * @param  array{field_team_id: string, penyulang_id: string, section_name?: ?string, visited_at: string, summary?: ?string, latitude?: mixed, longitude?: mixed, gps_accuracy_m?: mixed, personnel_ids?: array<int, string>, personnel_names?: array<int, string>, client_request_id?: ?string, findings?: array<int, array{finding_code: string, description: string, keypoint_id?: mixed, asset_label?: ?string, latitude?: mixed, longitude?: mixed, suggested_team_type?: ?string, photos?: array<int, UploadedFile>}>}  $data
     */
    public function record(array $data, User $actor, ?FieldTeam $submittingTeam, string $source, bool $requirePhotos): Inspection
    {
        // Pengiriman ulang hanya mengembalikan kunjungan milik tim yang sama dengan isi yang sama.
        $findings = array_values($data['findings'] ?? []);

        return IdempotentSubmission::run(
            Inspection::class,
            $data['client_request_id'] ?? null,
            ['field_team_id', (string) $data['field_team_id'], 'tim lain'],
            [
                'penyulang_id' => $data['penyulang_id'],
                'has_finding' => $findings !== [],
                'section_name' => IdempotentSubmission::text($data['section_name'] ?? null),
                'summary' => IdempotentSubmission::text($data['summary'] ?? null),
                'visited_at' => CarbonImmutable::parse($data['visited_at'], ShiftService::TIMEZONE),
                'latitude' => IdempotentSubmission::decimal($data['latitude'] ?? null, 7),
                'longitude' => IdempotentSubmission::decimal($data['longitude'] ?? null, 7),
                'gps_accuracy_m' => IdempotentSubmission::decimal($data['gps_accuracy_m'] ?? null, 2),
                'personnel' => fn (Inspection $inspection) => IdempotentSubmission::samePersonnel($inspection->personnel, $data['personnel_ids'] ?? [], $data['personnel_names'] ?? []),
                'findings' => fn (Inspection $inspection) => IdempotentSubmission::sameList(
                    $inspection->findings()->with('media')->get()
                        ->map(fn (InspectionFinding $finding) => self::findingSignature($finding->finding_code, $finding->description, $finding->keypoint_id, $finding->media->pluck('sha256')->all(), $finding->apkuk_no_tiang, $finding->apkuk_target_quantity, $finding->suggested_team_type))
                        ->all(),
                    array_map(fn (array $finding) => self::findingSignature($finding['finding_code'] ?? '', $finding['description'] ?? '', $finding['keypoint_id'] ?? null, IdempotentSubmission::hashes($finding['photos'] ?? []), $finding['apkuk_no_tiang'] ?? null, $finding['apkuk_target_quantity'] ?? null, $finding['suggested_team_type'] ?? null), $findings),
                ),
            ],
            fn () => $this->create($data, $actor, $submittingTeam, $source, $requirePhotos),
        );
    }

    /**
     * Identitas isi satu temuan untuk pembanding pengiriman ulang (urutan foto diabaikan).
     *
     * @param  array<int, string>  $hashes
     */
    private static function findingSignature(mixed $code, mixed $description, mixed $keypointId, array $hashes, mixed $pole = null, mixed $quantity = null, mixed $suggestedTeam = null): string
    {
        sort($hashes);

        return json_encode([trim((string) $code), trim((string) $description), filled($keypointId) ? (int) $keypointId : null, $hashes, mb_strtoupper(trim((string) $pole)), filled($quantity) ? (int) $quantity : null, filled($suggestedTeam) ? (string) $suggestedTeam : null]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function create(array $data, User $actor, ?FieldTeam $submittingTeam, string $source, bool $requirePhotos): Inspection
    {
        $team = FieldTeam::query()->whereKey($data['field_team_id'])->where('is_active', true)->first();
        if ($team === null || $team->role !== self::TEAM_ROLE) {
            throw ValidationException::withMessages(['field_team_id' => 'Pilih tim INSPEKSI yang aktif.']);
        }
        if ($submittingTeam !== null && $submittingTeam->getKey() !== $team->getKey()) {
            throw ValidationException::withMessages(['field_team_id' => 'Akun hanya dapat mencatat inspeksi timnya sendiri.']);
        }
        $penyulang = Penyulang::query()->whereKey($data['penyulang_id'])->where('is_active', true)->first()
            ?? throw ValidationException::withMessages(['penyulang_id' => 'Penyulang tidak ditemukan atau tidak aktif.']);

        $visitedAt = CarbonImmutable::parse($data['visited_at'], ShiftService::TIMEZONE);
        if ($visitedAt->gt(CarbonImmutable::now()->addMinutes(10))) {
            throw ValidationException::withMessages(['visited_at' => 'Waktu kunjungan tidak boleh di masa depan.']);
        }

        $personnel = $this->personnel($team, $data['personnel_ids'] ?? [], $data['personnel_names'] ?? []);
        $findings = array_values($data['findings'] ?? []);
        $summary = filled($data['summary'] ?? null) ? trim($data['summary']) : null;
        if ($findings === [] && $summary === null) {
            throw ValidationException::withMessages(['summary' => 'Kunjungan tanpa temuan wajib diberi catatan singkat (mis. area yang diperiksa).']);
        }
        $prepared = array_map(fn (array $finding, int $index) => $this->prepareFinding($finding, $index, $penyulang, $requirePhotos), $findings, array_keys($findings));

        $stored = [];
        try {
            foreach ($prepared as $index => $finding) {
                foreach ($finding['photos'] as $photo) {
                    $stored[$index][] = $this->media->store($photo, 'inspections/'.$visitedAt->format('Ym'));
                }
            }

            return DB::transaction(function () use ($data, $actor, $submittingTeam, $source, $team, $penyulang, $visitedAt, $personnel, $prepared, $stored, $summary): Inspection {
                $inspection = Inspection::create([
                    'code' => $this->nextCode($visitedAt),
                    'field_team_id' => $team->getKey(),
                    'penyulang_id' => $penyulang->getKey(),
                    'section_name' => filled($data['section_name'] ?? null) ? trim($data['section_name']) : null,
                    'visited_at' => $visitedAt->utc(),
                    'has_finding' => $prepared !== [],
                    'summary' => $summary,
                    'latitude' => $data['latitude'] ?? null,
                    'longitude' => $data['longitude'] ?? null,
                    'gps_accuracy_m' => $data['gps_accuracy_m'] ?? null,
                    'source' => $source,
                    'inspection_type' => Inspection::TYPE_REGULAR, // hidden legacy column; no type distinction
                    'submitted_by_field_team_id' => $submittingTeam?->getKey(),
                    'submitted_by_user_id' => $actor->getKey(),
                    'client_request_id' => $data['client_request_id'] ?? null,
                ]);

                foreach ($personnel as $person) {
                    InspectionPersonnel::create(['inspection_id' => $inspection->getKey()] + $person);
                }

                foreach ($prepared as $index => $finding) {
                    $row = InspectionFinding::create([
                        'inspection_id' => $inspection->getKey(),
                        'finding_code' => $finding['finding_code'],
                        'description' => $finding['description'],
                        'keypoint_id' => $finding['keypoint_id'],
                        'asset_label' => $finding['asset_label'],
                        'latitude' => $finding['latitude'],
                        'longitude' => $finding['longitude'],
                        'occurred_at' => $visitedAt->utc(),
                        'suggested_team_type' => $finding['suggested_team_type'],
                        'apkuk_no_tiang' => $finding['apkuk_no_tiang'],
                        'apkuk_target_quantity' => $finding['apkuk_target_quantity'],
                        'validation_status' => InspectionFinding::VALIDATION_MENUNGGU,
                    ]);
                    foreach ($stored[$index] ?? [] as $file) {
                        InspectionFindingMedia::create($file + [
                            'inspection_finding_id' => $row->getKey(),
                            'capture_source' => $source === Inspection::SOURCE_ADMIN ? 'ADMIN_UPLOAD' : 'CAMERA',
                            'captured_at' => $visitedAt->utc(),
                            'latitude' => $finding['latitude'],
                            'longitude' => $finding['longitude'],
                            'uploaded_at' => now(),
                        ]);
                    }
                }

                $this->audit->record('inspection', $inspection->getKey(), $inspection->code, AuditLog::ACTION_CREATED, null, [
                    'field_team_id' => $team->getKey(),
                    'penyulang_id' => $penyulang->getKey(),
                    'visited_at' => $inspection->visited_at,
                    'has_finding' => $inspection->has_finding,
                    'finding_count' => count($prepared),
                    'source' => $source,
                ], $actor, null, $submittingTeam);

                return $inspection;
            });
        } catch (Throwable $e) {
            $this->media->discard(array_merge([], ...array_values($stored)));
            throw $e;
        }
    }

    /**
     * @param  array{team_type?: ?string, work_type?: ?string, field_team_id?: ?string, work_date?: ?string, due_date?: ?string, notes?: ?string}|null  $followUp
     */
    public function approveFinding(InspectionFinding $finding, User $admin, ?string $note, ?array $followUp): InspectionFinding
    {
        return DB::transaction(function () use ($finding, $admin, $note, $followUp): InspectionFinding {
            $finding = $this->pending($finding);
            $workOrder = null;

            if ($followUp !== null && filled($followUp['team_type'] ?? null)) {
                $teamType = $followUp['team_type'];
                if (! in_array($teamType, self::FOLLOW_UP_TEAMS, true)) {
                    throw ValidationException::withMessages(['follow_up_team_type' => 'WO tindak lanjut hanya untuk tim HARSUS atau ROW.']);
                }
                if ($teamType === WorkOrder::TEAM_HARSUS && $finding->keypoint_id === null) {
                    throw ValidationException::withMessages(['follow_up_team_type' => 'WO HARSUS wajib menunjuk aset jaringan; temuan ini tidak terkait keypoint.']);
                }
                if (filled($finding->suggested_team_type) && $finding->suggested_team_type !== $teamType) {
                    throw ValidationException::withMessages(['follow_up_team_type' => 'Tim WO harus sesuai tim tujuan yang dipilih saat INSPEKSI.']);
                }
                $penyulang = $finding->inspection->penyulang;
                $workOrder = $this->creator->create($admin, [
                    'team_type' => $teamType,
                    'work_type' => (string) ($followUp['work_type'] ?? ''),
                    'penyulang_id' => $penyulang->getKey(),
                    'field_team_id' => $followUp['field_team_id'] ?? null,
                    'section_name' => $finding->inspection->section_name,
                    'work_date' => (string) ($followUp['work_date'] ?? ''),
                    'due_date' => (string) ($followUp['due_date'] ?? ''),
                    'notes' => $followUp['notes'] ?? $finding->description,
                ], $penyulang, $this->targets->fromFinding($finding, $penyulang), WorkOrder::SOURCE_ADMIN_MANUAL, null, [
                    'inspection_id' => $finding->inspection_id,
                    'inspection_finding_id' => $finding->getKey(),
                ]);
            }

            $finding->forceFill([
                'validation_status' => InspectionFinding::VALIDATION_DISETUJUI,
                'validated_by_user_id' => $admin->getKey(),
                'validated_at' => now(),
                'validation_note' => filled($note) ? trim($note) : null,
                'follow_up_work_order_id' => $workOrder?->getKey(),
            ])->save();

            $this->audit->record('inspection_finding', $finding->getKey(), $finding->inspection->code.' · '.$finding->finding_code, AuditLog::ACTION_APPROVED,
                ['validation_status' => InspectionFinding::VALIDATION_MENUNGGU],
                ['validation_status' => InspectionFinding::VALIDATION_DISETUJUI, 'follow_up_work_order_id' => $workOrder?->getKey()],
                $admin, $note);

            return $finding;
        });
    }

    public function rejectFinding(InspectionFinding $finding, User $admin, string $note): InspectionFinding
    {
        $note = trim($note);
        if (mb_strlen($note) < 5) {
            throw ValidationException::withMessages(['review_note' => 'Alasan penolakan wajib diisi (minimal 5 karakter).']);
        }

        return DB::transaction(function () use ($finding, $admin, $note): InspectionFinding {
            $finding = $this->pending($finding);
            $finding->forceFill([
                'validation_status' => InspectionFinding::VALIDATION_DITOLAK,
                'validated_by_user_id' => $admin->getKey(),
                'validated_at' => now(),
                'validation_note' => $note,
            ])->save();

            $this->audit->record('inspection_finding', $finding->getKey(), $finding->inspection->code.' · '.$finding->finding_code, AuditLog::ACTION_REJECTED,
                ['validation_status' => InspectionFinding::VALIDATION_MENUNGGU],
                ['validation_status' => InspectionFinding::VALIDATION_DITOLAK],
                $admin, $note);

            return $finding;
        });
    }

    private function pending(InspectionFinding $finding): InspectionFinding
    {
        $finding = InspectionFinding::query()->lockForUpdate()->findOrFail($finding->getKey());
        if ($finding->validation_status !== InspectionFinding::VALIDATION_MENUNGGU) {
            throw new WorkflowConflictException('Temuan ini sudah divalidasi sebelumnya.');
        }

        return $finding;
    }

    /**
     * @param  array<string, mixed>  $finding
     * @return array{finding_code: string, description: string, keypoint_id: ?int, asset_label: ?string, latitude: ?string, longitude: ?string, suggested_team_type: ?string, photos: array<int, UploadedFile>}
     */
    private function prepareFinding(array $finding, int $index, Penyulang $penyulang, bool $requirePhotos): array
    {
        $key = "findings.{$index}";
        $code = (string) ($finding['finding_code'] ?? '');
        if (! ReferenceCode::isValid(ReferenceCode::CATEGORY_INSPECTION_FINDING, $code, self::TEAM_ROLE)) {
            throw ValidationException::withMessages(["{$key}.finding_code" => 'Pilih jenis temuan dari daftar yang tersedia.']);
        }
        $description = trim((string) ($finding['description'] ?? ''));
        if (mb_strlen($description) < 5) {
            throw ValidationException::withMessages(["{$key}.description" => 'Uraikan temuan (minimal 5 karakter).']);
        }

        $keypoint = null;
        if (filled($finding['keypoint_id'] ?? null)) {
            $keypoint = Keypoint::query()->find((int) $finding['keypoint_id']);
            if ($keypoint === null) {
                throw ValidationException::withMessages(["{$key}.keypoint_id" => 'Aset (keypoint) tidak ditemukan.']);
            }
            if (! LocationTargetResolver::penyulangMatches($keypoint->penyulang, $penyulang)) {
                throw ValidationException::withMessages(["{$key}.keypoint_id" => "Aset berada di penyulang '{$keypoint->penyulang}', bukan {$penyulang->nama_penyulang}."]);
            }
        }

        [$latitude, $longitude] = LocationTargetResolver::coordinates(
            isset($finding['latitude']) ? (string) $finding['latitude'] : null,
            isset($finding['longitude']) ? (string) $finding['longitude'] : null,
        );
        if ($latitude === null && $keypoint?->hasCoordinates()) {
            [$latitude, $longitude] = [(string) $keypoint->latitude, (string) $keypoint->longitude];
        }
        if ($keypoint === null && $latitude === null) {
            throw ValidationException::withMessages(["{$key}.latitude" => 'Setiap temuan wajib memiliki aset atau koordinat lokasi.']);
        }

        $suggested = filled($finding['suggested_team_type'] ?? null) ? (string) $finding['suggested_team_type'] : null;
        if ($suggested !== null && ! in_array($suggested, [WorkOrder::TEAM_POSKO, WorkOrder::TEAM_HARSUS, WorkOrder::TEAM_ROW], true)) {
            throw ValidationException::withMessages(["{$key}.suggested_team_type" => 'Saran tim tidak dikenal.']);
        }

        // Tidak memakai `kurang_bh` dari SPS. Kebutuhan APKUK berasal dari temuan INSPEKSI.
        $apkukPole = mb_strtoupper(trim((string) ($finding['apkuk_no_tiang'] ?? '')));
        $apkukQuantity = $finding['apkuk_target_quantity'] ?? null;
        $hasApkukTarget = $apkukPole !== '' || filled($apkukQuantity);
        if ($hasApkukTarget) {
            if ($suggested !== WorkOrder::TEAM_POSKO) {
                throw ValidationException::withMessages(["{$key}.suggested_team_type" => 'Temuan kebutuhan APKUK harus menyarankan tim POSKO.']);
            }
            if (in_array($apkukPole, ['', '-', 'DUMMY'], true) || mb_strlen($apkukPole) > 191) {
                throw ValidationException::withMessages(["{$key}.apkuk_no_tiang" => 'Nomor tiang APKUK wajib diisi dengan identitas yang sah.']);
            }
            if (filter_var($apkukQuantity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]) === false) {
                throw ValidationException::withMessages(["{$key}.apkuk_target_quantity" => 'Jumlah kebutuhan APKUK harus 1–1000.']);
            }
            $apkukQuantity = (int) $apkukQuantity;
        }

        $photos = array_values(array_filter($finding['photos'] ?? [], fn ($file) => $file instanceof UploadedFile));
        if ($requirePhotos && $photos === []) {
            throw ValidationException::withMessages(["{$key}.photos" => 'Setiap temuan wajib menyertakan minimal satu foto.']);
        }

        return [
            'finding_code' => $code,
            'description' => $description,
            'keypoint_id' => $keypoint?->id,
            'asset_label' => $keypoint ? trim(($keypoint->jenis ? $keypoint->jenis.' ' : '').($keypoint->nama ?? '')) : (filled($finding['asset_label'] ?? null) ? trim($finding['asset_label']) : null),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'suggested_team_type' => $suggested,
            'apkuk_no_tiang' => $hasApkukTarget ? $apkukPole : null,
            'apkuk_target_quantity' => $hasApkukTarget ? $apkukQuantity : null,
            'photos' => $photos,
        ];
    }

    /**
     * @param  array<int, string>  $ids
     * @param  array<int, string>  $names
     * @return array<int, array{field_personnel_id: ?string, personnel_name: string}>
     */
    private function personnel(FieldTeam $team, array $ids, array $names): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
        $found = FieldPersonnel::query()->whereIn('id', $ids)->where('field_team_id', $team->getKey())->where('is_active', true)->get();
        if ($found->count() !== count($ids)) {
            throw ValidationException::withMessages(['personnel_ids' => 'Petugas harus anggota aktif tim INSPEKSI yang dipilih.']);
        }

        $rows = $found->map(fn (FieldPersonnel $p) => ['field_personnel_id' => $p->getKey(), 'personnel_name' => $p->name])->all();
        foreach ($names as $name) {
            if (trim((string) $name) !== '') {
                $rows[] = ['field_personnel_id' => null, 'personnel_name' => mb_substr(trim((string) $name), 0, 255)];
            }
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['personnel_ids' => 'Pilih minimal satu petugas yang melakukan inspeksi.']);
        }

        return $rows;
    }

    /**
     * Format: INS-YYYYMMDD-NNNN (tanggal kunjungan WIB).
     */
    private function nextCode(CarbonImmutable $visitedAt): string
    {
        $prefix = 'INS-'.$visitedAt->setTimezone(ShiftService::TIMEZONE)->format('Ymd').'-';
        // Bentrok nomor pada request bersamaan ditolak unique index `code` (tidak menimpa data).
        $last = Inspection::query()->where('code', 'like', $prefix.'%')->max('code');
        $next = $last === null ? 1 : ((int) substr($last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
