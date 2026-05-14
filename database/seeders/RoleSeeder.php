<?php

namespace Database\Seeders;

use App\Models\Rol;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = ['Admin', 'Ventas', 'Operaciones', 'Finanzas'];

        foreach ($roles as $nombre) {
            Rol::firstOrCreate(['nombre' => $nombre], [
                'estado' => 'Activo',
            ]);
        }
    }
}
