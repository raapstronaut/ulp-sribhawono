<?php

use App\Http\Controllers\Admin\AccountSettingsController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\HarsusController;
use App\Http\Controllers\Admin\InspectionController;
use App\Http\Controllers\Admin\MapDataController;
use App\Http\Controllers\Admin\MasterDataController;
use App\Http\Controllers\Admin\P0Controller;
use App\Http\Controllers\Admin\PasilaController;
use App\Http\Controllers\Admin\ReviewController;
use App\Http\Controllers\Admin\RowController;
use App\Http\Controllers\Admin\SpecialCaseController;
use App\Http\Controllers\Admin\UserAccessController;
use App\Http\Controllers\Admin\WorkOrderController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'admin'])
    ->group(function (): void {

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index'])
            ->name('dashboard');

        // Maps
        Route::view('/maps', 'admin.maps.index')
            ->name('maps.index');
    Route::get('/maps/data', [MapDataController::class, 'index'])->name('maps.data');

        // Data Keypoint untuk marker Leaflet
        Route::get('/maps/keypoints', function () {
            $points = DB::table('keypoints')
                ->where('sumber_input', 'SPS_BOS')
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->whereBetween('latitude', [-90, 90])
                ->whereBetween('longitude', [-180, 180])
                ->orderBy('id')
                ->get([
                    'id',
                    'penyulang',
                    'jenis',
                    'nama',
                    'latitude',
                    'longitude',
                ])
                ->map(fn ($point) => [
                    'id' => $point->id,
                    'penyulang' => $point->penyulang,
                    'jenis' => $point->jenis,
                    'nama' => $point->nama,
                    'latitude' => (float) $point->latitude,
                    'longitude' => (float) $point->longitude,
                ]);

            return response()->json([
                'data' => $points,
            ]);
        })->name('maps.keypoints');

        // P0
        Route::get('/p0', [P0Controller::class, 'index'])
            ->name('p0.index');

        Route::get('/p0/{p0}', [P0Controller::class, 'show'])
            ->name('p0.show');

        // Work Order
        Route::get('/work-orders', [WorkOrderController::class, 'index'])
            ->name('work-orders.index');

        Route::get('/work-orders/create', [WorkOrderController::class, 'create'])
            ->name('work-orders.create');

        Route::post('/work-orders', [WorkOrderController::class, 'store'])
            ->name('work-orders.store');

        Route::get('/work-orders/{workOrder}', [WorkOrderController::class, 'show'])
            ->name('work-orders.show');

        Route::post('/work-orders/{workOrder}/cancel', [WorkOrderController::class, 'cancel'])
            ->name('work-orders.cancel');

        // Web Admin Foundation v1: modul baca saja, tanpa tindakan tulis.
        Route::get('/pasila', [PasilaController::class, 'index'])->name('pasila.index');
        Route::get('/pasila/{posko}', [PasilaController::class, 'show'])->name('pasila.show');

        Route::get('/inspeksi', [InspectionController::class, 'index'])->name('inspeksi.index');
        Route::get('/inspeksi/{inspection}', [InspectionController::class, 'show'])
            ->where('inspection', '[A-Za-z0-9\-]{1,64}')
            ->name('inspeksi.show');

        Route::get('/row', [RowController::class, 'index'])->name('row.index');
        Route::get('/harsus', [HarsusController::class, 'index'])->name('harsus.index');

        Route::get('/review', [ReviewController::class, 'index'])->name('reviews.index');

        Route::get('/special-case', [SpecialCaseController::class, 'index'])->name('special-case.index');
        Route::get('/special-case/detail', [SpecialCaseController::class, 'show'])->name('special-case.show');

        Route::get('/master-data', [MasterDataController::class, 'index'])->name('master-data.index');
        Route::get('/master-data/posko/{posko}', [MasterDataController::class, 'showPosko'])->name('master-data.posko.show');
        Route::get('/master-data/penyulang/{penyulang}', [MasterDataController::class, 'showPenyulang'])->name('master-data.penyulang.show');

        Route::get('/analitik', [AnalyticsController::class, 'index'])->name('analytics.index');

        Route::get('/users', [UserAccessController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [UserAccessController::class, 'show'])->where('user', '[0-9]{1,18}')->name('users.show');

        Route::get('/audit-log', [AuditLogController::class, 'index'])->name('audit-logs.index');
        Route::get('/audit-log/{auditLog}', [AuditLogController::class, 'show'])->where('auditLog', '[0-9]{1,18}')->name('audit-logs.show');

        // Pengaturan Akun
        Route::get('/account', [AccountSettingsController::class, 'edit'])
            ->name('account.edit');

        Route::patch('/account/username', [AccountSettingsController::class, 'updateUsername'])
            ->name('account.username.update');

        Route::put('/account/password', [AccountSettingsController::class, 'updatePassword'])
            ->name('account.password.update');
    });