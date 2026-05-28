<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\RolController;
use App\Http\Controllers\API\PermisoController;
use App\Http\Controllers\API\UsuarioController;
use App\Http\Controllers\API\CiudadController;
use App\Http\Controllers\API\MaestroController;
use App\Http\Controllers\API\ProductoController;
use App\Http\Controllers\API\EtiquetaController;
use App\Http\Controllers\API\EntidadController;
use App\Http\Controllers\API\ContactoController;
use App\Http\Controllers\API\LugarEntidadController;
use App\Http\Controllers\API\ColaboradorController;
use App\Http\Controllers\API\ProveedorController;
use App\Http\Controllers\API\OportunidadController;
use App\Http\Controllers\API\DetalleOportunidadController;
use App\Http\Controllers\API\SeguimientoController;
use App\Http\Controllers\API\ServicioController;
use App\Http\Controllers\API\DetalleServicioController;
use App\Http\Controllers\API\OrdenServicioController;
use App\Http\Controllers\API\CuentaController;
use App\Http\Controllers\API\DashboardController;
use App\Http\Controllers\API\MovimientoController;
use App\Http\Controllers\API\BrandPermissionController;
use App\Http\Controllers\API\ContactoAccionController;
use App\Http\Controllers\API\CotizacionController;
use App\Http\Controllers\API\PlanController;
use App\Http\Controllers\API\SailusEntidadController;
use App\Http\Controllers\API\SailusWebhookController;
use App\Infrastructure\Auth\ValidateApiKeyMiddleware;

