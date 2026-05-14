<?php

namespace Database\Seeders;

use App\Models\Permiso;
use App\Models\Rol;
use Illuminate\Database\Seeder;

class PermisoSeeder extends Seeder
{
    public function run(): void
    {
        $admin = Rol::where('nombre', 'Admin')->first();

        if ($admin) {
            // Admin gets wildcard permission
            Permiso::firstOrCreate([
                'rol_id' => $admin->id,
                'vista' => '*',
            ]);
        }

        $vistas = [
            'roles' => ['index', 'store', 'show', 'update', 'destroy'],
            'permisos' => ['index', 'store', 'show', 'update', 'destroy'],
            'usuarios' => ['index', 'store', 'show', 'update', 'destroy', 'toggle-status'],
            'ciudades' => ['index', 'show'],
            'productos' => ['index', 'store', 'show', 'update', 'destroy'],
            'etiquetas' => ['index', 'store', 'show', 'update', 'destroy'],
        ];

        $roles = Rol::where('nombre', '!=', 'Admin')->get();

        foreach ($roles as $rol) {
            foreach ($vistas as $vista => $acciones) {
                foreach ($acciones as $accion) {
                    Permiso::firstOrCreate([
                        'rol_id' => $rol->id,
                        'vista' => "{$vista}.{$accion}",
                    ]);
                }
            }
        }
    }
}
