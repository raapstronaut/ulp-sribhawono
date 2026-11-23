<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Inspection\InspectionFullGeneratorPilot;
use App\Services\ShiftService;
use App\Support\QueryInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * V6B1: read-only INSPEKSI planning screen (legacy internal class name). Never invokes run()/generate(),
 * never persists ShiftInstance, generation runs, allocations, or work orders.
 * Fail closed outside the verified local backend / isolated in-memory tests.
 */
final class InspectionFullQuotaPreviewController extends Controller
{
    public function index(Request $request, ShiftService $shifts, InspectionFullGeneratorPilot $pilot): View
    {
        abort_unless($this->isAllowedReadOnlyEnvironment(), 404);
        abort_unless(
            Schema::hasColumn('work_order_apkuk_targets', 'inspection_finding_id')
                && Schema::hasColumn('inspection_findings', 'apkuk_target_quantity'),
            503,
            'Skema target APKUK dari INSPEKSI belum tersedia. Tidak ada operasi generator yang dijalankan.'
        );

        $current = $shifts->instanceAt();
        $date = QueryInput::date($request, 'operational_date') ?: $current['operational_date'];
        $shift = QueryInput::choice($request, 'shift_code', $shifts->codes(), $current['code']);
        $data = $pilot->preview($date, $shift); // THIS METHOD IS READ-ONLY.

        return view('admin.shift-quota.inspection-full-preview', [
            'preview' => $data,
            'date' => $date,
            'shift' => $shift,
            'shiftOptions' => collect($shifts->codes())->mapWithKeys(
                fn (string $code) => [$code => 'Shift '.$code.' ('.$shifts->describe($code)['time'].')']
            )->all(),
        ]);
    }

    private function isAllowedReadOnlyEnvironment(): bool
    {
        $env = (string) app()->environment();
        $connection = (string) config('database.default');
        $db = (array) config("database.connections.{$connection}", []);
        if (filled($db['url'] ?? null)) {
            return false;
        }
        if ($env === 'testing') {
            return $connection === 'sqlite'
                && ($db['driver'] ?? null) === 'sqlite'
                && ($db['database'] ?? null) === ':memory:';
        }
        return $env === 'local'
            && $connection === 'pgsql'
            && ($db['driver'] ?? null) === 'pgsql'
            && in_array((string) ($db['host'] ?? ''), ['127.0.0.1', 'localhost', '::1'], true)
            && (string) ($db['port'] ?? '') === '5432'
            && ($db['database'] ?? null) === 'ulp_sribhawono_dev';
    }
}
