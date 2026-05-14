<?php

namespace Database\Seeders;

use App\Models\Entidad;
use App\Models\Usuario;
use Illuminate\Database\Seeder;

class BrandPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Crear usuario admin si no existe
        $user = Usuario::firstOrCreate(
            ['email' => 'admin@tecnoinnsoft.dev'],
            [
                'nombre' => 'Admin Principal',
                'password_hash' => bcrypt('password'),
                'rol_id' => 1,
                'estado' => 'Activo',
            ]
        );

        // 2. Crear entidades marca
        $tecnoinnsoft = Entidad::firstOrCreate(
            ['identificacion' => '900000001-0'],
            [
                'tipo_persona' => 'Juridica',
                'tipo_id' => 'NIT',
                'nombre' => 'Tecnoinnsoft',
                'nombre_comercial' => 'Tecnoinnsoft',
                'dominio' => 'tecnoinnsoft.com',
                'estado' => 'Propia',
            ]
        );

        $deseguridad = Entidad::firstOrCreate(
            ['identificacion' => '900000002-0'],
            [
                'tipo_persona' => 'Juridica',
                'tipo_id' => 'NIT',
                'nombre' => 'Deseguridad.dev',
                'nombre_comercial' => 'Deseguridad.dev',
                'dominio' => 'deseguridad.net',
                'estado' => 'Propia',
            ]
        );

        // 3. Vincular usuario a las marcas
        $user->entidades()->syncWithoutDetaching([
            $tecnoinnsoft->id,
            $deseguridad->id,
        ]);

        $this->command->info("✅ Admin ID {$user->id} vinculado a: {$tecnoinnsoft->dominio}, {$deseguridad->dominio}");
    }
}
