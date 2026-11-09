<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ApiResponse;

    public function analytics(DashboardService $service)
    {
        return $this->success($service->analytics());
    }

    public function trend(DashboardService $service)
    {
        return $this->success($service->trend());
    }
}
