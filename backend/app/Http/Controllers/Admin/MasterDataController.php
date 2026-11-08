<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FieldPersonnel;
use App\Models\FieldTeam;
use App\Models\FieldTeamShiftAssignment;
use App\Models\Penyulang;
use App\Models\Posko;
use App\Models\WorkOrder;
use App\Services\Apkuk\ApkukSourceState;
use App\Services\PoskoCrewRotationService;
use App\Services\ShiftService;
use Carbon\CarbonImmutable;
use App\Support\DataLoader;
use App\Support\PenyulangName;
use App\Support\QueryInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Master & sumber data Web Admin. Tim, petugas, kode, dan jadwal shift dikelola lewat
 * MasterDataManageController; Posko, Penyulang, dan Keypoint tetap hasil sinkronisasi.
 */
class MasterDataController extends Controller
{
    private const PER_PAGE = 25;

    public const TABS = [
        'posko' => 'Posko',
        'penyulang' => 'Penyulang',
        'tim' => 'Tim Lapangan',
        'petugas' => 'Petugas',
        'kode' => 'Kode Referensi',
        'jadwal' => 'Jadwal Shift Tim',
        'keypoint' => 'Keypoint',
        'sumber' => 'Sumber Data',
    ];

    public const TEAM_ROLES = ['POSKO' => 'POSKO', 'HARSUS' => 'HARSUS', 'INSPEKSI' => 'INSPEKSI', 'ROW' => 'ROW'];

    public const STATUSES = ['active' => 'Aktif', 'inactive' => 'Nonaktif'];

