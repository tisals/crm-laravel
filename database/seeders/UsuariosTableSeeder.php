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

        // Limpiar asignaciones previas de estos usuarios
        DB::table('entidad_usuario')
            ->whereIn('usuario_id', $userIds)
            ->whereNotIn('entidad_id', $propiaIds)
            ->delete();

        $insertData = [];
        $otherIndex = 0;
        foreach ($entidadUserMap as $item) {
            if (in_array($item['entidad_id'], $propiaIds)) {
                continue;
            }
            if ($item['year'] >= 2026) {
                $insertData[] = [
                    'entidad_id' => $item['entidad_id'],
                    'usuario_id' => $lorenaId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            } else {
                $insertData[] = [
                    'entidad_id' => $item['entidad_id'],
                    'usuario_id' => $otherUserIds[$otherIndex % count($otherUserIds)],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $otherIndex++;
            }

            if (count($insertData) >= 500) {
                DB::table('entidad_usuario')->insert($insertData);
                $insertData = [];
            }
        }

        if (count($insertData) > 0) {
            DB::table('entidad_usuario')->insert($insertData);
        }

        // UPDATE OPORTUNIDADES AND CONTACTOS
        // También debemos asegurarnos de que el "responsable" (created_by) en la oportunidad
        // se actualice de acuerdo al usuario asignado a la entidad de esa oportunidad.
        DB::statement('
            UPDATE oportunidad 
            SET created_by = (
                SELECT usuario_id 
                FROM entidad_usuario 
                WHERE entidad_usuario.entidad_id = oportunidad.entidad_id 
                LIMIT 1
            )
            WHERE EXISTS (
                SELECT 1 
                FROM entidad_usuario 
                WHERE entidad_usuario.entidad_id = oportunidad.entidad_id
            )
        ');

        DB::statement('
            UPDATE contacto 
            SET created_by = (
                SELECT usuario_id 
                FROM entidad_usuario 
                WHERE entidad_usuario.entidad_id = contacto.entidad_id 
                LIMIT 1
            )
            WHERE EXISTS (
                SELECT 1 
                FROM entidad_usuario 
                WHERE entidad_usuario.entidad_id = contacto.entidad_id
            )
        ');

        $this->command->info('Usuarios creados y entidades asignadas equitativamente.');
    }
}
