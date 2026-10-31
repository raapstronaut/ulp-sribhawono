<?php

namespace App\Services\Pasila;

use App\Models\AuditLog;
use App\Models\FieldPersonnel;
use App\Models\FieldTeam;
use App\Models\Keypoint;
use App\Models\PasilaFinding;
use App\Models\PasilaFindingMedia;
use App\Models\PasilaFindingPersonnel;
use App\Models\PasilaNilReport;
use App\Models\PasilaNilReportPersonnel;
use App\Models\PasilaSession;
use App\Models\Penyulang;
use App\Models\Posko;
use App\Models\ReferenceCode;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Apkuk\ApkukSectionService;
use App\Services\Apkuk\SectionPoskoMap;
use App\Services\Audit\AuditLogger;
use App\Services\ShiftService;
use App\Services\Work\IdempotentSubmission;
use App\Services\Work\LocationTargetResolver;
use App\Services\Work\MediaStore;
use App\Services\Work\WorkflowConflictException;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pencatatan temuan PASILA per titik dan laporan nihil oleh akun tim POSKO (Batch B3.2).
 *
 * - Hanya akun POSKO dari Posko pemilik sesi. Pelapor: akun dari autentikasi + petugas roster aktif
 *   tim itu (tanpa nama ketik bebas).
 * - Satu temuan = satu record dengan Section terverifikasi milik Posko sesi (default Section sesi),
 *   kode temuan aktif di reference_codes PASILA_FINDING, uraian, GPS perangkat, waktu kejadian di
 *   dalam jendela shift sesi, dan minimal satu foto.
 * - Laporan nihil: pernyataan eksplisit tanpa temuan (catatan area, GPS, waktu, petugas). Ditolak
 *   bila sesi sudah memiliki temuan; temuan yang masuk kemudian menggantikannya (riwayat tetap).
 *   Daftar temuan kosong tidak pernah dianggap nihil.
 * - Pengiriman ulang dengan client_request_id sama mengembalikan record yang ada (IdempotentSubmission).
 * - Tidak membaca atau mengubah WO APKUK, kuota, maupun P0.
 */
class PasilaFindingService
{
    public const MAX_PHOTOS = 10;

    /** Toleransi jam perangkat di depan jam server, sama dengan pencatatan inspeksi. */
    private const FUTURE_TOLERANCE_MINUTES = 10;

    private const MIN_NOTE = 10;