Route::prefix('v1')->group(function () {
    // Public endpoints
    Route::get('/health', fn () => response()->json(['status' => 'ok']));
    Route::get('/plans', [PlanController::class, 'index']);
    Route::post('/webhook/registration', [SailusWebhookController::class, 'registration'])
        ->middleware('auth:sanctum')
        ->name('webhook.registration');

    Route::post('/webhook/purchase', [SailusWebhookController::class, 'purchase'])
        ->middleware('auth:sanctum')
        ->name('webhook.purchase');

    Route::post('/license/validate', [SailusWebhookController::class, 'validateLicense'])
        ->middleware('auth:sanctum')
        ->name('license.validate');

    Route::put('/services/{id}/renew', [ServicioController::class, 'renew'])
        ->middleware('auth:sanctum')
        ->name('services.renew');

    // Auth (con rate limiting específico para login)
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:auth')
        ->name('auth.login');

    // Password reset (public, rate limited)
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:5,10')
        ->name('password.forgot');

    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:5,10')
        ->name('password.reset');

    // API Key validation (uses X-API-Key header, not Sanctum)
    Route::get('/auth/validate-key', [AuthController::class, 'validateKey'])
        ->middleware(ValidateApiKeyMiddleware::class)
        ->name('auth.validate-key');

    // Brands endpoint (consumido por SAIlus)
    Route::get('/users/{id}/brands', [BrandPermissionController::class, 'index'])
        ->middleware(['auth:sanctum', 'throttle:api'])
        ->name('users.brands');

    // Protected endpoints (require Sanctum token)
    // Rate limiting: mutations (POST/PUT/DELETE) → throttle:api; GETs → sin throttle
    Route::middleware(['auth:sanctum', 'throttle-mutations'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        // Dashboard (sin RBAC — todos los usuarios autenticados)
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.index');

        // Roles (with RBAC)
        Route::middleware('rbac')->group(function () {
            Route::get('/roles', [RolController::class, 'index'])->name('roles.index');
            Route::post('/roles', [RolController::class, 'store'])->name('roles.store');
            Route::get('/roles/{id}', [RolController::class, 'show'])->name('roles.show');
            Route::put('/roles/{id}', [RolController::class, 'update'])->name('roles.update');
            Route::delete('/roles/{id}', [RolController::class, 'destroy'])->name('roles.destroy');
        });

        // Permisos
        Route::middleware('rbac')->group(function () {
            Route::get('/permisos', [PermisoController::class, 'index'])->name('permisos.index');
            Route::post('/permisos', [PermisoController::class, 'store'])->name('permisos.store');
            Route::get('/permisos/{id}', [PermisoController::class, 'show'])->name('permisos.show');
            Route::put('/permisos/{id}', [PermisoController::class, 'update'])->name('permisos.update');
            Route::delete('/permisos/{id}', [PermisoController::class, 'destroy'])->name('permisos.destroy');
        });

        // Usuarios
        Route::middleware('rbac')->group(function () {
            Route::get('/usuarios', [UsuarioController::class, 'index'])->name('usuarios.index');
            Route::post('/usuarios', [UsuarioController::class, 'store'])->name('usuarios.store');
            Route::get('/usuarios/{id}', [UsuarioController::class, 'show'])->name('usuarios.show');
            Route::put('/usuarios/{id}', [UsuarioController::class, 'update'])->name('usuarios.update');
            Route::delete('/usuarios/{id}', [UsuarioController::class, 'destroy'])->name('usuarios.destroy');
            Route::put('/usuarios/{id}/status', [UsuarioController::class, 'toggleStatus'])->name('usuarios.toggle-status');
        });

        // Seguridad Dashboard
        Route::middleware('rbac')->group(function () {
            Route::get('/seguridad/dashboard', [\App\Http\Controllers\API\SecurityDashboardController::class, 'index'])
                ->name('seguridad.dashboard');
        });

        // Ciudades
        Route::middleware('rbac')->group(function () {
            Route::get('/ciudades', [CiudadController::class, 'index'])->name('ciudades.index');
            Route::post('/ciudades', [CiudadController::class, 'store'])->name('ciudades.store');
            Route::get('/ciudades/{cod_municipio}', [CiudadController::class, 'show'])->name('ciudades.show');
            Route::put('/ciudades/{cod_municipio}', [CiudadController::class, 'update'])->name('ciudades.update');
            Route::delete('/ciudades/{cod_municipio}', [CiudadController::class, 'destroy'])->name('ciudades.destroy');
        });

        // Maestros
        Route::middleware('rbac')->group(function () {
            Route::get('/maestros', [MaestroController::class, 'index'])->name('maestros.index');
            Route::post('/maestros', [MaestroController::class, 'store'])->name('maestros.store');
            Route::get('/maestros/{id}', [MaestroController::class, 'show'])->name('maestros.show');
            Route::put('/maestros/{id}', [MaestroController::class, 'update'])->name('maestros.update');
            Route::delete('/maestros/{id}', [MaestroController::class, 'destroy'])->name('maestros.destroy');
        });

        // Productos
        Route::middleware('rbac')->group(function () {
            Route::get('/productos', [ProductoController::class, 'index'])->name('productos.index');
            Route::post('/productos', [ProductoController::class, 'store'])->name('productos.store');
            Route::get('/productos/{id}', [ProductoController::class, 'show'])->name('productos.show');
            Route::put('/productos/{id}', [ProductoController::class, 'update'])->name('productos.update');
            Route::delete('/productos/{id}', [ProductoController::class, 'destroy'])->name('productos.destroy');
        });

        // Etiquetas
        Route::middleware('rbac')->group(function () {
            Route::get('/etiquetas', [EtiquetaController::class, 'index'])->name('etiquetas.index');
            Route::post('/etiquetas', [EtiquetaController::class, 'store'])->name('etiquetas.store');
            Route::get('/etiquetas/{id}', [EtiquetaController::class, 'show'])->name('etiquetas.show');
            Route::put('/etiquetas/{id}', [EtiquetaController::class, 'update'])->name('etiquetas.update');
            Route::delete('/etiquetas/{id}', [EtiquetaController::class, 'destroy'])->name('etiquetas.destroy');
        });

        // Entidad (Directorio Empresarial)
        Route::middleware('rbac')->group(function () {
            Route::get('/entidad', [EntidadController::class, 'index'])->name('entidad.index');
            Route::post('/entidad', [EntidadController::class, 'store'])->name('entidad.store');
            Route::get('/entidad/{id}', [EntidadController::class, 'show'])->name('entidad.show');
            Route::put('/entidad/{id}', [EntidadController::class, 'update'])->name('entidad.update');
            Route::delete('/entidad/{id}', [EntidadController::class, 'destroy'])->name('entidad.destroy');
        });

        // Contacto
        Route::middleware('rbac')->group(function () {
            Route::get('/contacto', [ContactoController::class, 'index'])->name('contacto.index');
            Route::post('/contacto', [ContactoController::class, 'store'])->name('contacto.store');
            Route::get('/contacto/{id}', [ContactoController::class, 'show'])->name('contacto.show');
            Route::put('/contacto/{id}', [ContactoController::class, 'update'])->name('contacto.update');
            Route::delete('/contacto/{id}', [ContactoController::class, 'destroy'])->name('contacto.destroy');
            Route::post('/contacto/{contactoId}/acciones', [ContactoAccionController::class, 'acciones'])->name('contacto.acciones');
        });

        // Lugares Entidad (nested under entidad)
        Route::middleware('rbac')->group(function () {
            Route::get('/entidad/{entidadId}/lugares', [LugarEntidadController::class, 'index'])->name('entidad.lugares.index');
            Route::post('/entidad/{entidadId}/lugares', [LugarEntidadController::class, 'store'])->name('entidad.lugares.store');
            Route::get('/entidad/{entidadId}/lugares/{id}', [LugarEntidadController::class, 'show'])->name('entidad.lugares.show');
            Route::put('/entidad/{entidadId}/lugares/{id}', [LugarEntidadController::class, 'update'])->name('entidad.lugares.update');
            Route::delete('/entidad/{entidadId}/lugares/{id}', [LugarEntidadController::class, 'destroy'])->name('entidad.lugares.destroy');
        });

        // Colaboradores
        Route::middleware('rbac')->group(function () {
            Route::get('/colaboradores', [ColaboradorController::class, 'index'])->name('colaboradores.index');
            Route::post('/colaboradores', [ColaboradorController::class, 'store'])->name('colaboradores.store');
            Route::get('/colaboradores/{id}', [ColaboradorController::class, 'show'])->name('colaboradores.show');
            Route::put('/colaboradores/{id}', [ColaboradorController::class, 'update'])->name('colaboradores.update');
            Route::delete('/colaboradores/{id}', [ColaboradorController::class, 'destroy'])->name('colaboradores.destroy');
        });

        // Proveedores
        Route::middleware('rbac')->group(function () {
            Route::get('/proveedores', [ProveedorController::class, 'index'])->name('proveedores.index');
            Route::post('/proveedores', [ProveedorController::class, 'store'])->name('proveedores.store');
            Route::get('/proveedores/{id}', [ProveedorController::class, 'show'])->name('proveedores.show');
            Route::put('/proveedores/{id}', [ProveedorController::class, 'update'])->name('proveedores.update');
            Route::delete('/proveedores/{id}', [ProveedorController::class, 'destroy'])->name('proveedores.destroy');
        });

        // Oportunidad (CRM)
        Route::middleware('rbac')->group(function () {
            Route::get('/oportunidades', [OportunidadController::class, 'index'])->name('oportunidades.index');
            Route::post('/oportunidades', [OportunidadController::class, 'store'])->name('oportunidades.store');
            Route::get('/oportunidades/{id}', [OportunidadController::class, 'show'])->name('oportunidades.show');
            Route::put('/oportunidades/{id}', [OportunidadController::class, 'update'])->name('oportunidades.update');
            Route::delete('/oportunidades/{id}', [OportunidadController::class, 'destroy'])->name('oportunidades.destroy');
            Route::post('/oportunidades/{id}/ganar', [OportunidadController::class, 'ganar'])->name('oportunidades.ganar');
            Route::post('/oportunidades/{id}/clonar', [OportunidadController::class, 'clonar'])->name('oportunidades.clonar');
        });

        // Detalle Oportunidad (nested under oportunidad + direct)
        Route::middleware('rbac')->group(function () {
            Route::get('/oportunidades/{oportunidadId}/detalles', [DetalleOportunidadController::class, 'index'])->name('oportunidades.detalles.index');
            Route::post('/oportunidades/{oportunidadId}/detalles', [DetalleOportunidadController::class, 'store'])->name('oportunidades.detalles.store');
            Route::get('/detalles-oportunidad/{id}', [DetalleOportunidadController::class, 'show'])->name('detalles-oportunidad.show');
            Route::put('/detalles-oportunidad/{id}', [DetalleOportunidadController::class, 'update'])->name('detalles-oportunidad.update');
            Route::delete('/detalles-oportunidad/{id}', [DetalleOportunidadController::class, 'destroy'])->name('detalles-oportunidad.destroy');
        });

        // Cotización (PDF generation and approval)
        Route::get('/oportunidades/{id}/cotizacion-data', [CotizacionController::class, 'data']);
        Route::post('/oportunidades/{id}/aprobar', [CotizacionController::class, 'aprobar']);
        Route::post('/oportunidades/{id}/enviar', [CotizacionController::class, 'enviar']);
        Route::get('/oportunidades/{id}/pdf', [CotizacionController::class, 'pdf']);

        // ICS exports — MUST be before {id} routes, otherwise calendar.ics matches {id}
        Route::middleware(['auth:sanctum', 'extract-token'])->group(function () {
            Route::get('/seguimientos/{id}/ics', [SeguimientoController::class, 'exportIcs'])->name('seguimientos.ics');
            Route::get('/seguimientos/calendar.ics', [SeguimientoController::class, 'exportCalendarIcs'])->name('seguimientos.calendar');
        });

        // Seguimiento CRUD
        Route::middleware('rbac')->group(function () {
            Route::get('/seguimientos', [SeguimientoController::class, 'index'])->name('seguimientos.index');
            Route::post('/seguimientos', [SeguimientoController::class, 'store'])->name('seguimientos.store');
            Route::get('/seguimientos/{id}', [SeguimientoController::class, 'show'])->name('seguimientos.show');
            Route::put('/seguimientos/{id}', [SeguimientoController::class, 'update'])->name('seguimientos.update');
            Route::delete('/seguimientos/{id}', [SeguimientoController::class, 'destroy'])->name('seguimientos.destroy');
        });

        // Seguimiento filters by related entity
        Route::middleware('rbac')->group(function () {
            Route::get('/oportunidades/{oportunidadId}/seguimientos', [SeguimientoController::class, 'index'])->name('oportunidades.seguimientos.index');
            Route::get('/contactos/{contactoId}/seguimientos', [SeguimientoController::class, 'index'])->name('contactos.seguimientos.index');
            Route::get('/entidades/{entidadId}/seguimientos', [SeguimientoController::class, 'index'])->name('entidades.seguimientos.index');
        });

        // ===== Phase 4: Operaciones + Finanzas =====

        // Servicios
        Route::middleware('rbac')->group(function () {
            Route::get('/servicios', [ServicioController::class, 'index'])->name('servicios.index');
            Route::post('/servicios', [ServicioController::class, 'store'])->name('servicios.store');
            Route::get('/servicios/{id}', [ServicioController::class, 'show'])->name('servicios.show');
            Route::put('/servicios/{id}', [ServicioController::class, 'update'])->name('servicios.update');
            Route::delete('/servicios/{id}', [ServicioController::class, 'destroy'])->name('servicios.destroy');
        });

        // Servicios filtered by oportunidad
        Route::middleware('rbac')->group(function () {
            Route::get('/oportunidades/{oportunidad}/servicios', [ServicioController::class, 'index'])->name('oportunidades.servicios.index');
        });

        // Detalle Servicios (nested under servicios + direct)
        Route::middleware('rbac')->group(function () {
            Route::get('/servicios/{servicioId}/detalles', [DetalleServicioController::class, 'index'])->name('servicios.detalles.index');
            Route::post('/servicios/{servicioId}/detalles', [DetalleServicioController::class, 'store'])->name('servicios.detalles.store');
            Route::get('/detalles-servicio/{id}', [DetalleServicioController::class, 'show'])->name('detalles-servicio.show');
            Route::put('/detalles-servicio/{id}', [DetalleServicioController::class, 'update'])->name('detalles-servicio.update');
            Route::delete('/detalles-servicio/{id}', [DetalleServicioController::class, 'destroy'])->name('detalles-servicio.destroy');
        });

        // Ordenes de Servicio
        Route::middleware('rbac')->group(function () {
            Route::get('/ordenes-servicio', [OrdenServicioController::class, 'index'])->name('ordenes-servicio.index');
            Route::post('/ordenes-servicio', [OrdenServicioController::class, 'store'])->name('ordenes-servicio.store');
            Route::get('/ordenes-servicio/{id}', [OrdenServicioController::class, 'show'])->name('ordenes-servicio.show');
            Route::put('/ordenes-servicio/{id}', [OrdenServicioController::class, 'update'])->name('ordenes-servicio.update');
            Route::delete('/ordenes-servicio/{id}', [OrdenServicioController::class, 'destroy'])->name('ordenes-servicio.destroy');
        });

        // Cuentas
        Route::middleware('rbac')->group(function () {
            Route::get('/cuentas', [CuentaController::class, 'index'])->name('cuentas.index');
            Route::post('/cuentas', [CuentaController::class, 'store'])->name('cuentas.store');
            Route::get('/cuentas/{id}', [CuentaController::class, 'show'])->name('cuentas.show');
            Route::put('/cuentas/{id}', [CuentaController::class, 'update'])->name('cuentas.update');
            Route::delete('/cuentas/{id}', [CuentaController::class, 'destroy'])->name('cuentas.destroy');
        });

        // Cuentas by proveedor
        Route::middleware('rbac')->group(function () {
            Route::get('/proveedores/{proveedor}/cuentas', [CuentaController::class, 'index'])->name('proveedores.cuentas.index');
        });

        // Sailus integration - Entidad by ID
        Route::get('/sailus/entidad/{id}', [SailusEntidadController::class, 'show']);

        // Servicios by entidad
        Route::get('/servicios/entidad/{entidadId}', [ServicioController::class, 'byEntidad']);

        // Movimientos
        Route::middleware('rbac')->group(function () {
            Route::get('/movimientos', [MovimientoController::class, 'index'])->name('movimientos.index');
            Route::post('/movimientos', [MovimientoController::class, 'store'])->name('movimientos.store');
            Route::get('/movimientos/{id}', [MovimientoController::class, 'show'])->name('movimientos.show');
            Route::put('/movimientos/{id}', [MovimientoController::class, 'update'])->name('movimientos.update');
            Route::delete('/movimientos/{id}', [MovimientoController::class, 'destroy'])->name('movimientos.destroy');
        });
    });
});
