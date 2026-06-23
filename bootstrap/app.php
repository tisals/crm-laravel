<?php

use App\Http\Middleware\ApiAccessLogger;
use App\Http\Middleware\ExtractTokenFromQuery;
use App\Http\Middleware\ThrottleMutations;
use App\Infrastructure\Auth\RbacMiddleware;
use App\Infrastructure\Auth\ValidateApiKeyMiddleware;
use App\Providers\EventServiceProvider;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            return '/login';
        });

        $middleware->alias([
            'api-key' => ValidateApiKeyMiddleware::class,
            'rbac' => RbacMiddleware::class,
            'api-logger' => ApiAccessLogger::class,
            'extract-token' => ExtractTokenFromQuery::class,
            'throttle-mutations' => ThrottleMutations::class,
        ]);

        // Agregar logger como middleware global para todas las rutas API.
        // HandleCors va primero (prepend) para que los preflight requests
        // se respondan antes de pasar por el resto del stack.
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
            ApiAccessLogger::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['success' => false, 'error' => 'No autenticado.'], 401);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['success' => false, 'error' => $e->getMessage() ?: 'Recurso no encontrado.'], 404);
            }
        });
    })
    ->withProviders([
        EventServiceProvider::class,
    ])->create();
