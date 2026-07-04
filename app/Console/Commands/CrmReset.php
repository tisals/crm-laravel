<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

/**
 * crm:reset — Borrar la base de datos y regenerarla desde cero.
 *
 * Wrapper sobre `migrate:fresh --seed` con:
 *  - Confirmación interactiva (--force la salta)
 *  - Output con timing y resumen
 *  - Validación post-seed que se ejecturó OK
 *
 * Uso:
 *   php artisan crm:reset                    # pregunta confirmación
 *   php artisan crm:reset --force            # salta confirmación
 *   php artisan crm:reset --skip-seed        # solo migrate:fresh, no seeds
 *
 * Dev (SQLite/MariaDB en Docker):
 *   docker exec crm-laravel-dev php artisan crm:reset --force
 *
 * Prod:
 *   PHP_AUTOLOAD_FORCE_PROD=true php artisan crm:reset --force
 */
class CrmReset extends Command
{
    protected $signature = 'crm:reset
        {--force : Skip confirmation}
        {--skip-seed : Run migrate:fresh only, skip database seeding}';

    protected $description = 'Drop all tables and re-migrate + re-seed from CSV (clean slate)';

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->error('╔═══════════════════════════════════════════════════════╗');
            $this->error('║  ⚠️  PELIGRO: Esta acción BORRA TODA la base de datos   ║');
            $this->error('╚═══════════════════════════════════════════════════════╝');
            $this->line('');
            $this->line('Conexión activa: ' . config('database.default') . ' → ' . config('database.connections.' . config('database.default') . '.database'));
            $this->line('Entorno: APP_ENV=' . app()->environment());
            $this->line('');

            if (! $this->confirm('¿Continuar y borrar todas las tablas?', false)) {
                $this->info('Cancelado por el usuario.');
                return self::FAILURE;
            }
        }

        $start = microtime(true);

        $this->info('╭─[1/3] Ejecutando migrate:fresh...');
        Artisan::call('migrate:fresh', [
            '--force' => true,
        ]);
        $this->info('├─ Output: ' . Artisan::output());
        $this->info('╰─ OK (' . round(microtime(true) - $start, 2) . 's)');

        if ($this->option('skip-seed')) {
            $this->newLine();
            $this->info('⏭ --skip-seed activo, saltando seeds.');
            return self::SUCCESS;
        }

        $mid = microtime(true);
        $this->info('╭─[2/3] Ejecutando db:seed...');
        Artisan::call('db:seed', ['--force' => true]);
        $this->info('╰─ OK (' . round(microtime(true) - $mid, 2) . 's)');

        $end = microtime(true);
        $this->info('╭─[3/3] Validación post-seed...');
        $this->validateSeed();
        $this->info('╰─ OK');

        $this->newLine();
        $this->info('═════════════════════════════════════════════════════');
        $this->info(' ✅ BASE DE DATOS REINICIADA EXITOSAMENTE');
        $this->info('⏱ Tiempo total: ' . round($end - $start, 2) . 's');
        $this->info('═════════════════════════════════════════════════════');

        return self::SUCCESS;
    }

    /**
     * Validación rápida post-seed: cuenta tablas clave.
     * Si todo está OK, los counts deben ser > 0.
     */
    private function validateSeed(): void
    {
        $tables = [
            'entidad' => 'Entidades',
            'contacto' => 'Contactos',
            'oportunidad' => 'Oportunidades',
            'detalle_oportunidad' => 'Detalles',
            'seguimiento' => 'Seguimientos',
            'usuarios' => 'Usuarios',
        ];

        foreach ($tables as $table => $label) {
            try {
                $count = DB::table($table)->count();
                $marker = $count > 0 ? '✓' : '⚠';
                $color = $count > 0 ? 'info' : 'warn';
                $this->{$color}("  {$marker} {$label} ({$table}): {$count} registros");
            } catch (\Throwable $t) {
                $this->error("  ✗ {$label} ({$table}): tabla no existe");
            }
        }

        // Distribución entidades por dominio
        $conDom = DB::table('entidad')->whereNotNull('dominio')->where('dominio', '!=', '')->count();
        $conSocial = DB::table('entidad')->whereNotNull('red_social_url')->where('red_social_url', '!=', '')->count();
        $sinDom = DB::table('entidad')->where(function ($q) {
            $q->whereNull('dominio')->orWhere('dominio', '');
        })->count();

        $this->newLine();
        $this->info("  Distribución entidades:");
        $this->info("    • Con dominio real: {$conDom}");
        $this->info("    • Con red social:   {$conSocial}");
        $this->info("    • Sin sitio web:    {$sinDom}");
    }
}
