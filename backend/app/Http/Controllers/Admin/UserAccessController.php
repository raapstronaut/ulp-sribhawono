<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FieldTeam;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderStatusLog;
use App\Support\DataLoader;
use App\Support\QueryInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Daftar akun dan matriks hak akses (baca saja). Password tidak pernah dimuat ke view.
 */
class UserAccessController extends Controller
{
    private const PER_PAGE = 20;

    private const USER_COLUMNS = ['id', 'name', 'email', 'username', 'role', 'is_active', 'created_at', 'updated_at'];

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
        ['role' => 'POSKO', 'platform' => 'Android', 'scope' => '6 WO APKUK/shift/Posko, PASILA + P0 dalam PASILA, reschedule.', 'status' => 'Login mobile belum dibangun'],
        ['role' => 'HARSUS', 'platform' => 'Android', 'scope' => 'WO PEKERJAAN_KHUSUS manual Admin atau limpahan setelah ACC; bukan P0.', 'status' => 'Login mobile belum dibangun'],
        ['role' => 'INSPEKSI', 'platform' => 'Android', 'scope' => 'Temuan nyata ≥ 1/hari, ranking area + rotasi, validasi Admin.', 'status' => 'Login mobile belum dibangun'],
        ['role' => 'ROW', 'platform' => 'Android', 'scope' => 'WO RABAS/PANGKAS/TEBANG otomatis dan manual, rute cluster.', 'status' => 'Login mobile belum dibangun'],
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
            'load' => $load,
        ]);
    }
}
