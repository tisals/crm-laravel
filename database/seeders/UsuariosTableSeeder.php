<?php

namespace Database\Seeders;

use App\Models\Usuario;
use App\Models\Entidad;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

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
            
            $rolModel = \App\Models\Rol::where('nombre', $rol)->first();
            $userData['rol_id'] = $rolModel ? $rolModel->id : 1; // Fallback to 1

            $user = Usuario::updateOrCreate(
                ['email' => $userData['email']],
                $userData
            );
            $userIds[] = $user->id;
        }

        // Asignar equitativamente a cada usuario las entidades
        $entidades = Entidad::pluck('id')->toArray();
        
        DB::table('entidad_usuario')->truncate(); // Limpiar asignaciones previas
        
        $insertData = [];
        foreach ($entidades as $index => $entidadId) {
            $assignedUserId = $userIds[$index % count($userIds)];
            $insertData[] = [
                'entidad_id' => $entidadId,
                'usuario_id' => $assignedUserId,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            // Batch insert to avoid memory issues
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
