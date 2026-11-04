<?php

namespace Database\Seeders;

use App\Models\App;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AppsCatalogSeeder extends Seeder
{
    /**
     * Seed the canonical apps catalog (12 slugs).
     *
     * Source: module-manifest.json (9 modules) + janus + mercurio + sailus
     * = 12 total.
     * Legacy slugs (brp, crm, marketing, wp-plugin, la-llave,
     * mercurio-dual-write) are soft-deleted for traceability.
     *
     * `sailus` is NO LONGER a legacy slug: the name was reclaimed for the
     * SST manuals module (the pantheon of the hub's apps, read as
     * "bienestar y salud"). It used to be the platform's own former name,
     * whose successor is `mercurio` — so the old `sailus -> mercurio`
     * alias is gone with it.
     *
     * See: janus-apps-canonicalization SDD change.
     */
    public function run(): void
    {
        $canonical = [
            ['slug' => 'concordia',    'nombre' => 'Concordia'],
            ['slug' => 'fama',         'nombre' => 'Marketing Manager (Fama)'],
            ['slug' => 'minerva',      'nombre' => 'Minerva CRM'],
            ['slug' => 'numeria',      'nombre' => 'Numeria'],
            ['slug' => 'safe-health',  'nombre' => 'Safe-Health'],
            ['slug' => 'tempus',       'nombre' => 'Tempus'],
            ['slug' => 'tis',          'nombre' => 'TIS'],
            ['slug' => 'vesta',        'nombre' => 'Vesta (Asistencia BRP)'],
            ['slug' => 'vigil',        'nombre' => 'Vigil'],
            ['slug' => 'janus',        'nombre' => 'Janus Shell (infraestructura)'],
            ['slug' => 'mercurio',     'nombre' => 'Mercurio Gateway (infraestructura)'],
            [
                'slug' => 'sailus',
                'nombre' => 'Sailus (Manuales SST)',
                'descripcion' => 'Manuales SST: matriz de riesgos GTC 45, plan de trabajo y set documental del SG-SST.',
            ],
        ];

        $now = now();

        DB::transaction(function () use ($canonical, $now) {
            // Upsert the canonical rows using Eloquent (handles soft-deletes correctly).
            foreach ($canonical as $row) {
                $payload = [
                    'nombre' => $row['nombre'],
                    'tipo' => 'internal',
                    'auth_type' => 'sanctum',
                    'activo' => true,
                    'descripcion' => $row['descripcion'] ?? null,
                    'deleted_at' => null,
                ];

                // A reclaimed slug must not keep the alias of its former
                // owner (the column may not exist on older databases).
                if (Schema::hasColumn('apps', 'replaced_by')) {
                    $payload['replaced_by'] = null;
                }

                App::updateOrCreate(['slug' => $row['slug']], $payload);

                // Insert outbox event for each upserted app (guard: table may not exist yet).
                $this->insertOutboxEvent('app.upserted', [
                    'slug' => $row['slug'],
                    'nombre' => $row['nombre'],
                ]);
            }

            // Soft-delete the legacy slugs. `sailus` is NOT one of them
            // anymore: it was reclaimed for the SST manuals module, and a
            // legacy soft-delete here would take the live app down on
            // every seed.
            $legacy = [
                'brp', 'crm', 'marketing', 'wp-plugin', 'la-llave',
            ];
            foreach ($legacy as $slug) {
                DB::table('apps')
                    ->where('slug', $slug)
                    ->whereNull('deleted_at')
                    ->update([
                        'deleted_at' => $now,
                        'updated_at' => $now,
                    ]);
            }

            // Handle mercurio dual-write: the old SAIlus mercurio row.
            DB::table('apps')
                ->where('slug', 'mercurio')
                ->where('nombre', 'like', '%SAIlus%')
                ->whereNull('deleted_at')
                ->update([
                    'deleted_at' => $now,
                    'updated_at' => $now,
                ]);

            // Backfill replaced_by for legacy slugs (guard: only if column exists).
            if (Schema::hasColumn('apps', 'replaced_by')) {
                $aliases = [
                    'brp'        => 'vesta',
                    'crm'        => 'minerva',
                    'marketing'  => 'fama',
                    'wp-plugin'  => 'tis',
                    'la-llave'   => 'concordia',
                ];
                foreach ($aliases as $legacy => $canon) {
                    DB::table('apps')
                        ->where('slug', $legacy)
                        ->whereNull('replaced_by')
                        ->update(['replaced_by' => $canon]);
                }
            }
        });
    }

    private function insertOutboxEvent(string $eventType, array $payload): void
    {
        // Guard: the outbox table may not exist on first deploy (migration runs after seed
        // in fresh-install scenarios). Skip gracefully.
        if (! Schema::hasTable('webhook_outbox')) {
            return;
        }

        DB::table('webhook_outbox')->insert([
            'event_type' => $eventType,
            'payload_json' => json_encode($payload),
            'attempts' => 0,
            'status' => 'pending',
            'next_retry_at' => null,
            'last_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