    public function index(Request $request, DataLoader $load): View
    {
        $tab = QueryInput::choice($request, 'tab', array_keys(self::TABS), 'posko');
        $filters = [
            'status' => QueryInput::choice($request, 'status', array_keys(self::STATUSES)),
            'role' => QueryInput::choice($request, 'role', array_keys(self::TEAM_ROLES)),
            // Nilai dropdown dicocokkan persis dengan data sumber.
            'category' => QueryInput::text($request, 'category', 80, trim: false),
            'penyulang' => QueryInput::text($request, 'penyulang', 100, trim: false),
            'jenis' => QueryInput::text($request, 'jenis', 100, trim: false),
            'q' => QueryInput::text($request, 'q'),
        ];
        $activeFlag = $filters['status'] === '' ? null : $filters['status'] === 'active';
        $like = '%'.mb_strtoupper($filters['q']).'%';

        $counts = [
            'posko' => $load->attempt('count_posko', fn () => Posko::query()->count()),
            'penyulang' => $load->attempt('count_penyulang', fn () => Penyulang::query()->count()),
            'tim' => $load->attempt('count_tim', fn () => FieldTeam::query()->count()),
            'petugas' => $load->attempt('count_petugas', fn () => FieldPersonnel::query()->count()),
            'kode' => $load->attempt('count_kode', fn () => DB::table('reference_codes')->count()),
            'keypoint' => $load->attempt('count_keypoint', fn () => DB::table('keypoints')->count()),
        ];

        $data = ['options' => []];

        switch ($tab) {
            case 'posko':
                $data['rows'] = $load->attempt('rows', fn () => Posko::query()
                    ->withCount(['penyulangs'])
                    ->when($activeFlag !== null, fn ($query) => $query->where('is_active', $activeFlag))
                    ->when($filters['q'] !== '', fn ($query) => $query->where(fn ($inner) => $inner
                        ->where(DB::raw('upper(nama_posko)'), 'like', $like)
                        ->orWhere(DB::raw('upper(kode_posko)'), 'like', $like)))
                    ->orderBy('nama_posko')
                    ->paginate(self::PER_PAGE)
                    ->withQueryString()
                    ->tap(DataLoader::withinRange(...)), DataLoader::emptyPage(self::PER_PAGE));
                $data['teamCounts'] = $load->attempt('rows', fn () => FieldTeam::query()
                    ->whereNotNull('posko_id')
                    ->select('posko_id', DB::raw('count(*) as total'))
                    ->groupBy('posko_id')
                    ->pluck('total', 'posko_id'), collect());
                break;

            case 'penyulang':
                $data['rows'] = $load->attempt('rows', fn () => Penyulang::query()
                    ->withCount(['poskos'])
                    ->when($activeFlag !== null, fn ($query) => $query->where('is_active', $activeFlag))
                    ->when($filters['q'] !== '', fn ($query) => $query->where(fn ($inner) => $inner
                        ->where(DB::raw('upper(nama_penyulang)'), 'like', $like)
                        ->orWhere(DB::raw('upper(kode_penyulang)'), 'like', $like)
                        ->orWhere(DB::raw('upper(gardu_induk)'), 'like', $like)))
                    ->orderBy('kode_penyulang')
                    ->paginate(self::PER_PAGE)
                    ->withQueryString()
                    ->tap(DataLoader::withinRange(...)), DataLoader::emptyPage(self::PER_PAGE));
                break;

            case 'tim':
                $data['rows'] = $load->attempt('rows', fn () => FieldTeam::query()
                    ->with('posko')
                    ->withCount(['personnel', 'personnel as active_personnel_count' => fn ($query) => $query->where('is_active', true)])
                    ->when($filters['role'] !== '', fn ($query) => $query->where('role', $filters['role']))
                    ->when($activeFlag !== null, fn ($query) => $query->where('is_active', $activeFlag))
                    ->when($filters['q'] !== '', fn ($query) => $query->where(fn ($inner) => $inner
                        ->where(DB::raw('upper(name)'), 'like', $like)
                        ->orWhere(DB::raw('upper(code)'), 'like', $like)))
                    ->orderBy('role')
                    ->orderBy('name')
                    ->paginate(self::PER_PAGE)
                    ->withQueryString()
                    ->tap(DataLoader::withinRange(...)), DataLoader::emptyPage(self::PER_PAGE));
                break;

            case 'petugas':
                $data['rows'] = $load->attempt('rows', fn () => FieldPersonnel::query()
                    ->with('team')
                    ->when($filters['role'] !== '', fn ($query) => $query->whereHas('team', fn ($team) => $team->where('role', $filters['role'])))
                    ->when($activeFlag !== null, fn ($query) => $query->where('is_active', $activeFlag))
                    ->when($filters['q'] !== '', fn ($query) => $query->where(fn ($inner) => $inner
                        ->where(DB::raw('upper(name)'), 'like', $like)
                        ->orWhere(DB::raw('upper(personnel_code)'), 'like', $like)))
                    ->orderBy('name')
                    ->paginate(self::PER_PAGE)
                    ->withQueryString()
                    ->tap(DataLoader::withinRange(...)), DataLoader::emptyPage(self::PER_PAGE));
                break;

            case 'kode':
                $data['options']['category'] = $load->attempt('rows', fn () => DB::table('reference_codes')
                    ->distinct()
                    ->orderBy('category')
                    ->pluck('category', 'category')
                    ->all(), []);
                $data['rows'] = $load->attempt('rows', fn () => DB::table('reference_codes')
                    ->when($filters['category'] !== '', fn ($query) => $query->where('category', $filters['category']))
                    ->when($activeFlag !== null, fn ($query) => $query->where('is_active', $activeFlag))
                    ->when($filters['q'] !== '', fn ($query) => $query->where(fn ($inner) => $inner
                        ->where(DB::raw('upper(code)'), 'like', $like)
                        ->orWhere(DB::raw('upper(label)'), 'like', $like)))
                    ->orderBy('category')
                    ->orderBy('sort_order')
                    ->orderBy('code')
                    ->paginate(self::PER_PAGE)
                    ->withQueryString()
                    ->tap(DataLoader::withinRange(...)), DataLoader::emptyPage(self::PER_PAGE));
                break;

            case 'jadwal':
                $data['rosterPoskos'] = $load->attempt('roster_poskos', fn () => Posko::query()->where('is_active', true)->orderBy('nama_posko')->get(['id', 'nama_posko']), collect());
                $data['rows'] = $load->attempt('rows', fn () => FieldTeamShiftAssignment::query()
                    ->with('team.posko')
                    ->when($filters['q'] !== '', fn ($query) => $query->whereHas('team', fn ($team) => $team
                        ->where(DB::raw('upper(name)'), 'like', $like)
                        ->orWhere(DB::raw('upper(code)'), 'like', $like)))
                    ->when($activeFlag !== null, fn ($query) => $activeFlag
                        ? $query->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', now(ShiftService::TIMEZONE)->toDateString()))
                        : $query->where('effective_until', '<', now(ShiftService::TIMEZONE)->toDateString()))
                    ->orderByDesc('effective_from')
                    ->paginate(self::PER_PAGE)
                    ->withQueryString()
                    ->tap(DataLoader::withinRange(...)), DataLoader::emptyPage(self::PER_PAGE));
                break;

            case 'keypoint':
                $data['options']['penyulang'] = $load->attempt('rows', fn () => DB::table('keypoints')
                    ->whereNotNull('penyulang')
                    ->distinct()
                    ->orderBy('penyulang')
                    ->pluck('penyulang', 'penyulang')
                    ->all(), []);
                $data['options']['jenis'] = $load->attempt('rows', fn () => DB::table('keypoints')
                    ->whereNotNull('jenis')
                    ->distinct()
                    ->orderBy('jenis')
                    ->pluck('jenis', 'jenis')
                    ->all(), []);
                $data['rows'] = $load->attempt('rows', fn () => DB::table('keypoints')
                    ->when($filters['penyulang'] !== '', fn ($query) => $query->where('penyulang', $filters['penyulang']))
                    ->when($filters['jenis'] !== '', fn ($query) => $query->where('jenis', $filters['jenis']))
                    ->when($filters['q'] !== '', fn ($query) => $query->where(DB::raw('upper(nama)'), 'like', $like))
                    ->orderBy('penyulang')
                    ->orderBy('nama')
                    ->paginate(self::PER_PAGE, ['id', 'penyulang', 'jenis', 'nama', 'latitude', 'longitude', 'sumber_input', 'last_synced_at'])
                    ->withQueryString()
                    ->tap(DataLoader::withinRange(...)), DataLoader::emptyPage(self::PER_PAGE));
                break;

            case 'sumber':
                $data['sources'] = $this->sourceSummaries($load);
                break;
        }

        return view('admin.master-data.index', [
            'tab' => $tab,
            // Tab yang dapat dikelola dari Web => slug MasterDataManageController.
            'manageable' => ['tim' => 'tim', 'petugas' => 'petugas', 'kode' => 'kode'],
            'filters' => $filters,
            'hasFilter' => collect($filters)->contains(fn ($value) => $value !== ''),
            'counts' => $counts,
            'load' => $load,
            ...$data,
        ]);
    }

