<?php

namespace Database\Seeders;

use App\Models\Permiso;
use App\Models\Rol;
use Illuminate\Database\Seeder;

class PermisoSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmins = Rol::whereIn('nombre', ['SuperAdmin', 'Admin'])->get();

        foreach ($superAdmins as $admin) {
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
            'entidad' => ['index', 'store', 'show', 'update', 'destroy'],
            'contacto' => ['index', 'store', 'show', 'update', 'destroy', 'acciones'],
            'oportunidades' => ['index', 'store', 'show', 'update', 'destroy', 'ganar', 'clonar', 'version'],
            'seguimientos' => ['index', 'store', 'show', 'update', 'destroy'],
            'maestros' => ['index', 'store', 'show', 'update', 'destroy'],
            'servicios' => ['index', 'store', 'show', 'update', 'destroy', 'renew', 'byEntidad'],
            'detalles-servicio' => ['index', 'store', 'show', 'update', 'destroy'],
            'ordenes-servicio' => ['index', 'store', 'show', 'update', 'destroy'],
            'cuentas' => ['index', 'store', 'show', 'update', 'destroy'],
            'movimientos' => ['index', 'store', 'show', 'update', 'destroy'],
            'entidad-usuario' => ['index', 'store', 'destroy'],
            'pipelines' => ['index'],
        ];

        $roles = Rol::whereNotIn('nombre', ['SuperAdmin', 'Admin'])->get();

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
