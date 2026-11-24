<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Katalog sumber ASSET_RAW, bukan master aset permanen.
 * ID baris staging dapat berubah setelah import --replace.
 * Belum ada hubungan historis yang bisa dipastikan antara ASSET_RAW dan WO.
 */
final class AssetCatalogController extends Controller
{
    public function index(Request $request): View
    {
        // Query input is always reduced to bounded strings, never injected into SQL.
        $read = static function (string $key) use ($request): string {
            $value = $request->query($key);
            return is_string($value) ? mb_substr(trim($value), 0, 80) : '';
        };

        $q = $read('q');
        $filters = [
            'jenis' => $read('jenis'),
            'penyulang' => $read('penyulang'),
            'status' => $read('status'),
        ];
        $columns = [
            'jenis' => 'jenis_aset_raw',
            'penyulang' => 'penyulang_raw',
            'status' => 'status_raw',
        ];
        $options = ['jenis' => [], 'penyulang' => [], 'status' => []];
        $ready = Schema::hasTable('asset_source_rows');
        $assets = null;

        if ($ready) {
            foreach ($columns as $key => $column) {
                $options[$key] = DB::table('asset_source_rows')
                    ->whereNotNull($column)->where($column, '<>', '')
                    ->distinct()->orderBy($column)->limit(200)
                    ->pluck($column)->all();
            }

            $builder = DB::table('asset_source_rows');
            if ($q !== '') {
                $like = '%'.mb_strtolower($q).'%';
                $builder->where(function ($query) use ($like): void {
                    $query->whereRaw('lower(id_aset_raw) like ?', [$like])
                        ->orWhereRaw('lower(nama_aset_raw) like ?', [$like])
                        ->orWhereRaw('lower(jenis_aset_raw) like ?', [$like])
                        ->orWhereRaw('lower(penyulang_raw) like ?', [$like]);
                });
            }
            foreach ($columns as $key => $column) {
                if ($filters[$key] !== '') {
                    $builder->where($column, $filters[$key]);
                }
            }
            $assets = $builder->orderBy('id')->paginate(25)->withQueryString();
        }

        return view('admin.aset.index', compact('assets', 'q', 'ready', 'filters', 'options'));
    }

    public function show(int $asset): View
    {
        abort_unless(Schema::hasTable('asset_source_rows'), 503);
        $row = DB::table('asset_source_rows')->where('id', $asset)->first();
        abort_if($row === null, 404);

        $sameIdCount = 0;
        if (filled($row->id_aset_raw)) {
            $sameIdCount = DB::table('asset_source_rows')
                ->where('id_aset_raw', $row->id_aset_raw)->count();
        }

        return view('admin.aset.show', [
            'asset' => $row,
            'sameIdCount' => $sameIdCount,
        ]);
    }
}
