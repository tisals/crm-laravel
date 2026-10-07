<?php

namespace Database\Seeders;

use App\Models\App;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AppsCatalogSeeder extends Seeder
{
    /**
     * Seed the canonical apps catalog (11 slugs).
     *
     * Source: module-manifest.json (9 modules) + janus + mercurio = 11 total.
     * Legacy slugs (brp, crm, marketing, wp-plugin, la-llave, sailus,
     * mercurio-dual-write) are soft-deleted for traceability.
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
        ];

        $now = now();

        DB::transaction(function () use ($canonical, $now) {
            // Upsert 11 canonical rows using Eloquent (handles soft-deletes correctly).
            foreach ($canonical as $row) {
                App::updateOrCreate(
                    ['slug' => $row['slug']],
                    [
                        'nombre' => $row['nombre'],
                        'tipo' => 'internal',
                        'auth_type' => 'sanctum',
                        'activo' => true,
                        'descripcion' => null,
                        'deleted_at' => null,
                    ]
                );

                // Insert outbox event for each upserted app (guard: table may not exist yet).
                $this->insertOutboxEvent('app.upserted', [
                    'slug' => $row['slug'],
                    'nombre' => $row['nombre'],
                ]);
            }

            // Soft-delete 7 legacy slugs.
            $legacy = [
                'brp', 'crm', 'marketing', 'wp-plugin', 'la-llave', 'sailus',
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
                    'sailus'     => 'mercurio',
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