    public function showPosko(Posko $posko, DataLoader $load, Request $request, PoskoCrewRotationService $rotation): View
    {
        $isValidDate = static function (string $date): bool {
            if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts)) {
                return false;
            }

            return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
        };

        $configuredAnchor = config('field_ops.posko_crew_anchor_date');
        $anchor = is_string($configuredAnchor) && $isValidDate($configuredAnchor) ? $configuredAnchor : null;
        $today = app(ShiftService::class)->operationalDate();
        $defaultDate = $anchor !== null && $today < $anchor ? $anchor : $today;
        $requestedDate = $request->query('date');
        $rosterDate = is_string($requestedDate) && $isValidDate($requestedDate)
            && ($anchor === null || $requestedDate >= $anchor)
                ? $requestedDate
                : $defaultDate;

        $teams = $load->attempt('teams', fn () => FieldTeam::query()
            ->where('posko_id', $posko->id)
            ->with('personnel')
            ->withCount(['personnel as active_personnel_count' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('name')
            ->get(), collect());
        $poskoTeams = $teams->where('role', 'POSKO');
        $poskoTeam = $poskoTeams->count() === 1 ? $poskoTeams->first() : null;
        $roster = $poskoTeam === null ? null : $rotation->roster(
            $poskoTeam,
            CarbonImmutable::parse($rosterDate.' 08:00:00', ShiftService::TIMEZONE),
        );

        $rosterDays = [];
        if ($anchor !== null) {
            for ($i = 0; $i < 4; $i++) {
                $date = CarbonImmutable::parse($rosterDate, ShiftService::TIMEZONE)->addDays($i)->toDateString();
                $pagi = $rotation->forDate($date, 'A');
                $sore = $rotation->forDate($date, 'B');
                $malam = $rotation->forDate($date, 'C');
                if ($pagi === null || $sore === null || $malam === null) {
                    $rosterDays = [];
                    break;
                }
                $rosterDays[] = [
                    'date' => $date,
                    'cycle_day' => $pagi['cycle_day'],
                    'by_crew' => [
                        $pagi['crew_number'] => 'Pagi',
                        $sore['crew_number'] => 'Sore',
                        $malam['crew_number'] => 'Malam',
                        $pagi['off_crew_number'] => 'Libur',
                    ],
                ];
            }
        }

        return view('admin.master-data.posko', [
            'posko' => $posko,
            'penyulangs' => $load->attempt('penyulangs', fn () => $posko->penyulangs()->orderBy('kode_penyulang')->get(), collect()),
            'teams' => $teams,
            'workOrders' => $load->attempt('work_orders', fn () => [
                'total' => WorkOrder::query()->where('posko_id', $posko->id)->count(),
                'active' => WorkOrder::query()->where('posko_id', $posko->id)->active()->count(),
            ]),
            'rosterAnchor' => $anchor,
            'rosterDate' => $rosterDate,
            'roster' => $roster,
            'poskoTeamCount' => $poskoTeams->count(),
            'poskoTeamActive' => $poskoTeam?->is_active ?? false,
            'rosterDays' => $rosterDays,
            'load' => $load,
        ]);
    }


    public function showPenyulang(Penyulang $penyulang, DataLoader $load): View
    {
        // Data sinkron menyimpan penyulang sebagai teks; dicocokkan dengan kode/nama master
        // memakai normalisasi yang sama dengan kandidat WO APKUK (kapital, titik di akhir, alias
        // terkonfirmasi seperti TANGGAMUS → TANGGAMUS 2), bukan relasi canonical.
        $names = PenyulangName::sourceNames($penyulang);
        $matching = fn (string $table) => DB::table($table)->whereIn(DB::raw(PenyulangName::sqlNormalized('penyulang')), $names);

        return view('admin.master-data.penyulang', [
            'penyulang' => $penyulang,
            'poskos' => $load->attempt('poskos', fn () => $penyulang->poskos()->orderBy('nama_posko')->get(), collect()),
            'sourceCounts' => [
                'keypoints' => $load->attempt('keypoints', fn () => $matching('keypoints')->count()),
                'apkuks' => $load->attempt('apkuks', fn () => ApkukSourceState::active($matching('apkuks'))->count()),
                'gangguans' => $load->attempt('gangguans', fn () => $matching('gangguans')->count()),
            ],
            'workOrders' => $load->attempt('work_orders', fn () => WorkOrder::query()
                ->where('penyulang_id', $penyulang->id)
                ->select('team_type', DB::raw('count(*) as total'))
                ->groupBy('team_type')
                ->pluck('total', 'team_type')
                ->all(), []),
            'matchedNames' => $names,
            'load' => $load,
        ]);
    }

    /**
     * @return array<int, array{key: string, label: string, table: string, kind: string, description: string, total: ?int, extra: ?string, last_at: ?string}>
     */
    private function sourceSummaries(DataLoader $load): array
    {
        $sources = [
            ['table' => 'asset_source_rows', 'label' => 'Aset (ASSET_RAW)', 'kind' => 'Staging mentah', 'time' => 'imported_at', 'description' => 'Snapshot mentah; belum menjadi master aset canonical.'],
            ['table' => 'vegetasi_source_rows', 'label' => 'Vegetasi (VEGETASI_RAW)', 'kind' => 'Staging mentah', 'time' => 'imported_at', 'description' => 'Bahan vegetasi canonical untuk ROW; belum tervalidasi.'],
            ['table' => 'section_pic_source_rows', 'label' => 'Section / PIC (SECTION_PIC_RAW)', 'kind' => 'Staging mentah', 'time' => 'imported_at', 'description' => 'Grouping visual sumber dipertahankan; tidak di-fill-down.'],
            ['table' => 'keypoint_source_rows', 'label' => 'Keypoint (staging lama)', 'kind' => 'Staging mentah', 'time' => 'imported_at', 'description' => 'Digantikan sinkron langsung ke tabel keypoints.'],
            ['table' => 'keypoints', 'label' => 'Keypoint (SPS BOS)', 'kind' => 'Sinkron sumber', 'time' => 'last_synced_at', 'description' => 'keypoint:sync; mencakup FCO/switching menurut stakeholder, tetap perlu audit.'],
            ['table' => 'apkuks', 'label' => 'APKUK (SPS BOS)', 'kind' => 'Sinkron sumber', 'time' => 'last_synced_at', 'description' => 'apkuk:sync; laporan hilang tetap menunggu rekonsiliasi sampai muncul kembali atau diarsipkan Admin atas konfirmasi pemilik data. Histori tidak dihapus.'],
            ['table' => 'gangguans', 'label' => 'Gangguan (PLN pusat)', 'kind' => 'Sinkron sumber', 'time' => 'last_synced_at', 'description' => 'gangguan:sync; belum memiliki koordinat kejadian dan aset terdampak.'],
        ];

        return array_map(function (array $source) use ($load): array {
            $section = 'source_'.$source['table'];
            // Nama tabel/kolom berasal dari daftar tetap di atas.
            $query = DB::table($source['table'])
                ->selectRaw("count(*) as total, max({$source['time']}) as last_at");
            if ($source['table'] === 'apkuks') {
                // Satu round-trip dengan subquery yang memakai definisi status sumber tunggal.
                $query
                    ->selectSub(ApkukSourceState::active(DB::table('apkuks as apkuk_active'), 'apkuk_active')->selectRaw('count(*)'), 'active')
                    ->selectSub(ApkukSourceState::pending(DB::table('apkuks as apkuk_pending'), 'apkuk_pending')->selectRaw('count(*)'), 'pending')
                    ->selectSub(ApkukSourceState::history(DB::table('apkuks as apkuk_history'), 'apkuk_history')->selectRaw('count(*)'), 'history');
            }
            $stats = $load->attempt($section, fn () => $query->first());

            return [
                'key' => $section,
                'label' => $source['label'],
                'table' => $source['table'],
                'kind' => $source['kind'],
                'description' => $source['description'],
                'total' => $stats === null ? null : (int) $stats->total,
                'extra' => isset($stats->active)
                    ? ((int) $stats->active).' aktif · '.((int) $stats->pending).' menunggu rekonsiliasi · '.((int) $stats->history).' histori'
                    : null,
                'last_at' => $stats?->last_at,
            ];
        }, $sources);
    }
}
