<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FieldTeam;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderStatusLog;
use App\Services\Admin\UserAccountService;
use App\Support\DataLoader;
use App\Support\QueryInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Akun dan hak akses: daftar, tambah, ubah role/tim, aktif/nonaktif, reset kata sandi lewat
 * UserAccountService; semua perubahan tercatat di Audit Log. Password tidak pernah dimuat ke view.
 */
class UserAccessController extends Controller
{
    private const PER_PAGE = 20;

    private const USER_COLUMNS = ['id', 'name', 'email', 'username', 'role', 'field_team_id', 'is_active', 'created_at', 'updated_at'];

    public const ROLES = [
        'ADMIN' => 'ADMIN',
        'POSKO' => 'POSKO',
        'HARSUS' => 'HARSUS',
        'INSPEKSI' => 'INSPEKSI',
        'ROW' => 'ROW',
        'NONE' => 'Tanpa role',
    ];

    public const STATUSES = ['active' => 'Aktif', 'inactive' => 'Nonaktif'];

    /**
     * Matriks role v3 (PRD/TECHNICAL_SPEC). Hanya ADMIN yang diterapkan di Web saat ini.
     */
    public const ROLE_MATRIX = [
        ['role' => 'ADMIN', 'platform' => 'Web Laravel', 'scope' => 'Monitor semua modul, review/ACC-Tolak, pekerjaan manual, Maps, koreksi histori.', 'status' => 'Diterapkan: middleware auth + admin pada /admin/*'],
        ['role' => 'POSKO', 'platform' => 'Android', 'scope' => '6 WO APKUK/shift/Posko, PASILA + P0 dalam PASILA, reschedule.', 'status' => 'API mobile v1 (token Sanctum) tersedia; aplikasi Android belum diuji bersama backend'],
        ['role' => 'HARSUS', 'platform' => 'Android', 'scope' => 'WO PEKERJAAN_KHUSUS manual Admin atau limpahan setelah ACC; bukan P0.', 'status' => 'API mobile v1 (token Sanctum) tersedia; aplikasi Android belum diuji bersama backend'],
        ['role' => 'INSPEKSI', 'platform' => 'Android', 'scope' => 'Temuan nyata ≥ 1/hari, ranking area + rotasi, validasi Admin.', 'status' => 'API mobile v1 (token Sanctum) tersedia; aplikasi Android belum diuji bersama backend'],
        ['role' => 'ROW', 'platform' => 'Android', 'scope' => 'WO RABAS/PANGKAS/TEBANG otomatis dan manual, rute cluster.', 'status' => 'API mobile v1 (token Sanctum) tersedia; aplikasi Android belum diuji bersama backend'],
    ];

