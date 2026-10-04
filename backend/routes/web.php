<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\DashboardController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/admin/dashboard', [DashboardController::class, 'index'])->name('admin.dashboard');

use App\Http\Controllers\Admin\P0Controller;
Route::get('/admin/p0', [P0Controller::class, 'index'])->name('admin.p0.index');
Route::get('/admin/p0/{p0}', [P0Controller::class, 'show'])->name('admin.p0.show');

use App\Http\Controllers\Admin\WorkOrderController;
Route::get('/admin/work-orders', [WorkOrderController::class, 'index'])->name('admin.work-orders.index');
Route::get('/admin/work-orders/{workOrder}', [WorkOrderController::class, 'show'])->name('admin.work-orders.show');
