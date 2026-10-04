<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\P0Service;
use Illuminate\Http\Request;

class P0Controller extends Controller
{
    protected $p0Service;

    public function __construct(P0Service $p0Service)
    {
        $this->p0Service = $p0Service;
    }

    public function index()
    {
        $data = $this->p0Service->getDashboardData();
        return view('admin.p0.index', $data);
    }

    public function show($p0)
    {
        // P0 domain doesn't exist, so any P0 requested is not found
        return response()->view('admin.p0.show', ['p0_id' => $p0, 'not_found' => true], 404);
    }
}