    public function index(Request $request, DataLoader $load): View
    {
        $filters = [
            'role' => QueryInput::choice($request, 'role', array_keys(self::ROLES)),
            'status' => QueryInput::choice($request, 'status', array_keys(self::STATUSES)),
            'q' => QueryInput::text($request, 'q'),
        ];
        $like = '%'.mb_strtoupper($filters['q']).'%';

        return view('admin.users.index', [
            'filters' => $filters,
            'hasFilter' => collect($filters)->contains(fn ($value) => $value !== ''),
            'users' => $load->attempt('users', fn () => User::query()
                ->select(self::USER_COLUMNS)
                ->with('fieldTeam:id,name')
                ->when($filters['role'] === 'NONE', fn ($query) => $query->whereNull('role'))
                ->when($filters['role'] !== '' && $filters['role'] !== 'NONE', fn ($query) => $query->where('role', $filters['role']))
                ->when($filters['status'] !== '', fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
                ->when($filters['q'] !== '', fn ($query) => $query->where(fn ($inner) => $inner
                    ->where(DB::raw('upper(username)'), 'like', $like)
                    ->orWhere(DB::raw('upper(name)'), 'like', $like)
                    ->orWhere(DB::raw('upper(email)'), 'like', $like)))
                ->orderBy('username')
                ->orderBy('id')
                ->paginate(self::PER_PAGE)
                ->withQueryString()
                ->tap(DataLoader::withinRange(...)), DataLoader::emptyPage(self::PER_PAGE)),
            'summary' => $load->attempt('summary', fn () => [
                'total' => User::query()->count(),
                'active_admins' => User::query()->where('role', User::ROLE_ADMIN)->where('is_active', true)->count(),
                'inactive' => User::query()->where('is_active', false)->count(),
                'field_teams' => FieldTeam::query()->count(),
            ]),
            'load' => $load,
        ]);
    }

    public function show(User $user, DataLoader $load): View
    {
        return view('admin.users.show', [
            'account' => $user,
            'activity' => $load->attempt('activity', fn () => [
                'work_orders_created' => WorkOrder::query()->where('created_by_user_id', $user->id)->count(),
                'audit_entries' => WorkOrderStatusLog::query()->where('actor_user_id', $user->id)->count(),
            ]),
            'recentLogs' => $load->attempt('logs', fn () => WorkOrderStatusLog::query()
                ->with('workOrder:id,code,team_type,work_type')
                ->where('actor_user_id', $user->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(10)
                ->get(), collect()),
            'accountAudit' => $load->attempt('account_audit', fn () => AuditLog::query()
                ->with('actor:id,username,name')
                ->where(fn ($q) => $q->where(fn ($inner) => $inner->where('entity_type', 'user')->where('entity_id', (string) $user->id))
                    ->orWhere('actor_user_id', $user->id))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(15)
                ->get(), collect()),
            'tokenCount' => $user->tokens()->count(),
            'teamOptions' => $this->teamOptions(),
            'load' => $load,
        ]);
    }

    public function create(): View
    {
        return view('admin.users.form', ['account' => null, 'teamOptions' => $this->teamOptions()]);
    }

    public function store(Request $request, UserAccountService $accounts): RedirectResponse
    {
        $data = $request->validate($this->rules(null) + [
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);
        $user = $accounts->create($request->user(), $data + ['is_active' => $request->boolean('is_active', true)]);

        return redirect()->route('admin.users.show', $user)->with('flash', "Akun {$user->displayName()} dibuat.");
    }

    public function edit(User $user): View
    {
        return view('admin.users.form', ['account' => $user, 'teamOptions' => $this->teamOptions()]);
    }

    public function update(Request $request, User $user, UserAccountService $accounts): RedirectResponse
    {
        $data = $request->validate($this->rules($user) + ['reason' => ['nullable', 'string', 'max:1000']]);
        $accounts->update($request->user(), $user, $data, $data['reason'] ?? null);

        return redirect()->route('admin.users.show', $user)->with('flash', 'Akun diperbarui.');
    }

    public function activation(Request $request, User $user, UserAccountService $accounts): RedirectResponse
    {
        $data = $request->validate([
            'active' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $accounts->setActive($request->user(), $user, (bool) $data['active'], $data['reason']);

        return redirect()->route('admin.users.show', $user)->with('flash', $data['active'] ? 'Akun diaktifkan.' : 'Akun dinonaktifkan; sesi dan token API dicabut.');
    }

    public function password(Request $request, User $user, UserAccountService $accounts): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $accounts->resetPassword($request->user(), $user, $data['password'], $data['reason']);

        return redirect()->route('admin.users.show', $user)->with('flash', 'Kata sandi direset; sesi lain dan token API akun ini dicabut.');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(?User $user): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'alpha_dash', Rule::unique('users', 'username')->ignore($user?->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'role' => ['required', Rule::in(array_keys(User::ROLE_LABELS))],
            'field_team_id' => ['nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function teamOptions(): array
    {
        return FieldTeam::query()->where('is_active', true)->orderBy('role')->orderBy('name')->get()
            ->groupBy('role')
            ->map(fn ($teams) => $teams->pluck('name', 'id')->all())
            ->all();
    }
}
