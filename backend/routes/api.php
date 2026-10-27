<?php

use App\Http\Controllers\Api\V1\Mobile\AuthController;
use App\Http\Controllers\Api\V1\Mobile\BootstrapController;
use App\Http\Controllers\Api\V1\Mobile\InspectionController;
use App\Http\Controllers\Api\V1\Mobile\WorkOrderController;
use Illuminate\Support\Facades\Route;

/*
| API v1 untuk aplikasi Android tim lapangan (POSKO, HARSUS, INSPEKSI, ROW).
| Respons: { data, meta } atau { message, errors }. 401 tanpa token, 403 akun/tim tidak
| berhak, 404 data di luar cakupan tim, 409 konflik workflow, 422 validasi.
*/
Route::prefix('v1/mobile')->name('api.mobile.')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:6,1')
        ->name('auth.login');

    Route::middleware(['auth:sanctum', 'field', 'throttle:120,1'])->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::get('/bootstrap', [BootstrapController::class, 'show'])->name('bootstrap');

        Route::get('/work-feed', [WorkOrderController::class, 'index'])->name('work-feed');
        Route::get('/work-orders/{workOrder}', [WorkOrderController::class, 'show'])->name('work-orders.show');
        Route::post('/work-orders/{workOrder}/start', [WorkOrderController::class, 'start'])->name('work-orders.start');
        Route::post('/work-orders/{workOrder}/reports', [WorkOrderController::class, 'report'])->name('work-orders.reports');
        Route::post('/work-orders/{workOrder}/reschedules', [WorkOrderController::class, 'reschedule'])->name('work-orders.reschedules');
        Route::post('/work-orders/{workOrder}/escalations', [WorkOrderController::class, 'escalate'])->name('work-orders.escalations');
        Route::post('/work-orders/{workOrder}/issues', [WorkOrderController::class, 'issue'])->name('work-orders.issues');

        Route::get('/inspections', [InspectionController::class, 'index'])->name('inspections.index');
        Route::post('/inspections', [InspectionController::class, 'store'])->name('inspections.store');
    });
});
