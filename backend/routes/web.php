<?php

use App\Http\Controllers\Admin\AccountSettingsController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\ApkukController;
use App\Http\Controllers\Admin\ApkukReconciliationController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\HarsusController;
use App\Http\Controllers\Admin\InspectionController;
use App\Http\Controllers\Admin\InspectionFullQuotaPreviewController;
use App\Http\Controllers\Admin\MapDataController;
use App\Http\Controllers\Admin\MasterDataController;
use App\Http\Controllers\Admin\MasterDataManageController;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\Admin\P0Controller;
use App\Http\Controllers\Admin\PasilaController;
use App\Http\Controllers\Admin\ReviewController;
use App\Http\Controllers\Admin\RowController;
use App\Http\Controllers\Admin\RowPlanController;
use App\Http\Controllers\Admin\ShiftQuotaController;
use App\Http\Controllers\Admin\SpecialCaseController;
use App\Http\Controllers\Admin\TeamWorkOrderController;
use App\Http\Controllers\Admin\UserAccessController;
use App\Http\Controllers\Admin\WorkOrderActionController;
use App\Http\Controllers\Admin\WorkOrderController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// Parameter model UUID (HasUuids) menolak nilai bukan UUID dengan 404 saat binding, setelah
// middleware auth/admin berjalan; tamu tetap diarahkan ke login dan non-admin tetap 403.
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

        // P0 POSKO: monitoring, ACC/tolak usulan SYSTEM. P0 manual dibuat dari sesi PASILA (route PASILA).
        Route::get('/p0', [P0Controller::class, 'index'])
            ->name('p0.index');

        Route::get('/p0/{p0Item}', [P0Controller::class, 'show'])
            ->name('p0.show');
        Route::post('/p0/{p0Item}/acc', [P0Controller::class, 'approve'])->name('p0.approve');
        Route::post('/p0/{p0Item}/tolak', [P0Controller::class, 'reject'])->name('p0.reject');

        // Antrean rekonsiliasi APKUK (laporan yang tidak lagi ada di APKUK_RAW)
        Route::get('/apkuk-rekonsiliasi', [ApkukReconciliationController::class, 'index'])
            ->name('apkuk-reconciliation.index');

        Route::post('/apkuk-rekonsiliasi/{item}/selesaikan', [ApkukReconciliationController::class, 'resolve'])
            ->where('item', '[0-9]{1,18}')
            ->name('apkuk-reconciliation.resolve');

        Route::post('/apkuk-rekonsiliasi/konfirmasi-hapus', [ApkukReconciliationController::class, 'resolveMany'])
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

        // Koreksi data/jadwal dan pencatatan atas nama tim lapangan.
        Route::get('/work-orders/{workOrder}/edit', [WorkOrderActionController::class, 'edit'])->name('work-orders.edit');
        Route::put('/work-orders/{workOrder}', [WorkOrderActionController::class, 'update'])->name('work-orders.update');
        Route::post('/work-orders/{workOrder}/start', [WorkOrderActionController::class, 'start'])->name('work-orders.start');
        Route::post('/work-orders/{workOrder}/reports', [WorkOrderActionController::class, 'report'])->name('work-orders.reports');
        Route::post('/work-orders/{workOrder}/escalations', [WorkOrderActionController::class, 'escalate'])->name('work-orders.escalations');
        Route::post('/work-orders/{workOrder}/issues', [WorkOrderActionController::class, 'issue'])->name('work-orders.issues');

        // Foto bukti dari disk privat.
        Route::get('/media/work-orders/{media}', [MediaController::class, 'workOrder'])->name('media.work-orders');
        Route::get('/media/inspections/{media}', [MediaController::class, 'inspection'])->name('media.inspections');
        Route::get('/media/pasila/{media}', [MediaController::class, 'pasila'])->whereUuid('media')->name('media.pasila');

        // APKUK: laporan sumber aktif, histori, kebutuhan, dan progres pemasangan.
        Route::get('/apkuk', [ApkukController::class, 'index'])->name('apkuk.index');
        Route::get('/apkuk/{apkuk}', [ApkukController::class, 'show'])->where('apkuk', '[0-9]{1,18}')->name('apkuk.show');
        Route::post('/apkuk/{apkuk}/section', [ApkukController::class, 'updateSection'])->where('apkuk', '[0-9]{1,18}')->name('apkuk.section.update');

        // Kuota 6 WO APKUK per Posko per shift: monitoring, pratinjau, dan generate (terkunci pengaman aktivasi).
        Route::get('/kuota-shift', [ShiftQuotaController::class, 'index'])->name('shift-quota.index');
        // V6B1: read-only INSPEKSI FULL candidate preview (never invokes legacy generator).
        Route::get('/kuota-shift/inspeksi-full', [InspectionFullQuotaPreviewController::class, 'index'])->name('shift-quota.inspection-full');
        Route::post('/kuota-shift/eksekusi', [ShiftQuotaController::class, 'run'])->name('shift-quota.run');

        // Sesi PASILA per Posko per shift: monitor, buka sesi shift berjalan (terkunci flag), tetapkan Section.
        Route::get('/pasila', [PasilaController::class, 'index'])->name('pasila.index');
        Route::post('/pasila/buka-shift', [PasilaController::class, 'open'])->name('pasila.open');
        Route::post('/pasila/sesi/{pasilaSession}/section', [PasilaController::class, 'assignSection'])->whereUuid('pasilaSession')->name('pasila.section');
        Route::post('/pasila/sesi/{pasilaSession}/p0', [P0Controller::class, 'store'])->whereUuid('pasilaSession')->name('pasila.p0.store');
        Route::get('/pasila/{posko}', [PasilaController::class, 'show'])->name('pasila.show');

        Route::get('/inspeksi', [InspectionController::class, 'index'])->name('inspeksi.index');
        Route::get('/inspeksi/create', [InspectionController::class, 'create'])->name('inspeksi.create');
        Route::post('/inspeksi', [InspectionController::class, 'store'])->name('inspeksi.store');
        Route::get('/inspeksi/{inspection}', [InspectionController::class, 'show'])->name('inspeksi.show');
        Route::post('/inspeksi/temuan/{finding}/approve', [InspectionController::class, 'approveFinding'])->name('inspeksi.findings.approve');
        Route::post('/inspeksi/temuan/{finding}/reject', [InspectionController::class, 'rejectFinding'])->name('inspeksi.findings.reject');
        Route::post('/inspeksi/temuan/{finding}/work-order', [InspectionController::class, 'createApprovedFindingWorkOrder'])->name('inspeksi.findings.work-order');
        Route::post('/inspeksi/temuan/{finding}/posko-work-order', [InspectionController::class, 'createApprovedPoskoWorkOrder'])->name('inspeksi.findings.posko-work-order');

        Route::get('/row', [RowController::class, 'index'])->name('row.index');
        // V10: penugasan ROW eksplisit oleh Admin; tidak membuat WO dan tidak memanggil generator.
        Route::get('/row/perencanaan', [RowPlanController::class, 'index'])->name('row.plan');
        Route::get('/row/perencanaan/pratinjau', [RowPlanController::class, 'preview'])->name('row.plan.preview');
        Route::post('/row/perencanaan/{finding}', [RowPlanController::class, 'store'])->whereUuid('finding')->name('row.plan.store');
        Route::get('/harsus', [HarsusController::class, 'index'])->name('harsus.index');
        Route::get('/{team}/create', [TeamWorkOrderController::class, 'create'])->whereIn('team', ['row', 'harsus'])->name('team-work-orders.create');
        Route::post('/{team}', [TeamWorkOrderController::class, 'store'])->whereIn('team', ['row', 'harsus'])->name('team-work-orders.store');

        Route::get('/review', [ReviewController::class, 'index'])->name('reviews.index');
        Route::post('/review/laporan/{report}/approve', [ReviewController::class, 'approveReport'])->name('reviews.reports.approve');
        Route::post('/review/laporan/{report}/reject', [ReviewController::class, 'rejectReport'])->name('reviews.reports.reject');
        Route::post('/review/reschedule/{reschedule}/approve', [ReviewController::class, 'approveReschedule'])->name('reviews.reschedules.approve');
        Route::post('/review/reschedule/{reschedule}/reject', [ReviewController::class, 'rejectReschedule'])->name('reviews.reschedules.reject');
        Route::post('/review/limpahan/{escalation}/approve', [ReviewController::class, 'approveEscalation'])->name('reviews.escalations.approve');
        Route::post('/review/limpahan/{escalation}/reject', [ReviewController::class, 'rejectEscalation'])->name('reviews.escalations.reject');

        Route::get('/special-case', [SpecialCaseController::class, 'index'])->name('special-case.index');
        Route::get('/special-case/detail', [SpecialCaseController::class, 'show'])->name('special-case.show');
        Route::post('/special-case/kendala/{issue}/resolve', [SpecialCaseController::class, 'resolveIssue'])->name('special-case.issues.resolve');

        Route::get('/master-data', [MasterDataController::class, 'index'])->name('master-data.index');
        Route::get('/master-data/posko/{posko}', [MasterDataController::class, 'showPosko'])->name('master-data.posko.show');
        Route::get('/master-data/penyulang/{penyulang}', [MasterDataController::class, 'showPenyulang'])->name('master-data.penyulang.show');
        Route::get('/master-data/{type}/create', [MasterDataManageController::class, 'create'])->whereIn('type', array_keys(MasterDataManageController::TYPES))->name('master-data.manage.create');
        Route::post('/master-data/{type}', [MasterDataManageController::class, 'store'])->whereIn('type', array_keys(MasterDataManageController::TYPES))->name('master-data.manage.store');
        Route::get('/master-data/{type}/{id}/edit', [MasterDataManageController::class, 'edit'])->whereIn('type', array_keys(MasterDataManageController::TYPES))->name('master-data.manage.edit');
        Route::put('/master-data/{type}/{id}', [MasterDataManageController::class, 'update'])->whereIn('type', array_keys(MasterDataManageController::TYPES))->name('master-data.manage.update');
        Route::delete('/master-data/{type}/{id}', [MasterDataManageController::class, 'destroy'])->whereIn('type', array_keys(MasterDataManageController::TYPES))->name('master-data.manage.destroy');

        Route::get('/analitik', [AnalyticsController::class, 'index'])->name('analytics.index');
        Route::get('/analitik/export', [AnalyticsController::class, 'export'])->name('analytics.export');

        Route::get('/users', [UserAccessController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserAccessController::class, 'create'])->name('users.create');
        Route::post('/users', [UserAccessController::class, 'store'])->name('users.store');
        Route::get('/users/{user}', [UserAccessController::class, 'show'])->where('user', '[0-9]{1,18}')->name('users.show');
        Route::get('/users/{user}/edit', [UserAccessController::class, 'edit'])->where('user', '[0-9]{1,18}')->name('users.edit');
        Route::put('/users/{user}', [UserAccessController::class, 'update'])->where('user', '[0-9]{1,18}')->name('users.update');
        Route::post('/users/{user}/activation', [UserAccessController::class, 'activation'])->where('user', '[0-9]{1,18}')->name('users.activation');
        Route::post('/users/{user}/password', [UserAccessController::class, 'password'])->where('user', '[0-9]{1,18}')->name('users.password');

        Route::get('/audit-log', [AuditLogController::class, 'index'])->name('audit-logs.index');
        Route::get('/audit-log/umum', [AuditLogController::class, 'general'])->name('audit-logs.general');
        Route::get('/audit-log/umum/{auditLog}', [AuditLogController::class, 'showGeneral'])->where('auditLog', '[0-9]{1,18}')->name('audit-logs.general.show');
        Route::get('/audit-log/{auditLog}', [AuditLogController::class, 'show'])->where('auditLog', '[0-9]{1,18}')->name('audit-logs.show');

        // Pengaturan Akun
        Route::get('/account', [AccountSettingsController::class, 'edit'])
            ->name('account.edit');

        Route::patch('/account/username', [AccountSettingsController::class, 'updateUsername'])
            ->name('account.username.update');

        Route::put('/account/password', [AccountSettingsController::class, 'updatePassword'])
            ->name('account.password.update');
    });
