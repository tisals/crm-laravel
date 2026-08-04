<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Agregar columnas a roles
        DB::statement('ALTER TABLE roles ADD COLUMN slug VARCHAR(50) NULL AFTER nombre');
        DB::statement('ALTER TABLE roles ADD COLUMN es_super_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER slug');
        DB::statement('CREATE UNIQUE INDEX idx_roles_slug ON roles(slug)');

        // Backfill: poblar slugs de los 4 roles originales
        $slugMap = [
            'SuperAdmin' => 'super-admin',
            'Comercial' => 'comercial',
            'Operaciones' => 'operaciones',
            'Finanzas' => 'finanzas',
        ];
        foreach ($slugMap as $nombre => $slug) {
            DB::table('roles')->where('nombre', $nombre)->whereNull('slug')->update(['slug' => $slug]);
        }

        // Marcar SuperAdmin
        DB::table('roles')->where('nombre', 'SuperAdmin')->update(['es_super_admin' => true]);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX idx_roles_slug ON roles');
        DB::statement('ALTER TABLE roles DROP COLUMN es_super_admin');
        DB::statement('ALTER TABLE roles DROP COLUMN slug');
    }
};