    public function __construct(
        private readonly MediaStore $media,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{client_request_id?: ?string, finding_code: string, description: string, section?: ?string, keypoint_id?: mixed, asset_label?: ?string, latitude: mixed, longitude: mixed, gps_accuracy_m?: mixed, occurred_at: string, personnel_ids: array<int, string>}  $data
     * @param  array<int, UploadedFile>  $photos
     */
    public function recordFinding(PasilaSession $session, User $account, array $data, array $photos): PasilaFinding
    {
        $team = $this->teamOf($account, $session);
        $section = IdempotentSubmission::text($data['section'] ?? null);

        return IdempotentSubmission::run(
            PasilaFinding::class,
            $data['client_request_id'] ?? null,
            ['submitted_by_field_team_id', $team->getKey(), 'tim lain'],
            [
                'pasila_session_id' => $session->getKey(),
                'finding_code' => mb_strtoupper(trim($data['finding_code'])),
                'description' => IdempotentSubmission::text($data['description']),
                'section' => fn (PasilaFinding $f) => $section === null || ApkukSectionService::normalize($section) === $f->section_key,
                'keypoint_id' => filled($data['keypoint_id'] ?? null) ? (int) $data['keypoint_id'] : null,
                'asset_label' => IdempotentSubmission::text($data['asset_label'] ?? null),
                'latitude' => IdempotentSubmission::decimal($data['latitude'], 7),
                'longitude' => IdempotentSubmission::decimal($data['longitude'], 7),
                'gps_accuracy_m' => IdempotentSubmission::decimal($data['gps_accuracy_m'] ?? null, 2),
                'occurred_at' => CarbonImmutable::parse($data['occurred_at'], ShiftService::TIMEZONE),
                'personnel' => fn (PasilaFinding $f) => IdempotentSubmission::samePersonnel($f->personnel, $data['personnel_ids'], []),
                'photos' => fn (PasilaFinding $f) => IdempotentSubmission::sameList($f->media->pluck('sha256')->all(), IdempotentSubmission::hashes($photos)),
            ],
            fn () => $this->createFinding($session, $account, $team, $data, $photos),
        );
    }

    /**
     * @param  array{client_request_id?: ?string, note: string, latitude: mixed, longitude: mixed, gps_accuracy_m?: mixed, occurred_at: string, personnel_ids: array<int, string>}  $data
     */
    public function recordNilReport(PasilaSession $session, User $account, array $data): PasilaNilReport
    {
        $team = $this->teamOf($account, $session);

        return IdempotentSubmission::run(
            PasilaNilReport::class,
            $data['client_request_id'] ?? null,
            ['submitted_by_field_team_id', $team->getKey(), 'tim lain'],
            [
                'pasila_session_id' => $session->getKey(),
                'note' => IdempotentSubmission::text($data['note']),
                'latitude' => IdempotentSubmission::decimal($data['latitude'], 7),
                'longitude' => IdempotentSubmission::decimal($data['longitude'], 7),
                'gps_accuracy_m' => IdempotentSubmission::decimal($data['gps_accuracy_m'] ?? null, 2),
                'occurred_at' => CarbonImmutable::parse($data['occurred_at'], ShiftService::TIMEZONE),
                'personnel' => fn (PasilaNilReport $r) => IdempotentSubmission::samePersonnel($r->personnel, $data['personnel_ids'], []),
            ],
            fn () => $this->createNilReport($session, $account, $team, $data),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $photos
     */
    private function createFinding(PasilaSession $session, User $account, FieldTeam $team, array $data, array $photos): PasilaFinding
    {
        $occurredAt = $this->occurredAt($session, (string) $data['occurred_at']);
        $personnel = $this->personnel($team, $data['personnel_ids']);
        $code = $this->findingCode((string) $data['finding_code']);
        $section = $this->section($session, $data['section'] ?? null);
        $keypointId = $this->keypoint($data['keypoint_id'] ?? null, $section);
        $photos = array_values(array_filter($photos, fn ($photo) => $photo instanceof UploadedFile));
        if ($photos === [] || count($photos) > self::MAX_PHOTOS) {
            throw ValidationException::withMessages(['photos' => 'Setiap temuan wajib memiliki 1–'.self::MAX_PHOTOS.' foto bukti.']);
        }

        $stored = [];
        try {
            foreach ($photos as $photo) {
                $stored[] = $this->media->store($photo, 'pasila/'.$occurredAt->setTimezone(ShiftService::TIMEZONE)->format('Ym'));
            }

            return DB::transaction(function () use ($session, $account, $team, $data, $occurredAt, $personnel, $code, $section, $keypointId, $stored): PasilaFinding {
                // Kunci baris sesi: nomor urut temuan dan pemeriksaan laporan nihil diserialkan per sesi.
                $session = PasilaSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

                $finding = PasilaFinding::create([
                    'pasila_session_id' => $session->getKey(),
                    'sequence' => (int) PasilaFinding::query()->where('pasila_session_id', $session->getKey())->max('sequence') + 1,
                    'posko_id' => $session->posko_id,
                    'penyulang_id' => $section['penyulang_model']->getKey(),
                    'section_key' => $section['key'],
                    'section_name' => $section['section'],
                    'finding_code' => $code,
                    'description' => trim((string) $data['description']),
                    'keypoint_id' => $keypointId,
                    'asset_label' => IdempotentSubmission::text($data['asset_label'] ?? null),
                    'latitude' => $data['latitude'],
                    'longitude' => $data['longitude'],
                    'gps_accuracy_m' => $data['gps_accuracy_m'] ?? null,
                    'coordinate_source' => PasilaFinding::COORDINATE_DEVICE,
                    'occurred_at' => $occurredAt->utc(),
                    'received_at' => now(),
                    'status' => PasilaFinding::STATUS_TERCATAT,
                    'source' => PasilaFinding::SOURCE_MOBILE,
                    'submitted_by_field_team_id' => $team->getKey(),
                    'submitted_by_user_id' => $account->getKey(),
                    'client_request_id' => $data['client_request_id'] ?? null,
                ]);
                foreach ($personnel as $person) {
                    PasilaFindingPersonnel::create(['pasila_finding_id' => $finding->getKey()] + $person);
                }
                foreach ($stored as $file) {
                    PasilaFindingMedia::create($file + [
                        'pasila_finding_id' => $finding->getKey(),
                        'capture_source' => 'CAMERA',
                        'captured_at' => $occurredAt->utc(),
                        'latitude' => $data['latitude'],
                        'longitude' => $data['longitude'],
                        'uploaded_at' => now(),
                    ]);
                }

                $this->audit->record('pasila_finding', $finding->getKey(), $session->code.' #'.$finding->sequence, AuditLog::ACTION_CREATED, null, [
                    'pasila_session_id' => $session->getKey(),
                    'section' => $finding->section_name,
                    'finding_code' => $code,
                    'occurred_at' => $finding->occurred_at,
                    'photo_count' => count($stored),
                ], $account, null, $team);

                // Bukti lapangan menang atas pernyataan nihil yang lebih dulu tercatat.
                $nil = PasilaNilReport::query()->where('pasila_session_id', $session->getKey())->where('status', PasilaNilReport::STATUS_AKTIF)->first();
                if ($nil !== null) {
                    $nil->forceFill(['status' => PasilaNilReport::STATUS_DIGANTIKAN, 'superseded_by_finding_id' => $finding->getKey(), 'superseded_at' => now()])->save();
                    $this->audit->record('pasila_nil_report', $nil->getKey(), $session->code, AuditLog::ACTION_UPDATED,
                        ['status' => PasilaNilReport::STATUS_AKTIF], ['status' => PasilaNilReport::STATUS_DIGANTIKAN, 'superseded_by_finding_id' => $finding->getKey()],
                        $account, 'Temuan #'.$finding->sequence.' tercatat setelah laporan nihil.', $team);
                }

                return $finding;
            });
        } catch (Throwable $e) {
            $this->media->discard($stored);
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createNilReport(PasilaSession $session, User $account, FieldTeam $team, array $data): PasilaNilReport
    {
        $occurredAt = $this->occurredAt($session, (string) $data['occurred_at']);
        $personnel = $this->personnel($team, $data['personnel_ids']);
        $note = trim((string) $data['note']);
        if (mb_strlen($note) < self::MIN_NOTE) {
            throw ValidationException::withMessages(['note' => 'Laporan nihil wajib menyebut area/rute yang dipatroli (minimal '.self::MIN_NOTE.' karakter).']);
        }

        return DB::transaction(function () use ($session, $account, $team, $data, $occurredAt, $personnel, $note): PasilaNilReport {
            $session = PasilaSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
            $findings = PasilaFinding::query()->where('pasila_session_id', $session->getKey())->count();
            if ($findings > 0) {
                throw new WorkflowConflictException("Sesi {$session->code} sudah memiliki {$findings} temuan; laporan nihil tidak dapat dibuat.");
            }
            if (PasilaNilReport::query()->where('pasila_session_id', $session->getKey())->exists()) {
                throw new WorkflowConflictException("Sesi {$session->code} sudah memiliki laporan nihil.");
            }

            $report = PasilaNilReport::create([
                'pasila_session_id' => $session->getKey(),
                'note' => $note,
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'gps_accuracy_m' => $data['gps_accuracy_m'] ?? null,
                'occurred_at' => $occurredAt->utc(),
                'received_at' => now(),
                'status' => PasilaNilReport::STATUS_AKTIF,
                'source' => PasilaFinding::SOURCE_MOBILE,
                'submitted_by_field_team_id' => $team->getKey(),
                'submitted_by_user_id' => $account->getKey(),
                'client_request_id' => $data['client_request_id'] ?? null,
            ]);
            foreach ($personnel as $person) {
                PasilaNilReportPersonnel::create(['pasila_nil_report_id' => $report->getKey()] + $person);
            }
            $this->audit->record('pasila_nil_report', $report->getKey(), $session->code, AuditLog::ACTION_CREATED, null, [
                'pasila_session_id' => $session->getKey(),
                'occurred_at' => $report->occurred_at,
                'personnel_count' => count($personnel),
            ], $account, null, $team);

            return $report;
        });
    }

    private function teamOf(User $account, PasilaSession $session): FieldTeam
    {
        $team = $account->fieldTeam;
        if ($team === null || $team->role !== WorkOrder::TEAM_POSKO || $team->posko_id !== $session->posko_id) {
            throw ValidationException::withMessages(['pasila_session' => 'Hanya tim POSKO pemilik sesi PASILA ini yang dapat melapor.']);
        }

        return $team;
    }

    /**
     * Waktu kejadian wajib di dalam jendela shift sesi [mulai, selesai) dan tidak di masa depan.
     * Kiriman offline yang tiba setelah shift berakhir tetap diterima bila waktunya di dalam jendela.
     */
    private function occurredAt(PasilaSession $session, string $value): CarbonImmutable
    {
        $at = CarbonImmutable::parse($value, ShiftService::TIMEZONE);
        $shift = $session->shiftInstance;
        if ($at->gt(CarbonImmutable::now()->addMinutes(self::FUTURE_TOLERANCE_MINUTES))) {
            throw ValidationException::withMessages(['occurred_at' => 'Waktu kejadian tidak boleh di masa depan.']);
        }
        if ($at->lt($shift->starts_at) || $at->gte($shift->ends_at)) {
            $fmt = fn ($t) => CarbonImmutable::instance($t)->setTimezone(ShiftService::TIMEZONE)->format('d/m/Y H:i');
            throw ValidationException::withMessages(['occurred_at' => "Waktu kejadian harus di dalam Shift {$shift->shift_code} sesi ini ({$fmt($shift->starts_at)}–{$fmt($shift->ends_at)} WIB)."]);
        }

        return $at;
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, array{field_personnel_id: string, personnel_name: string}>
     */
    private function personnel(FieldTeam $team, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
        if ($ids === []) {
            throw ValidationException::withMessages(['personnel_ids' => 'Pilih minimal satu petugas yang benar-benar melapor.']);
        }
        $found = FieldPersonnel::query()->whereIn('id', $ids)->where('field_team_id', $team->getKey())->where('is_active', true)->get();
        if ($found->count() !== count($ids)) {
            throw ValidationException::withMessages(['personnel_ids' => 'Petugas harus anggota aktif tim POSKO ini.']);
        }

        return $found->map(fn (FieldPersonnel $p) => ['field_personnel_id' => $p->getKey(), 'personnel_name' => $p->name])->all();
    }

    private function findingCode(string $code): string
    {
        $code = mb_strtoupper(trim($code));
        if (ReferenceCode::isValid(ReferenceCode::CATEGORY_PASILA_FINDING, $code, WorkOrder::TEAM_POSKO)) {
            return $code;
        }

        throw ValidationException::withMessages(['finding_code' => ReferenceCode::query()->activeFor(ReferenceCode::CATEGORY_PASILA_FINDING, WorkOrder::TEAM_POSKO)->exists()
            ? 'Kode temuan tidak aktif atau tidak dikenal untuk POSKO.'
            : 'Kode temuan PASILA belum tersedia; Admin perlu mengisi Master Data kategori PASILA_FINDING.']);
    }

    /**
     * Section temuan: Section yang dipilih petugas atau Section sesi, divalidasi ulang terhadap
     * referensi terkini. Harus terverifikasi dan milik Posko sesi.
     *
     * @return array{key: string, section: string, penyulang: string, penyulang_model: Penyulang, posko: Posko, reason: null, posko_pic: array<int, string>}
     */
    private function section(PasilaSession $session, ?string $requested): array
    {
        $value = filled($requested) ? trim((string) $requested) : $session->section_name;
        if ($value === null) {
            throw ValidationException::withMessages(['section' => 'Sesi belum memiliki Section terverifikasi. Pilih Section milik Posko ini; bila Posko belum memiliki Section, referensi SECTION_PIC_RAW perlu dilengkapi.']);
        }

        $entry = SectionPoskoMap::load()->entry($value);
        if ($entry === null) {
            throw ValidationException::withMessages(['section' => "Section \"{$value}\" tidak ditemukan pada referensi SECTION_PIC_RAW."]);
        }
        if ($entry['posko'] === null) {
            throw ValidationException::withMessages(['section' => 'Section '.$entry['section'].' ditahan: '.(SectionPoskoMap::LABELS[$entry['reason']] ?? $entry['reason']).'.']);
        }
        if ($entry['posko']->getKey() !== $session->posko_id) {
            throw ValidationException::withMessages(['section' => 'Section '.$entry['section'].' ('.$entry['penyulang'].') milik Posko '.$entry['posko']->nama_posko.', bukan Posko sesi ini.']);
        }

        return $entry;
    }

    /**
     * Keypoint opsional; bila diisi harus ada dan berada pada penyulang Section temuan.
     *
     * @param  array{penyulang_model: Penyulang}  $section
     */
    private function keypoint(mixed $keypointId, array $section): ?int
    {
        if (! filled($keypointId)) {
            return null;
        }
        $keypoint = Keypoint::query()->find((int) $keypointId);
        if ($keypoint === null) {
            throw ValidationException::withMessages(['keypoint_id' => 'Keypoint tidak ditemukan.']);
        }
        if (! LocationTargetResolver::penyulangMatches($keypoint->penyulang, $section['penyulang_model'])) {
            throw ValidationException::withMessages(['keypoint_id' => 'Keypoint berada di penyulang lain dari Section temuan.']);
        }

        return $keypoint->getKey();
    }
}
