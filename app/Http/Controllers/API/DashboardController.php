<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\Dashboard\GetDashboardUseCase;
use App\Http\Controllers\Controller;
use App\Http\Controllers\API\Concerns\ApiResponse;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct(
        private GetDashboardUseCase $dashboardUseCase,
    ) {}

    public function index(): JsonResponse
    {
        $data = $this->dashboardUseCase->execute();

        return $this->successResponse($data);
    }
}
