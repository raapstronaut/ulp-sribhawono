<?php

use App\Http\Controllers\Admin\AccountSettingsController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\MapDataController;
use App\Http\Controllers\Admin\P0Controller;
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

        // Antrean rekonsiliasi APKUK (laporan yang tidak lagi ada di APKUK_RAW)
        Route::get('/apkuk-rekonsiliasi', [\App\Http\Controllers\Admin\ApkukReconciliationController::class, 'index'])
            ->name('apkuk-reconciliation.index');

        Route::post('/apkuk-rekonsiliasi/{item}/selesaikan', [\App\Http\Controllers\Admin\ApkukReconciliationController::class, 'resolve'])
            ->whereNumber('item')
            ->name('apkuk-reconciliation.resolve');

        Route::post('/apkuk-rekonsiliasi/konfirmasi-hapus', [\App\Http\Controllers\Admin\ApkukReconciliationController::class, 'resolveMany'])
            ->name('apkuk-reconciliation.resolve-many');

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

        // Pengaturan Akun
        Route::get('/account', [AccountSettingsController::class, 'edit'])
            ->name('account.edit');

        Route::patch('/account/username', [AccountSettingsController::class, 'updateUsername'])
            ->name('account.username.update');

        Route::put('/account/password', [AccountSettingsController::class, 'updatePassword'])
            ->name('account.password.update');
    });