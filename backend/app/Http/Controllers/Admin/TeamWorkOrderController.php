<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FieldTeam;
use App\Models\Penyulang;
use App\Models\Posko;
use App\Models\WorkOrder;
use App\Models\WorkOrderLocationTarget;
use App\Services\ShiftService;
use App\Services\Work\LocationTargetResolver;
use App\Services\Work\TeamWorkOrderCreator;
use App\Support\PenyulangName;
use App\Support\QueryInput;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Pembuatan WO manual HARSUS (aset jaringan dari keypoint) dan ROW (titik vegetasi).
 */
class TeamWorkOrderController extends Controller
{
    private const PER_PAGE = 15;

    public function create(Request $request, string $team): View
    {
        $teamType = $this->teamType($team);
        $filters = [
            'penyulang_id' => QueryInput::uuid($request, 'penyulang_id'),
            'q' => QueryInput::text($request, 'q'),
            'target_id' => ctype_digit((string) $request->query('target_id')) ? (int) $request->query('target_id') : null,
        ];
        $penyulang = $filters['penyulang_id'] !== '' ? Penyulang::query()->where('is_active', true)->find($filters['penyulang_id']) : null;

        $candidates = null;
        $selected = null;
        $activeKeys = [];
        if ($penyulang !== null) {
            $query = $teamType === WorkOrder::TEAM_HARSUS ? $this->keypointQuery($penyulang, $filters['q']) : $this->vegetationQuery($penyulang, $filters['q']);
            $candidates = $query->paginate(self::PER_PAGE)->withQueryString();
            $activeKeys = WorkOrderLocationTarget::query()
                ->whereNotNull('active_target_key')
                ->whereIn('target_key', $candidates->getCollection()->map(fn ($row) => self::targetKeyOf($teamType, $row))->filter()->all())
                ->with('workOrder:id,code')
                ->get()
                ->mapWithKeys(fn ($t) => [$t->target_key => $t->workOrder?->code])
                ->all();
            if ($filters['target_id'] !== null) {
                $selected = ($teamType === WorkOrder::TEAM_HARSUS ? $this->keypointQuery($penyulang, '') : $this->vegetationQuery($penyulang, ''))
                    ->where('id', $filters['target_id'])->first();
            }
        }

        return view('admin.team-work-orders.create', [
            'teamType' => $teamType,
            'routeTeam' => $team,
            'filters' => $filters,
            'penyulang' => $penyulang,
            'candidates' => $candidates,
            'selected' => $selected,
            'activeKeys' => $activeKeys,
            'penyulangOptions' => Penyulang::query()->where('is_active', true)->orderBy('kode_penyulang')->pluck('nama_penyulang', 'id')->all(),
            'poskoOptions' => Posko::query()->where('is_active', true)->orderBy('nama_posko')->pluck('nama_posko', 'id')->all(),
            'teamOptions' => FieldTeam::query()->where('role', $teamType)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            'workTypeOptions' => collect(WorkOrder::WORK_TYPES_BY_TEAM[$teamType])->mapWithKeys(fn ($t) => [$t => WorkOrder::WORK_TYPE_LABELS[$t]])->all(),
            'today' => now(ShiftService::TIMEZONE)->toDateString(),
        ]);
    }

    public function store(Request $request, string $team, TeamWorkOrderCreator $creator): RedirectResponse
    {
        $teamType = $this->teamType($team);
        $data = $request->validate([
            'work_type' => ['required', Rule::in(WorkOrder::WORK_TYPES_BY_TEAM[$teamType])],
            'penyulang_id' => ['required', 'uuid'],
            'target_id' => ['required', 'integer', 'min:1'],
            // ROW: ID POHON yang dilihat Admin; menolak bila baris staging berganti isi setelah import ulang.
            'target_ref' => ['nullable', 'string', 'max:191'],
            'posko_id' => ['nullable', 'uuid'],
            'field_team_id' => ['nullable', 'uuid'],
            'section_name' => ['nullable', 'string', 'max:255'],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:work_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $input = $data + ['team_type' => $teamType];
        $input[$teamType === WorkOrder::TEAM_HARSUS ? 'keypoint_id' : 'vegetation_id'] = (int) $data['target_id'];
        if ($teamType === WorkOrder::TEAM_ROW) {
            $input['vegetation_id_pohon'] = $data['target_ref'] ?? null;
        }
        $workOrder = $creator->createManual($request->user(), $input);

        return redirect()->route('admin.work-orders.show', $workOrder)->with('flash', "WO {$workOrder->teamLabel()} {$workOrder->code} berhasil dibuat.");
    }

    private function teamType(string $team): string
    {
        return match ($team) {
            'harsus' => WorkOrder::TEAM_HARSUS,
            'row' => WorkOrder::TEAM_ROW,
            default => abort(404),
        };
    }

    /**
     * @return array<int, string>
     */
    private function penyulangNames(Penyulang $penyulang): array
    {
        return PenyulangName::sourceNames($penyulang);
    }

    /**
     * Kunci target yang dipakai guard WO aktif (lihat LocationTargetResolver).
     */
    public static function targetKeyOf(string $teamType, object $row): ?string
    {
        return $teamType === WorkOrder::TEAM_HARSUS
            ? 'KEYPOINT:'.$row->id
            : LocationTargetResolver::vegetationTargetKey($row->id_pohon_raw ?? null);
    }

    private function keypointQuery(Penyulang $penyulang, string $q): Builder
    {
        return DB::table('keypoints')
            ->whereIn(DB::raw(PenyulangName::sqlNormalized('penyulang')), $this->penyulangNames($penyulang))
            ->when($q !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where(DB::raw('upper(nama)'), 'like', '%'.mb_strtoupper($q).'%')
                ->orWhere(DB::raw('upper(jenis)'), 'like', '%'.mb_strtoupper($q).'%')))
            ->orderBy('jenis')
            ->orderBy('nama')
            ->select(['id', 'jenis', 'nama', 'penyulang', 'latitude', 'longitude', 'note']);
    }

    private function vegetationQuery(Penyulang $penyulang, string $q): Builder
    {
        return DB::table('vegetasi_source_rows')
            ->whereIn(DB::raw(PenyulangName::sqlNormalized('penyulang_raw')), $this->penyulangNames($penyulang))
            ->when($q !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where(DB::raw('upper(id_pohon_raw)'), 'like', '%'.mb_strtoupper($q).'%')
                ->orWhere(DB::raw('upper(nama_pohon_raw)'), 'like', '%'.mb_strtoupper($q).'%')
                ->orWhere(DB::raw('upper(nama_tiang_raw)'), 'like', '%'.mb_strtoupper($q).'%')))
            ->orderBy('source_row_number')
            ->select(['id', 'id_pohon_raw', 'nama_pohon_raw', 'nama_tiang_raw', 'jenis_pohon_raw', 'kondisi_raw', 'jarak_pohon_saat_ini_raw', 'penyulang_raw', 'section_raw', 'latitude_raw', 'longitude_raw', 'tanggal_perkiraan_bahaya_raw']);
    }

    public static function coordinatesOf(object $row): ?array
    {
        if (property_exists($row, 'latitude_raw')) {
            [$lat, $lon] = LocationTargetResolver::coordinates($row->latitude_raw, $row->longitude_raw);

            return $lat === null ? null : [$lat, $lon];
        }

        return $row->latitude !== null && $row->longitude !== null ? [$row->latitude, $row->longitude] : null;
    }
}
