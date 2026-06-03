<?php

use Illuminate\Support\Facades\Route;
use Modules\Administrativo\Http\Controllers\AdministrativoController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('administrativos', AdministrativoController::class)->names('administrativo');
});
