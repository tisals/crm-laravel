<?php

namespace Modules\Shared\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AppsSeeder extends Seeder
{
    public function run(): void
    {
        $apps = [
            ['slug' => 'crm',        'nombre' => 'CRM Tecnoinnsoft',   'tipo' => 'internal', 'auth_type' => 'sanctum', 'activo' => true, 'descripcion' => 'CRM principal'],
            ['slug' => 'sailus',     'nombre' => 'SAIlus Gateway',     'tipo' => 'internal', 'auth_type' => 'sanctum', 'activo' => true, 'descripcion' => 'Gateway SAIlus'],
            ['slug' => 'marketing',  'nombre' => 'Marketing Manager',  'tipo' => 'internal', 'auth_type' => 'sanctum', 'activo' => true, 'descripcion' => 'Marketing'],
            ['slug' => 'wp-plugin',  'nombre' => 'Plugin WordPress',   'tipo' => 'external', 'auth_type' => 'sanctum', 'activo' => true, 'descripcion' => 'Plugin para WordPress'],
            ['slug' => 'la-llave',   'nombre' => 'La Llave Documental', 'tipo' => 'external', 'auth_type' => 'sanctum', 'activo' => true, 'descripcion' => 'La Llave'],
            ['slug' => 'brp',        'nombre' => 'BRP Asistencia',     'tipo' => 'external', 'auth_type' => 'sanctum', 'activo' => true, 'descripcion' => 'BRP'],
        ];

        foreach ($apps as $app) {
            DB::table('apps')->updateOrInsert(
                ['slug' => $app['slug']],
                $app + ['created_at' => now(), 'updated_at' => now()]
            );
        }
    }
}
