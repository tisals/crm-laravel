<?php

use App\Http\Controllers\API\ContactoAccionController;
use Illuminate\Support\Facades\Route;
use Modules\CRM\Http\Controllers\BulkMoveOportunidadesController;
use Modules\CRM\Http\Controllers\ContactoController;
use Modules\CRM\Http\Controllers\PipelineController;
use Modules\CRM\Http\Controllers\PipelineEtapaController;
use Modules\CRM\Http\Controllers\SailusWebhookController;

// Prefixed automatically with /api by RouteServiceProvider

Route::prefix('v1')->group(function () {
    // Webhooks & License
    Route::post('/webhook/registration', [SailusWebhookController::class, 'registration'])
        ->middleware('auth:sanctum')
        ->name('webhook.registration');

    Route::post('/webhook/purchase', [SailusWebhookController::class, 'purchase'])
        ->middleware('auth:sanctum')
        ->name('webhook.purchase');

    Route::post('/license/validate', [SailusWebhookController::class, 'validateLicense'])
        ->middleware('auth:sanctum')
        ->name('license.validate');

    // Protected CRM routes
    Route::middleware(['auth:sanctum', 'throttle-mutations'])->group(function () {
        Route::middleware('rbac')->group(function () {
            // Pipelines CRUD
            Route::get('/pipelines', [PipelineController::class, 'index'])->name('pipelines.index');
            Route::post('/pipelines', [PipelineController::class, 'store'])->name('pipelines.store');
            Route::get('/pipelines/{id}', [PipelineController::class, 'show'])->name('pipelines.show');
            Route::put('/pipelines/{id}', [PipelineController::class, 'update'])->name('pipelines.update');
            Route::delete('/pipelines/{id}', [PipelineController::class, 'destroy'])->name('pipelines.destroy');

            // Pipeline Etapas CRUD
            Route::get('/pipelines/{pipeline}/etapas', [PipelineEtapaController::class, 'index'])->name('pipelines.etapas.index');
            Route::post('/pipelines/{pipeline}/etapas', [PipelineEtapaController::class, 'store'])->name('pipelines.etapas.store');
            Route::get('/pipelines/etapas/{id}', [PipelineEtapaController::class, 'show'])->name('pipelines.etapas.show');
            Route::put('/pipelines/etapas/{id}', [PipelineEtapaController::class, 'update'])->name('pipelines.etapas.update');
            Route::delete('/pipelines/etapas/{id}', [PipelineEtapaController::class, 'destroy'])->name('pipelines.etapas.destroy');
            Route::put('/pipelines/{pipeline}/etapas/reorder', [PipelineEtapaController::class, 'reorder'])->name('pipelines.etapas.reorder');

            // Bulk move oportunidades
            Route::post('/oportunidades/bulk-move-pipeline', BulkMoveOportunidadesController::class)
                ->name('oportunidades.bulk-move');

            // Contact CRUD
            Route::get('/contacto', [ContactoController::class, 'index'])->name('contacto.index');
            Route::post('/contacto', [ContactoController::class, 'store'])->name('contacto.store');
            Route::get('/contacto/{id}', [ContactoController::class, 'show'])->name('contacto.show');
            Route::put('/contacto/{id}', [ContactoController::class, 'update'])->name('contacto.update');
            Route::delete('/contacto/{id}', [ContactoController::class, 'destroy'])->name('contacto.destroy');
            Route::post('/contacto/{contactoId}/acciones', [ContactoAccionController::class, 'acciones'])->name('contacto.acciones');
        });
    });
});
