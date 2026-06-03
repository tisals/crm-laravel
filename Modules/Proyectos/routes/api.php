<?php

use Illuminate\Support\Facades\Route;
use Modules\Proyectos\Http\Controllers\ProyectosController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('proyectos', ProyectosController::class)->names('proyectos');
});
