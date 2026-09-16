<?php

namespace Database\Seeders;

use App\Models\App;
use App\Models\AppEntidad;
use App\Models\Entidad;
use App\Models\Usuario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Stamp the multi-tenant pivot tables (`app_entidad`,
 * `usuario_app_permisos`) with the seed data needed by:
 *
 *   - GET /api/v1/me/apps                          (GetMyAppsUseCase)
 *   - GET /api/v1/me/apps/{slug}/permisos          (GetMyAppPermissionsUseCase)
 *   - GET /api/v1/me/identity                      (GetMyIdentityUseCase)
 *   - GET /api/v1/me/permisos                      (GetMyIdentityUseCase)
 *   - GET /api/v1/users/{id}/brands                (BrandPermissionController)
 *
 * Without these rows the user-facing "My Apps" surfaces come back empty
 * even after `BrandPermissionsSeeder` has stamped the brand entities +
 * users, because the user → entidad → app chain is mediated by the
 * `app_entidad` pivot (and `usuario_app_permisos` carries the per-user
 * scoped overrides).
 *
 * # Source of truth
 *
 * Apps are looked up by **slug** (not id), so the seeder is robust to
 * re-ordering and to legacy-slug renames performed by `AppsCatalogSeeder`.
 * Brand entities are looked up by their `identificacion` (the same key
 * used by `BrandPermissionsSeeder`), so the seeder is idempotent even if
 * brand entities were created on a previous run.
 *
 * # Idempotency
 *
 *   - `app_entidad` is upserted via `AppEntidad::updateOrCreate` keyed on
 *     the UNIQUE `(app_id, entidad_id)` pair (idx_app_entidad_unique).
 *     Re-running overwrites the `fecha_contrato / fecha_vencimiento /
 *     estado / perfil / notas / created_by` columns but never deletes
 *     rows that a future commit might add (e.g. SAIlus Agent profile
 *     rows stamped by `SailusAgentSeeder`).
 *   - `usuario_app_permisos` rows are inserted only when the
 *     `(usuario_id, app_id, vista)` triple does NOT already exist
 *     (including soft-deleted rows). Per the task brief, existing rows
 *     — alive or soft-deleted — must NOT have their `deleted_at`
 *     touched. The UNIQUE constraint idx_uap_unique is satisfied because
 *     we never attempt to insert a duplicate.
 *
 * # Vista codes
 *
 * The task brief listed several non-existent codes
 * (`crm.comerciales`, `mercurio.read`, `fama.read`); these were not
 * seeded by `PermisoSeeder` and have no FK constraint pointing at them,
 * so storing them would invent contract surface. We substitute real codes
 * from `PermisoSeeder`:
 *
 *   - `entidad.index`, `oportunidades.index`, `contacto.index`,
 *     `seguimientos.index` for Comercial users (per-app scoped).
 *   - `*` for SuperAdmin users (matches the rol-level wildcard stamped by
 *     `PermisoSeeder` for the SuperAdmin rol — see also
 *     `MultiAppRbacService::lookup`, which bypasses `*` checks at the
 *     `permisos` layer; the `usuario_app_permisos` rows therefore
 *     document the seed intent without changing effective permissions).
 *
 * # Position in the chain
 *
 * Runs AFTER `BrandPermissionsSeeder` (so brand entities + users exist)
 * and BEFORE `PipelineSeeder` (so business data has the multi-tenant
 * pivot in place when it lands).
 */
class MultiTenantPivotSeeder extends Seeder
{
    /**
     * Brand entity `identificacion` values — MUST match
     * `BrandPermissionsSeeder` exactly.
     */
    private const BRAND_IDENT_TECNOINNSOFT = '900935453';

    private const BRAND_IDENT_DESEGURIDAD = '900935453-0';

    /**
     * Number of non-brand entidades that get `minerva` access as
     * `member` so dev `GET /api/v1/me/apps` returns useful rows.
     */
    private const NON_BRAND_DEMO_LIMIT = 20;

