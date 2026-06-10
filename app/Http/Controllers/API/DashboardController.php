<?php

namespace App\Http\Controllers\API;

use App\Application\UseCases\Dashboard\GetDashboardUseCase;
use App\Http\Controllers\API\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct(
        private GetDashboardUseCase $dashboardUseCase,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $this->dashboardUseCase->execute(
            comercialId: $request->integer('comercial_id') ?: null,
            fechaInicio: $request->query('fecha_inicio'),
            fechaFin: $request->query('fecha_fin'),
            authUser: $request->user(),
        );

        return $this->successResponse($data);
    }
}
