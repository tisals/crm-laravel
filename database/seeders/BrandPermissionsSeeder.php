<?php

namespace Database\Seeders;

use App\Models\Entidad;
use App\Models\Usuario;
use App\Models\Rol;
use Illuminate\Database\Seeder;

class BrandPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Crear entidades marca primero
        $tecnoinnsoft = Entidad::firstOrCreate(
            ['identificacion' => '900935453-0'],
            [
                'tipo_persona' => 'Juridica',
                'tipo_id' => 'NIT',
                'nombre' => 'Tecnoinnsoft SAS BIC',
                'nombre_comercial' => 'Tecnoinnsoft',
                'dominio' => 'tecnoinnsoft.com',
                'estado' => 'Propia',
            ]
        );

        $deseguridad = Entidad::firstOrCreate(
            ['identificacion' => '900935453-0'],
            [
                'tipo_persona' => 'Juridica',
                'tipo_id' => 'NIT',
                'nombre' => 'Deseguridad.net',
                'nombre_comercial' => 'Deseguridad.net',
                'dominio' => 'https://deseguridad.net',
                'estado' => 'Propia',
            ]
        );

        // Resolving roles dynamically to handle auto-increment changes across test transactions
        $superAdminRole = Rol::where('nombre', 'SuperAdmin')->first() ?? Rol::create(['nombre' => 'SuperAdmin', 'estado' => 'Activo']);
        $comercialRole = Rol::where('nombre', 'Comercial')->first() ?? Rol::create(['nombre' => 'Comercial', 'estado' => 'Activo']);

        // 2. Definir lista de usuarios a crear
        $usuarios = [
            [
                'email' => 'admin@tecnoinnsoft.dev',
                'nombre' => 'Admin Principal',
                'password_hash' => bcrypt('password'),
                'rol_id' => $superAdminRole->id,
                'estado' => 'Activo',
            ],
            [
                'email' => 'gestorcomercial.tis@gmail.com',
                'nombre' => 'Lorena Bernal',
                'password_hash' => bcrypt('password'),
                'rol_id' => $comercialRole->id, // Ventas (Comercial)
                'estado' => 'Activo',
            ],
            [
                'email' => 'direccion.tis@gmail.com',
                'nombre' => 'Jaime Novoa',
                'password_hash' => bcrypt('password'),
                'rol_id' => $comercialRole->id, // Ventas (Comercial)
                'estado' => 'Activo',
            ],
            [
                'email' => 'innovacionydesarrollo.tis@gmail.com',
                'nombre' => 'Alejandro Leguizamo',
                'password_hash' => bcrypt('password'),
                'rol_id' => $superAdminRole->id, // Admin (Super Admin)
                'estado' => 'Activo',
            ],
            [
                'email' => 'servicioalcliente.tis@gmail.com',
                'nombre' => 'Patricia Moreno',
                'password_hash' => bcrypt('password'),
                'rol_id' => $comercialRole->id, // Ventas (Comercial)
                'estado' => 'Activo',
            ],
        ];

        // 3. Crear y vincular a cada usuario con las marcas
        foreach ($usuarios as $u) {
            $user = Usuario::firstOrCreate(
                ['email' => $u['email']],
                [
                    'nombre' => $u['nombre'],
                    'password_hash' => $u['password_hash'],
                    'rol_id' => $u['rol_id'],
                    'estado' => $u['estado'],
                ]
            );

            $user->entidades()->syncWithoutDetaching([
                $tecnoinnsoft->id,
                $deseguridad->id,
            ]);

            if (isset($this->command)) {
                $this->command->info("✅ Usuario ID {$user->id} ({$user->nombre}) vinculado a: {$tecnoinnsoft->dominio}, {$deseguridad->dominio}");
            }
        }
    }
}

