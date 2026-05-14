<?php

namespace App\Providers;

use App\Domain\Repositories\RolRepositoryInterface;
use App\Domain\Repositories\PermisoRepositoryInterface;
use App\Domain\Repositories\UsuarioRepositoryInterface;
use App\Domain\Repositories\CiudadRepositoryInterface;
use App\Domain\Repositories\ProductoRepositoryInterface;
use App\Domain\Repositories\EtiquetaRepositoryInterface;
use App\Domain\Repositories\EntidadRepositoryInterface;
use App\Domain\Repositories\ContactoRepositoryInterface;
use App\Domain\Repositories\LugarEntidadRepositoryInterface;
use App\Domain\Repositories\ColaboradorRepositoryInterface;
use App\Domain\Repositories\ProveedorRepositoryInterface;
use App\Domain\Repositories\OportunidadRepositoryInterface;
use App\Domain\Repositories\DetalleOportunidadRepositoryInterface;
use App\Domain\Repositories\SeguimientoRepositoryInterface;
use App\Domain\Repositories\ServicioRepositoryInterface;
use App\Domain\Repositories\DetalleServicioRepositoryInterface;
use App\Domain\Repositories\OrdenServicioRepositoryInterface;
use App\Domain\Repositories\CuentaRepositoryInterface;
use App\Domain\Repositories\MovimientoRepositoryInterface;
use App\Infrastructure\Persistence\EloquentRolRepository;
use App\Infrastructure\Persistence\EloquentPermisoRepository;
use App\Infrastructure\Persistence\EloquentUsuarioRepository;
use App\Infrastructure\Persistence\EloquentCiudadRepository;
use App\Infrastructure\Persistence\EloquentProductoRepository;
use App\Infrastructure\Persistence\EloquentEtiquetaRepository;
use App\Infrastructure\Persistence\EloquentEntidadRepository;
use App\Infrastructure\Persistence\EloquentContactoRepository;
use App\Infrastructure\Persistence\EloquentLugarEntidadRepository;
use App\Infrastructure\Persistence\EloquentColaboradorRepository;
use App\Infrastructure\Persistence\EloquentProveedorRepository;
use App\Infrastructure\Persistence\EloquentOportunidadRepository;
use App\Infrastructure\Persistence\EloquentDetalleOportunidadRepository;
use App\Infrastructure\Persistence\EloquentSeguimientoRepository;
use App\Infrastructure\Persistence\EloquentServicioRepository;
use App\Infrastructure\Persistence\EloquentDetalleServicioRepository;
use App\Infrastructure\Persistence\EloquentOrdenServicioRepository;
use App\Infrastructure\Persistence\EloquentCuentaRepository;
use App\Infrastructure\Persistence\EloquentMovimientoRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RolRepositoryInterface::class, EloquentRolRepository::class);
        $this->app->bind(PermisoRepositoryInterface::class, EloquentPermisoRepository::class);
        $this->app->bind(UsuarioRepositoryInterface::class, EloquentUsuarioRepository::class);
        $this->app->bind(CiudadRepositoryInterface::class, EloquentCiudadRepository::class);
        $this->app->bind(ProductoRepositoryInterface::class, EloquentProductoRepository::class);
        $this->app->bind(EtiquetaRepositoryInterface::class, EloquentEtiquetaRepository::class);
        $this->app->bind(EntidadRepositoryInterface::class, EloquentEntidadRepository::class);
        $this->app->bind(ContactoRepositoryInterface::class, EloquentContactoRepository::class);
        $this->app->bind(LugarEntidadRepositoryInterface::class, EloquentLugarEntidadRepository::class);
        $this->app->bind(ColaboradorRepositoryInterface::class, EloquentColaboradorRepository::class);
        $this->app->bind(ProveedorRepositoryInterface::class, EloquentProveedorRepository::class);
        $this->app->bind(OportunidadRepositoryInterface::class, EloquentOportunidadRepository::class);
        $this->app->bind(DetalleOportunidadRepositoryInterface::class, EloquentDetalleOportunidadRepository::class);
        $this->app->bind(SeguimientoRepositoryInterface::class, EloquentSeguimientoRepository::class);
        $this->app->bind(ServicioRepositoryInterface::class, EloquentServicioRepository::class);
        $this->app->bind(DetalleServicioRepositoryInterface::class, EloquentDetalleServicioRepository::class);
        $this->app->bind(OrdenServicioRepositoryInterface::class, EloquentOrdenServicioRepository::class);
        $this->app->bind(CuentaRepositoryInterface::class, EloquentCuentaRepository::class);
        $this->app->bind(MovimientoRepositoryInterface::class, EloquentMovimientoRepository::class);
    }

    public function boot(): void
    {
        // Rate Limiting para API
        // Por defecto: 60 requests/minuto por token/API key
        // Para API keys externas: configurable por entidad
        \Illuminate\Support\Facades\RateLimiter::for('api', function ($request) {
            // Si es Sanctum token, usar el user_id
            if ($request->user()) {
                return \Illuminate\Cache\RateLimiting\Limit::perMinute(60)
                    ->by('user:' . $request->user()->id)
                    ->response(function () {
                        return response()->json([
                            'success' => false,
                            'error' => 'Too many requests. Intenta de nuevo en un minuto.',
                        ], 429);
                    });
            }

            // Si es API Key, usar el organization_id del middleware
            $organizationId = $request->attributes->get('organization_id', 'anonymous');
            
            return \Illuminate\Cache\RateLimiter::perMinute(60)
                ->by('api-key:' . $organizationId)
                ->response(function () {
                    return response()->json([
                        'success' => false,
                        'error' => 'Rate limit excedido. Máximo 60 requests por minuto.',
                    ], 429);
                });
        });

        // Rate limiting específico para autenticación (más restrictivo)
        \Illuminate\Support\Facades\RateLimiter::for('auth', function ($request) {
            return \Illuminate\Cache\RateLimiter::perMinute(10)
                ->by('auth:' . ($request->ip() . ':' . $request->input('email', 'unknown')))
                ->response(function () {
                    return response()->json([
                        'success' => false,
                        'error' => 'Demasiados intentos de login. Intenta de nuevo en un minuto.',
                    ], 429);
                });
        });
    }
}
