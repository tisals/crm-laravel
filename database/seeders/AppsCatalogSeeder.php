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
            ['slug' => 'minerva', 'nombre' => 'Minerva', 'tipo' => 'internal', 'auth_type' => 'sanctum', 'descripcion' => 'CRM-ERP fuente de verdad principal del ecosistema Tecnoinnsoft.'],
            // allow-list (futuro TokenExchangeController) acepte ambos valores.
            ['slug' => 'mercurio', 'nombre' => 'Mercurio Gateway', 'tipo' => 'internal', 'auth_type' => 'sanctum', 'descripcion' => 'Gateway de integraciones y bots (rename SAIlus→Mercurio, dual-write hasta 2027-02-06).'],
            ['slug' => 'fama', 'nombre' => 'Marketing Manager', 'tipo' => 'internal', 'auth_type' => 'sanctum', 'descripcion' => 'Gestión de campañas y embudos de marketing.'],
            ['slug' => 'wp-plugin', 'nombre' => 'Plugin WordPress', 'tipo' => 'external', 'auth_type' => 'sanctum', 'descripcion' => 'Plugin WP para sitios públicos.'],
            ['slug' => 'concordia', 'nombre' => 'Concordia', 'tipo' => 'external', 'auth_type' => 'sanctum', 'descripcion' => 'Gestión de hábitos saludables.'],
            ['slug' => 'janus', 'nombre' => 'Janus', 'tipo' => 'external', 'auth_type' => 'sanctum', 'descripcion' => 'Puerta de entrada a los microservicios internos de Tecnoinnsoft.'],
            ['slug' => 'numeria', 'nombre' => 'Numeria', 'tipo' => 'internal', 'auth_type' => 'sanctum', 'descripcion' => 'Módulo de control de indicadores de Gestión en SST'],
            ['slug' => 'Tempus', 'nombre' => 'Tempus', 'tipo' => 'external', 'auth_type' => 'sanctum', 'descripcion' => 'Control de personal y Horas Extras'],
            ['slug' => 'Vesta', 'nombre' => 'Vesta', 'tipo' => 'external', 'auth_type' => 'sanctum', 'descripcion' => 'Asistencia BRP'],
            ['slug' => 'labor', 'nombre' => 'Labor', 'tipo' => 'external', 'auth_type' => 'sanctum', 'descripcion' => 'Acciones de mejora de la productividad y bienestar de los colaboradores'],
            ['slug' => 'sailus', 'nombre' => 'SAIlus-agent', 'tipo' => 'internal', 'auth_type' => 'sanctum', 'descripcion' => 'HERMES-AGENT para la gestión con IA de tareas de marketing, soporte SST y Setter comercial.'],
            
        ];

        foreach ($apps as $data) {
            App::updateOrCreate(
                ['slug' => $data['slug']],
                array_merge($data, ['activo' => true])
            );
        }
    }
}