    public function run(): void
    {
        $this->command?->info('');
        $this->command?->info('🌐 MultiTenantPivotSeeder: stamping app_entidad + usuario_app_permisos...');

        $appEntidadRows = $this->seedAppEntidad();
        $usuarioPermisoRows = $this->seedUsuarioAppPermisos();

        $this->command?->info(sprintf(
            '  app_entidad: %d visionary rows upserted (insert + update).',
            $appEntidadRows
        ));
        $this->command?->info(sprintf(
            '  usuario_app_permisos: %d NEW rows inserted (existing rows left untouched).',
            $usuarioPermisoRows
        ));
        $this->command?->info('✅ MultiTenantPivotSeeder: done.');
    }

    // ──────────────────────────────────────────────────────────────────
    // app_entidad
    // ──────────────────────────────────────────────────────────────────

    private function seedAppEntidad(): int
    {
        $tecnoinnsoft = Entidad::where('identificacion', self::BRAND_IDENT_TECNOINNSOFT)->first();
        $deseguridad = Entidad::where('identificacion', self::BRAND_IDENT_DESEGURIDAD)->first();

        $stamped = 0;

        if ($tecnoinnsoft) {
            foreach ($this->tecnoinnsoftApps() as $row) {
                $stamped += $this->upsertAppEntidad(
                    $row['slug'],
                    $tecnoinnsoft->id,
                    $row['perfil'],
                    $row['estado']
                );
            }
        } else {
            $this->command?->warn(
                '  app_entidad: brand entity "'.self::BRAND_IDENT_TECNOINNSOFT.'" not found — skipping tecnoinnsoft apps.'
            );
        }

        if ($deseguridad) {
            foreach ($this->deseguridadApps() as $row) {
                $stamped += $this->upsertAppEntidad(
                    $row['slug'],
                    $deseguridad->id,
                    $row['perfil'],
                    $row['estado']
                );
            }
        } else {
            $this->command?->warn(
                '  app_entidad: brand entity "'.self::BRAND_IDENT_DESEGURIDAD.'" not found — skipping deseguridad apps.'
            );
        }

        // First N non-brand entidades get `minerva` access as `member`.
        // Filtered by identificacion NOT IN (brand NITs) so brand rows
        // are never duplicated.
        $nonBrandIds = Entidad::query()
            ->whereNotIn('identificacion', [
                self::BRAND_IDENT_TECNOINNSOFT,
                self::BRAND_IDENT_DESEGURIDAD,
            ])
            ->orderBy('id')
            ->limit(self::NON_BRAND_DEMO_LIMIT)
            ->pluck('id');

        foreach ($nonBrandIds as $entidadId) {
            $stamped += $this->upsertAppEntidad('minerva', (int) $entidadId, 'member', 'Activo');
        }

        return $stamped;
    }

    /**
     * Tecnoinnsoft brand: owns the canonical HUB apps; member of the
     * external ones.
     *
     * @return array<int, array{slug: string, perfil: string, estado: string}>
     */
    private function tecnoinnsoftApps(): array
    {
        return [
            ['slug' => 'mercurio',  'perfil' => 'owner',  'estado' => 'Activo'],
            ['slug' => 'fama',      'perfil' => 'owner',  'estado' => 'Activo'],
            ['slug' => 'minerva',   'perfil' => 'owner',  'estado' => 'Activo'],
            ['slug' => 'sailus',    'perfil' => 'owner',  'estado' => 'Activo'],
            ['slug' => 'janus',     'perfil' => 'member', 'estado' => 'Activo'],
            ['slug' => 'concordia', 'perfil' => 'member', 'estado' => 'Activo'],
        ];
    }

    /**
     * Deseguridad.net brand: owner of `minerva`, member of the rest;
     * `concordia` starts on `Trial` to exercise that branch of the enum.
     *
     * @return array<int, array{slug: string, perfil: string, estado: string}>
     */
    private function deseguridadApps(): array
    {
        return [
            ['slug' => 'mercurio',  'perfil' => 'member', 'estado' => 'Activo'],
            ['slug' => 'minerva',   'perfil' => 'owner',  'estado' => 'Activo'],
            ['slug' => 'sailus',    'perfil' => 'member', 'estado' => 'Activo'],
            ['slug' => 'concordia', 'perfil' => 'member', 'estado' => 'Trial'],
        ];
    }

