<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite doesn't support MODIFY COLUMN natively; rebuild the table
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=OFF');

            // 1. Create temp table with nullable identificacion
            DB::statement('
                CREATE TABLE entidad_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tipo_persona TEXT NOT NULL,
                    tipo_id VARCHAR(20),
                    identificacion VARCHAR(50) UNIQUE,
                    nombre VARCHAR(255) NOT NULL,
                    nombre_comercial VARCHAR(255),
                    direccion VARCHAR(255),
                    ciudad_cod VARCHAR(10),
                    dominio VARCHAR(255),
                    email VARCHAR(255),
                    telefono VARCHAR(50),
                    rut VARCHAR(255),
                    logo VARCHAR(255),
                    estado VARCHAR(50) DEFAULT "Activo",
                    allowed_domains TEXT,
                    webhook_url VARCHAR(255),
                    created_by INTEGER,
                    updated_by INTEGER,
                    created_at TIMESTAMP,
                    updated_at TIMESTAMP,
                    deleted_at TIMESTAMP
                )
            ');

            // 2. Copy data
            DB::statement('
                INSERT INTO entidad_new
                SELECT id, tipo_persona, tipo_id, NULLIF(identificacion, \'\'),
                       nombre, nombre_comercial, direccion, ciudad_cod, dominio,
                       email, telefono, rut, logo, estado,
                       allowed_domains, webhook_url,
                       created_by, updated_by, created_at, updated_at, deleted_at
                FROM entidad
            ');

            // 3. Swap tables
            DB::statement('DROP TABLE entidad');
            DB::statement('ALTER TABLE entidad_new RENAME TO entidad');

            // 4. Recreate indexes for queries that depend on them
            DB::statement('CREATE INDEX IF NOT EXISTS entidad_estado_index ON entidad(estado)');
            DB::statement('CREATE INDEX IF NOT EXISTS entidad_created_by_index ON entidad(created_by)');

            DB::statement('PRAGMA foreign_keys=ON');
        } else {
            // MySQL / MariaDB — simple MODIFY
            Schema::table('entidad', function (Blueprint $table) {
                $table->string('identificacion', 50)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=OFF');

            DB::statement('
                CREATE TABLE entidad_old (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tipo_persona TEXT NOT NULL,
                    tipo_id VARCHAR(20),
                    identificacion VARCHAR(50) NOT NULL UNIQUE,
                    nombre VARCHAR(255) NOT NULL,
                    nombre_comercial VARCHAR(255),
                    direccion VARCHAR(255),
                    ciudad_cod VARCHAR(10),
                    dominio VARCHAR(255),
                    email VARCHAR(255),
                    telefono VARCHAR(50),
                    rut VARCHAR(255),
                    logo VARCHAR(255),
                    estado VARCHAR(50) DEFAULT "Activo",
                    allowed_domains TEXT,
                    webhook_url VARCHAR(255),
                    created_by INTEGER,
                    updated_by INTEGER,
                    created_at TIMESTAMP,
                    updated_at TIMESTAMP,
                    deleted_at TIMESTAMP
                )
            ');

            DB::statement('
                INSERT INTO entidad_old
                SELECT * FROM entidad
            ');

            DB::statement('DROP TABLE entidad');
            DB::statement('ALTER TABLE entidad_old RENAME TO entidad');
            DB::statement('CREATE INDEX IF NOT EXISTS entidad_estado_index ON entidad(estado)');
            DB::statement('CREATE INDEX IF NOT EXISTS entidad_created_by_index ON entidad(created_by)');

            DB::statement('PRAGMA foreign_keys=ON');
        } else {
            Schema::table('entidad', function (Blueprint $table) {
                $table->string('identificacion', 50)->nullable(false)->change();
            });
        }
    }
};
