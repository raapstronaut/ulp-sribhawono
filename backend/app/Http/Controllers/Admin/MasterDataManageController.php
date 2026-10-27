<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FieldPersonnel;
use App\Models\FieldTeam;
use App\Models\FieldTeamShiftAssignment;
use App\Models\Posko;
use App\Models\ReferenceCode;
use App\Services\Admin\MasterDataService;
use App\Services\ShiftService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Tambah/ubah/hapus master milik aplikasi. Setiap perubahan tercatat di Audit Log.
 */
class MasterDataManageController extends Controller
{
    public const TYPES = [
        'tim' => 'Tim lapangan',
        'petugas' => 'Petugas',
        'kode' => 'Kode referensi',
        'jadwal' => 'Jadwal shift tim',
    ];

    public function __construct(private readonly MasterDataService $service) {}

    public function create(string $type, ShiftService $shifts): View
    {
        return $this->form($type, null, $shifts);
    }

    public function edit(string $type, string $id, ShiftService $shifts): View
    {
        return $this->form($type, $this->find($type, $id), $shifts);
    }

    public function store(Request $request, string $type): RedirectResponse
    {
        $model = $this->save($request, $type, null);

        return redirect()->route('admin.master-data.index', ['tab' => $type])->with('flash', self::TYPES[$type].' tersimpan.'.($model instanceof FieldTeam ? " Kode {$model->code}." : ''));
    }

    public function update(Request $request, string $type, string $id): RedirectResponse
    {
        $this->save($request, $type, $this->find($type, $id));

        return redirect()->route('admin.master-data.index', ['tab' => $type])->with('flash', self::TYPES[$type].' diperbarui.');
    }

    public function destroy(Request $request, string $type, string $id): RedirectResponse
    {
        $model = $this->find($type, $id);
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']])['reason'];
        $user = $request->user();

        match ($type) {
            'tim' => $this->service->deleteTeam($user, $model, $reason),
            'petugas' => $this->service->deletePersonnel($user, $model, $reason),
            'kode' => $this->service->deleteReferenceCode($user, $model, $reason),
            'jadwal' => $this->service->deleteShiftAssignment($user, $model, $reason),
        };

        return redirect()->route('admin.master-data.index', ['tab' => $type])->with('flash', self::TYPES[$type].' dihapus.');
    }

    private function save(Request $request, string $type, ?Model $model): Model
    {
        $user = $request->user();
        $id = $model?->getKey();

        return match ($type) {
            'tim' => $this->service->saveTeam($user, $model, $request->validate([
                'code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_\-\.]+$/', Rule::unique('field_teams', 'code')->ignore($id)],
                'name' => ['required', 'string', 'max:255'],
                'role' => ['required', Rule::in(MasterDataService::TEAM_ROLES)],
                'posko_id' => ['nullable', 'uuid'],
                'is_active' => ['nullable', 'boolean'],
            ]) + ['is_active' => $request->boolean('is_active')], $request->input('reason')),
            'petugas' => $this->service->savePersonnel($user, $model, $request->validate([
                'field_team_id' => ['required', 'uuid'],
                'personnel_code' => ['nullable', 'string', 'max:50', Rule::unique('field_personnel', 'personnel_code')->ignore($id)],
                'name' => ['required', 'string', 'max:255'],
                'is_active' => ['nullable', 'boolean'],
            ]) + ['is_active' => $request->boolean('is_active')], $request->input('reason')),
            'kode' => $this->service->saveReferenceCode($user, $model, $request->validate([
                'category' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_]+$/'],
                'code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_]+$/', Rule::unique('reference_codes', 'code')->where('category', mb_strtoupper((string) $request->input('category')))->ignore($id)],
                'label' => ['required', 'string', 'max:255'],
                'role_scope' => ['nullable', Rule::in(ReferenceCode::ROLE_SCOPES)],
                'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
                'is_active' => ['nullable', 'boolean'],
            ]) + ['is_active' => $request->boolean('is_active')], $request->input('reason')),
            'jadwal' => $this->service->saveShiftAssignment($user, $model, $request->validate([
                'field_team_id' => ['required', 'uuid'],
                'shift_code' => ['required', Rule::in(app(ShiftService::class)->codes())],
                'effective_from' => ['required', 'date_format:Y-m-d'],
                'effective_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            ]), $request->input('reason')),
            default => abort(404),
        };
    }

    private function form(string $type, ?Model $model, ShiftService $shifts): View
    {
        abort_unless(array_key_exists($type, self::TYPES), 404);

        return view('admin.master-data.form', [
            'type' => $type,
            'typeLabel' => self::TYPES[$type],
            'model' => $model,
            'poskoOptions' => Posko::query()->orderBy('nama_posko')->pluck('nama_posko', 'id')->all(),
            'teamOptions' => FieldTeam::query()->orderBy('role')->orderBy('name')->get()->mapWithKeys(fn ($t) => [$t->id => $t->role.' · '.$t->name.($t->is_active ? '' : ' (nonaktif)')])->all(),
            'poskoTeamOptions' => FieldTeam::query()->where('role', 'POSKO')->orderBy('name')->pluck('name', 'id')->all(),
            'roleOptions' => array_combine(MasterDataService::TEAM_ROLES, MasterDataService::TEAM_ROLES),
            'categoryOptions' => ReferenceCode::CATEGORIES + ReferenceCode::query()->distinct()->orderBy('category')->pluck('category', 'category')->all(),
            'roleScopeOptions' => array_combine(ReferenceCode::ROLE_SCOPES, ReferenceCode::ROLE_SCOPES),
            'shiftOptions' => collect($shifts->codes())->mapWithKeys(fn ($c) => [$c => 'Shift '.$c.' — '.$shifts->describe($c)['name']])->all(),
            'inUse' => match (true) {
                $model instanceof FieldTeam => $this->service->teamInUse($model),
                $model instanceof FieldPersonnel => $this->service->personnelInUse($model),
                $model instanceof ReferenceCode => $this->service->codeInUse($model),
                default => false,
            },
        ]);
    }

    private function find(string $type, string $id): Model
    {
        $class = match ($type) {
            'tim' => FieldTeam::class,
            'petugas' => FieldPersonnel::class,
            'kode' => ReferenceCode::class,
            'jadwal' => FieldTeamShiftAssignment::class,
            default => abort(404),
        };
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id), 404);

        return $class::query()->findOrFail($id);
    }
}
