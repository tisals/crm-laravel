<?php

namespace Database\Seeders;

use App\Models\App;
use Illuminate\Database\Seeder;

/**
 * Seed the canonical apps catalog.
 *
 * These are the apps that an entity can contract. Source: PRD-MultiApp-Access.md
 *
 * ## Reinstall + legacy-slug migration strategy (2026-08-28)
 *
 * Apps were renamed across multiple historical PRs (`crm` → `minerva`,
 * `marketing` → `fama`, `la-llave` → `concordia`, `brp` → `vesta`,
 * `hermes` → `sailus`, `sailus` Gateway → `mercurio`). The seeder now
 * carries both the new canonical slugs AND an optional `legacy_slug`
 * hint, so a single `db:seed --class=AppsCatalogSeeder` run against
 * EITHER a fresh server OR an existing server with legacy apps will
 * converge on the canonical state in one pass.
 *
 * For each app entry that has `legacy_slug`:
 *   - Phase 1 (renames): if the legacy row exists AND the new slug does
 *     NOT exist, UPDATE the legacy row's slug + content to the new
 *     values (preserves `id` and any `app_entidad` foreign keys).
 *   - If both legacy AND new exist (dual-write edge case, see `mercurio`
 *     ↔ legacy `sailus`): skip with a warning. Manual merge required.
 *
 * Phase 2 then runs `updateOrCreate` for every entry, so new apps are
 * inserted and existing canonical apps are refreshed in place.
 */
class AppsCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $apps = [
            [
                'slug' => 'minerva',
                'legacy_slug' => 'crm',
                'nombre' => 'Minerva',
                'tipo' => 'internal',
                'auth_type' => 'sanctum',
                'descripcion' => 'CRM-ERP fuente de verdad principal del ecosistema Tecnoinnsoft.',
            ],
            [
                'slug' => 'mercurio',
                'legacy_slug' => 'sailus',
                'nombre' => 'Mercurio Gateway',
                'tipo' => 'internal',
                'auth_type' => 'sanctum',
                'descripcion' => 'Gateway de integraciones y bots (rename SAIlus→Mercurio, dual-write hasta 2027-02-06).',
            ],
            [
                'slug' => 'fama',
                'legacy_slug' => 'marketing',
                'nombre' => 'Marketing Manager',
                'tipo' => 'internal',
                'auth_type' => 'sanctum',
                'descripcion' => 'Gestión de campañas y embudos de marketing.',
            ],
            [
                'slug' => 'wp-plugin',
                'nombre' => 'Plugin WordPress',
                'tipo' => 'external',
                'auth_type' => 'sanctum',
                'descripcion' => 'Plugin WP para sitios públicos.',
            ],
            [
                'slug' => 'concordia',
                'legacy_slug' => 'la-llave',
                'nombre' => 'Concordia',
                'tipo' => 'external',
                'auth_type' => 'sanctum',
                'descripcion' => 'Gestión de hábitos saludables.',
            ],
            [
                'slug' => 'janus',
                'nombre' => 'Janus',
                'tipo' => 'external',
                'auth_type' => 'sanctum',
                'descripcion' => 'Puerta de entrada a los microservicios internos de Tecnoinnsoft.',
            ],
            [
                'slug' => 'numeria',
                'nombre' => 'Numeria',
                'tipo' => 'internal',
                'auth_type' => 'sanctum',
                'descripcion' => 'Módulo de control de indicadores de Gestión en SST',
            ],
            [
                'slug' => 'Tempus',
                'nombre' => 'Tempus',
                'tipo' => 'external',
                'auth_type' => 'sanctum',
                'descripcion' => 'Control de personal y Horas Extras',
            ],
            [
                'slug' => 'Vesta',
                'legacy_slug' => 'brp',
                'nombre' => 'Vesta',
                'tipo' => 'external',
                'auth_type' => 'sanctum',
                'descripcion' => 'Asistencia BRP',
            ],
            [
                'slug' => 'labor',
                'nombre' => 'Labor',
                'tipo' => 'external',
                'auth_type' => 'sanctum',
                'descripcion' => 'Acciones de mejora de la productividad y bienestar de los colaboradores',
            ],
            [
                'slug' => 'sailus',
                'legacy_slug' => 'hermes',
                'nombre' => 'SAIlus-agent',
                'tipo' => 'internal',
                'auth_type' => 'sanctum',
                'descripcion' => 'HERMES-AGENT para la gestión con IA de tareas de marketing, soporte SST y Setter comercial.',
            ],
        ];

        // ── Phase 1: legacy-slug renames (preserve IDs + FKs) ──────────────
        foreach ($apps as $data) {
            if (! isset($data['legacy_slug'])) {
                continue;
            }

            $legacySlug = $data['legacy_slug'];
            $newSlug = $data['slug'];

            $legacyExists = App::where('slug', $legacySlug)->exists();
            $newExists = App::where('slug', $newSlug)->exists();

            if ($legacyExists && ! $newExists) {
                // Safe rename — UPDATE in place preserves id and any
                // app_entidad rows pointing at this app.
                App::where('slug', $legacySlug)->update([
                    'slug' => $newSlug,
                    'nombre' => $data['nombre'],
                    'tipo' => $data['tipo'],
                    'auth_type' => $data['auth_type'],
                    'descripcion' => $data['descripcion'],
                    'activo' => true,
                ]);

                continue;
            }

            if ($legacyExists && $newExists) {
                // Dual-write collision (e.g. legacy `sailus` Gateway coexisting
                // with new `mercurio` Gateway, OR legacy `sailus` Gateway
                // colliding with new `sailus` SAIlus Agent). Manual merge
                // required — the seeder intentionally skips to avoid
                // destroying FK-bearing rows.
                $this->command?->warn(
                    "AppsCatalogSeeder: legacy slug '{$legacySlug}' AND new slug '{$newSlug}' both exist. "
                    .'Manual merge required — seeder skipped both.'
                );

                continue;
            }
        }

        // ── Phase 2: standard upsert for all entries ───────────────────────
        foreach ($apps as $data) {
            // Strip the seeder-only `legacy_slug` hint so it doesn't leak
            // into the UPDATE SET clause (apps table has no such column).
            unset($data['legacy_slug']);

            App::updateOrCreate(
                ['slug' => $data['slug']],
                array_merge($data, ['activo' => true])
            );
        }
    }
}
