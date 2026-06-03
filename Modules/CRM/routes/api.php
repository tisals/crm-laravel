<?php

use Illuminate\Support\Facades\Route;
use Modules\CRM\Http\Controllers\SailusWebhookController;
use Modules\CRM\Http\Controllers\ContactoController;

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
            // Contact CRUD
            Route::get('/contacto', [ContactoController::class, 'index'])->name('contacto.index');
            Route::post('/contacto', [ContactoController::class, 'store'])->name('contacto.store');
            Route::get('/contacto/{id}', [ContactoController::class, 'show'])->name('contacto.show');
            Route::put('/contacto/{id}', [ContactoController::class, 'update'])->name('contacto.update');
            Route::delete('/contacto/{id}', [ContactoController::class, 'destroy'])->name('contacto.destroy');
            Route::post('/contacto/{contactoId}/acciones', [\App\Http\Controllers\API\ContactoAccionController::class, 'acciones'])->name('contacto.acciones');
        });
    });
});
