<?php

namespace Database\Seeders;

use App\Models\App;
use Illuminate\Database\Seeder;

class AppsCatalogSeeder extends Seeder
{
    /**
     * Seed the canonical apps catalog.
     *
     * These are the apps that an entity can contract. Source: PRD-MultiApp-Access.md
     */
    public function run(): void
    {
        $apps = [
            ['slug' => 'crm', 'nombre' => 'CRM Tecnoinnsoft', 'tipo' => 'internal', 'auth_type' => 'sanctum', 'descripcion' => 'CRM principal del ecosistema Tecnoinnsoft.'],
            ['slug' => 'sailus', 'nombre' => 'SAIlus Gateway', 'tipo' => 'internal', 'auth_type' => 'sanctum', 'descripcion' => 'Gateway de integraciones y bots.'],
            ['slug' => 'marketing', 'nombre' => 'Marketing Manager', 'tipo' => 'internal', 'auth_type' => 'sanctum', 'descripcion' => 'Gestión de campañas y embudos.'],
            ['slug' => 'wp-plugin', 'nombre' => 'Plugin WordPress', 'tipo' => 'external', 'auth_type' => 'sanctum', 'descripcion' => 'Plugin WP para sitios públicos.'],
            ['slug' => 'la-llave', 'nombre' => 'La Llave Documental', 'tipo' => 'external', 'auth_type' => 'sanctum', 'descripcion' => 'Gestión documental.'],
            ['slug' => 'brp', 'nombre' => 'BRP Asistencia', 'tipo' => 'external', 'auth_type' => 'sanctum', 'descripcion' => 'Asistencia psicosocial (Banco de Bogotá).'],
        ];

        foreach ($apps as $data) {
            App::updateOrCreate(
                ['slug' => $data['slug']],
                array_merge($data, ['activo' => true])
            );
        }
    }
}
