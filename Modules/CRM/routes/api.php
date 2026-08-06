<?php

use App\Http\Controllers\API\ContactoAccionController;
use App\Http\Controllers\API\MeController;
use App\Http\Controllers\API\UsuarioPermisoController;
use Illuminate\Support\Facades\Route;
use Modules\CRM\Http\Controllers\AppController;
use Modules\CRM\Http\Controllers\BulkMoveOportunidadesController;
use Modules\CRM\Http\Controllers\ContactoController;
use Modules\CRM\Http\Controllers\PersonaController;
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

    // Self-service endpoints (auth:sanctum, throttle:api). "me/apps" lets
    // the authenticated user list which apps they have access to
    // (transitively via entidad_usuario + app_entidad).
    Route::middleware(['auth:sanctum', 'throttle:api'])->prefix('me')->group(function () {
        Route::get('/apps', [MeController::class, 'apps'])->name('me.apps');
        Route::get('/apps/{slug}/permisos', [MeController::class, 'appPermisos'])->name('me.apps.permisos');
        Route::get('/identity', [MeController::class, 'identity'])->name('me.identity');
        Route::get('/permisos', [MeController::class, 'permisos'])->name('me.permisos');
    });

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
            Route::post('/contacto/{id}/reasignar', [ContactoController::class, 'reasignar'])->name('contacto.reasignar');
            Route::post('/contacto/{contactoId}/acciones', [ContactoAccionController::class, 'acciones'])->name('contacto.acciones');

            // Persona CRUD (Party Model — administrative full CRUD)
            Route::get('/personas', [PersonaController::class, 'index'])->name('personas.index');
            Route::post('/personas', [PersonaController::class, 'store'])->name('personas.store');
            Route::get('/personas/{id}', [PersonaController::class, 'show'])->name('personas.show');
            Route::put('/personas/{id}', [PersonaController::class, 'update'])->name('personas.update');
            Route::delete('/personas/{id}', [PersonaController::class, 'destroy'])->name('personas.destroy');

            // Apps catalog CRUD
            Route::get('/apps', [AppController::class, 'index'])->name('apps.index');
            Route::post('/apps', [AppController::class, 'store'])->name('apps.store');
            Route::get('/apps/{id}', [AppController::class, 'show'])->name('apps.show');
            Route::put('/apps/{id}', [AppController::class, 'update'])->name('apps.update');
            Route::delete('/apps/{id}', [AppController::class, 'destroy'])->name('apps.destroy');
            Route::get('/apps/{appId}/entidades', [AppController::class, 'entidadesByApp'])->name('apps.entidades');

            // Apps ↔ Entidad assignments
            Route::get('/entidad/{entidadId}/apps', [AppController::class, 'appsByEntidad'])->name('entidad.apps.index');
            Route::post('/entidad/{entidadId}/apps/{appId}', [AppController::class, 'assignAppToEntidad'])->name('entidad.apps.assign');
            Route::delete('/entidad/{entidadId}/apps/{appId}', [AppController::class, 'removeAppFromEntidad'])->name('entidad.apps.remove');

            // Admin: granular per-(user, app) permissions
            Route::prefix('usuarios/{userId}/apps/{appId}/permisos')->group(function () {
                Route::get('/', [UsuarioPermisoController::class, 'index'])->name('usuarios.apps.permisos.index');
                Route::post('/', [UsuarioPermisoController::class, 'sync'])->name('usuarios.apps.permisos.store');
                Route::post('/grant', [UsuarioPermisoController::class, 'grant'])->name('usuarios.apps.permisos.grant');
                Route::post('/reset-to-role-defaults', [UsuarioPermisoController::class, 'resetToRoleDefaults'])->name('usuarios.apps.permisos.reset');
                Route::delete('/{vista}', [UsuarioPermisoController::class, 'destroy'])->name('usuarios.apps.permisos.destroy');
            });
        });
    });
});