    /**
     * Upsert one `app_entidad` row, keyed on the UNIQUE `(app_id,
     * entidad_id)` pair. Returns 1 on insert/update, 0 if the app slug
     * is missing from the catalog.
     */
    private function upsertAppEntidad(string $slug, int $entidadId, string $perfil, string $estado): int
    {
        $app = App::where('slug', $slug)->first();
        if (! $app) {
            $this->command?->warn("    app slug '{$slug}' not found in apps catalog — skipping app_entidad row.");

            return 0;
        }

        AppEntidad::updateOrCreate(
            [
                'app_id' => $app->id,
                'entidad_id' => $entidadId,
            ],
            [
                'fecha_contrato' => now()->toDateString(),
                'fecha_vencimiento' => null,
                'estado' => $estado,
                'perfil' => $perfil,
                'notas' => null,
                'created_by' => null,
            ]
        );

        return 1;
    }

    // ──────────────────────────────────────────────────────────────────
    // usuario_app_permisos
    // ──────────────────────────────────────────────────────────────────

    private function seedUsuarioAppPermisos(): int
    {
        $stamped = 0;

        // SuperAdmin users: `vista='*'` for every app. The wildcard
        // bypass happens at the `permisos` layer (PermisoSeeder +
        // MultiAppRbacService::lookup), so these rows document the seed
        // intent without altering effective permissions.
        foreach (['admin@tecnoinnsoft.dev', 'innovacionydesarrollo.tis@gmail.com'] as $email) {
            $stamped += $this->stampSuperAdmin($email);
        }

        // Comercial users: scoped per-app vistas using real codes from
        // PermisoSeeder. Each user only sees the apps they actually
        // need access to in dev — keeps the seeded matriz honest.
        $stamped += $this->stampComercial(
            'gestorcomercial.tis@gmail.com',
            [
                ['slug' => 'minerva',  'vistas' => ['entidad.index', 'oportunidades.index', 'contacto.index']],
                ['slug' => 'mercurio', 'vistas' => ['entidad.index']],
                ['slug' => 'fama',     'vistas' => ['entidad.index']],
            ]
        );

        $stamped += $this->stampComercial(
            'direccion.tis@gmail.com',
            [
                ['slug' => 'minerva', 'vistas' => ['entidad.index', 'oportunidades.index']],
            ]
        );

        $stamped += $this->stampComercial(
            'servicioalcliente.tis@gmail.com',
            [
                ['slug' => 'minerva', 'vistas' => ['entidad.index', 'contacto.index']],
            ]
        );

        return $stamped;
    }

    private function stampSuperAdmin(string $email): int
    {
        $user = Usuario::where('email', $email)->first();
        if (! $user) {
            $this->command?->warn("    user '{$email}' not found — skipping SuperAdmin perms.");

            return 0;
        }

        $apps = App::orderBy('slug')->get();
        $stamped = 0;

        foreach ($apps as $app) {
            $stamped += $this->insertPermisoIfMissing($user->id, $app->id, '*');
        }

        return $stamped;
    }

    /**
     * @param  array<int, array{slug: string, vistas: array<int, string>}>  $appVistas
     */
    private function stampComercial(string $email, array $appVistas): int
    {
        $user = Usuario::where('email', $email)->first();
        if (! $user) {
            $this->command?->warn("    user '{$email}' not found — skipping Comercial perms.");

            return 0;
        }

        $stamped = 0;

        foreach ($appVistas as $entry) {
            $app = App::where('slug', $entry['slug'])->first();
            if (! $app) {
                $this->command?->warn("    app '{$entry['slug']}' not found — skipping for {$email}.");

                continue;
            }

            foreach ($entry['vistas'] as $vista) {
                $stamped += $this->insertPermisoIfMissing($user->id, $app->id, $vista);
            }
        }

        return $stamped;
    }

    /**
     * Insert one `usuario_app_permisos` row only when the UNIQUE
     * `(usuario_id, app_id, vista)` triple has no existing row (alive
     * OR soft-deleted). Per task brief, an existing row — including
     * a soft-deleted one — is left untouched.
     */
    private function insertPermisoIfMissing(int $usuarioId, int $appId, string $vista): int
    {
        $exists = DB::table('usuario_app_permisos')
            ->where('usuario_id', $usuarioId)
            ->where('app_id', $appId)
            ->where('vista', $vista)
            ->exists();

        if ($exists) {
            return 0;
        }

        $now = now();
        DB::table('usuario_app_permisos')->insert([
            'usuario_id' => $usuarioId,
            'app_id' => $appId,
            'vista' => $vista,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        return 1;
    }
}
