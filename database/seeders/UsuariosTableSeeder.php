<?php

namespace Database\Seeders;

use App\Models\Entidad;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UsuariosTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Per `tenant-data-model-fixes` (commit 2.5) + commit fe99f70: the
     * pivot is now `entidad_persona` (keyed on persona_id, NOT usuario_id).
     * The user ↔ entidad binding is created by:
     *   1. Look up the persona_id for each user (NOT NULL FK added in
     *      migration 000003, backfilled from usuarios.email → personas.email_principal).
     *   2. Insert into `entidad_persona` the rows (persona_id, entidad_id).
     *
     * For contactos: since `contacto.entidad_id` was dropped, the
     * `created_by` update resolves the responsible user via the pivot
     * joined back to `usuarios.persona_id` — same idea, two hops.
     */
    public function run(): void
    {
        $users = [
            [
                'nombre' => 'Alejandro Leguizamo',
                'email' => 'innovacionydesarrollo.tis@gmail.com',
                'rol' => 'Comercial',
                'password_hash' => Hash::make('password'),
            ],
            [
                'nombre' => 'Lorena Bernal',
                'email' => 'gestorcomercial.tis@gmail.com',
                'rol' => 'Comercial',
                'password_hash' => Hash::make('password'),
            ],
            [
                'nombre' => 'Jaime Novoa',
                'email' => 'direccion.tis@gmail.com',
                'rol' => 'Comercial',
                'password_hash' => Hash::make('password'),
            ],
            [
                'nombre' => 'Patricia Moreno',
                'email' => 'servicioalcliente.tis@gmail.com',
                'rol' => 'Comercial',
                'password_hash' => Hash::make('password'),
            ],
        ];

        $userIds = [];

        foreach ($users as $userData) {
            $rol = $userData['rol'];
            unset($userData['rol']);

            $rolModel = Rol::where('nombre', $rol)->first();
            $userData['rol_id'] = $rolModel ? $rolModel->id : 1; // Fallback to 1

            $user = Usuario::updateOrCreate(
                ['email' => $userData['email']],
                $userData
            );
            $userIds[] = $user->id;
        }

        // Asignar por rango de año de oportunidad (basado en la fecha más reciente):
        // - Lorena Bernal (índice 1): 2026
        // - Alejandro, Jaime, Patricia (índices 0,2,3): 2021-2025
        $propiaIds = Entidad::where('estado', 'Propia')->pluck('id')->toArray();

        // Entidades con opp más reciente en 2026 → Lorena
        // Entidades con opp más reciente en 2021-2025 → los otros 3 (round-robin)
        $entidadUserMap = DB::table('oportunidad')
            ->selectRaw('MIN(oportunidad.entidad_id) as entidad_id, MAX(oportunidad.fecha) as max_fecha')
            ->groupBy('entidad_id')
            ->get()
            ->map(function ($row) {
                $year = substr($row->max_fecha, 0, 4);
                return [
                    'entidad_id' => (int) $row->entidad_id,
                    'year' => (int) $year,
                ];
            });

        $lorenaId = $userIds[1]; // Lorena Bernal
        $otherUserIds = [$userIds[0], $userIds[2], $userIds[3]]; // Alejandro, Jaime, Patricia

        // Resolve persona_ids for our users (NOT NULL FK added in migration 000003).
        $userPersonaMap = DB::table('usuarios')
            ->whereIn('id', $userIds)
            ->pluck('persona_id', 'id')
            ->all();

        // Limpiar asignaciones previas de estos usuarios (via the pivot).
        DB::table('entidad_persona')
            ->whereIn('persona_id', array_values($userPersonaMap))
            ->whereNotIn('entidad_id', $propiaIds)
            ->delete();

        $insertData = [];
        $otherIndex = 0;
        foreach ($entidadUserMap as $item) {
            if (in_array($item['entidad_id'], $propiaIds)) {
                continue;
            }
            $assigneeUserId = $item['year'] >= 2026
                ? $lorenaId
                : $otherUserIds[$otherIndex % count($otherUserIds)];
            if ($item['year'] < 2026) {
                $otherIndex++;
            }

            $personaId = $userPersonaMap[$assigneeUserId] ?? null;
            if (! $personaId) {
                continue;
            }

            $insertData[] = [
                'entidad_id' => $item['entidad_id'],
                'persona_id' => $personaId,
                'categoria' => 'asignacion',
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($insertData) >= 500) {
                DB::table('entidad_persona')->insert($insertData);
                $insertData = [];
            }
        }

        if (count($insertData) > 0) {
            DB::table('entidad_persona')->insert($insertData);
        }

        // UPDATE OPORTUNIDADES: responsable (created_by) tracks the user
        // assigned to the oportunidad's entidad via the new pivot.
        // Per commit fe99f70: pivot is `entidad_persona`, joined back to
        // usuarios via `usuarios.persona_id` (NOT NULL FK added in migration 000003).
        DB::statement("
            UPDATE oportunidad
            SET created_by = (
                SELECT u.id
                FROM entidad_persona ep
                INNER JOIN usuarios u ON u.persona_id = ep.persona_id
                WHERE ep.entidad_id = oportunidad.entidad_id
                LIMIT 1
            )
            WHERE EXISTS (
                SELECT 1
                FROM entidad_persona ep
                WHERE ep.entidad_id = oportunidad.entidad_id
            )
        ");

        // UPDATE CONTACTOS: same idea but contacto.entidad_id was dropped,
        // so we resolve contacto → persona → entidad_persona → usuarios.
        DB::statement("
            UPDATE contacto c
            SET created_by = (
                SELECT u.id
                FROM entidad_persona ep
                INNER JOIN usuarios u ON u.persona_id = ep.persona_id
                WHERE ep.entidad_id = (
                    SELECT ep2.entidad_id
                    FROM entidad_persona ep2
                    WHERE ep2.persona_id = c.persona_id
                    LIMIT 1
                )
                LIMIT 1
            )
            WHERE c.persona_id IS NOT NULL
              AND EXISTS (
                SELECT 1
                FROM entidad_persona ep
                WHERE ep.persona_id = c.persona_id
              )
        ");

        $this->command->info('Usuarios creados y entidades asignadas equitativamente.');
    }
}
