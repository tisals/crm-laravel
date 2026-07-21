<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make `apellidos` nullable on `contacto` table.
 *
 * El Form Request (`ContactoRequest`) ya permite `apellidos` nullable, y el
 * `ContactoEntity` lo requiere como string no-nullable. Esta migración
 * alinea la BD con la realidad del negocio: algunos contactos solo tienen
 * nombre. Sin este cambio, el middleware `ConvertEmptyStringsToNull`
 * convierte el string vacío a null, el Form Request lo deja pasar, y la
 * inserción falla con `SQLSTATE[23000]` (NOT NULL violation) → 500.
 *
 * NOTA: Se usa SQL directo en lugar de `->change()` porque este proyecto
 * no tiene `doctrine/dbal` instalado, y Laravel 12 requiere esa dependencia
 * para alterar columnas via Schema builder.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `contacto` MODIFY COLUMN `apellidos` VARCHAR(150) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `contacto` MODIFY COLUMN `apellidos` VARCHAR(150) NOT NULL');
    }
};
