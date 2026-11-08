<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DashboardInsightsService;
use App\Services\DashboardService;
use App\Support\QueryInput;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly DashboardInsightsService $insightsService,
    ) {}

    public function index(Request $request)
    {
        $data = $this->dashboardService->getDashboardData(
            QueryInput::choice($request, 'period', array_keys(DashboardService::PERIODS), 'today'),
        );
        $data['insights'] = $this->insightsService->get(
            QueryInput::choice($request, 'trend_period', array_keys(DashboardInsightsService::PERIODS), '30d'),
        );

        if ($request->wantsJson()) {
            return response()->json($data);
        }

        return view('admin.dashboard', $data);
    }
}