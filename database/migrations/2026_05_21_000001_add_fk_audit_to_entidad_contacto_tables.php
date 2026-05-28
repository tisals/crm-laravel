<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add FK for created_by/updated_by on entidad and contacto,
     * skipping if the constraint already exists (idempotent).
     */
    public function up(): void
    {
        $this->ensureForeignKey('entidad', 'created_by', 'usuarios');
        $this->ensureForeignKey('entidad', 'updated_by', 'usuarios');
        $this->ensureForeignKey('contacto', 'created_by', 'usuarios');
        $this->ensureForeignKey('contacto', 'updated_by', 'usuarios');
    }

    public function down(): void
    {
        $this->dropForeignKeyIfExists('entidad', 'created_by');
        $this->dropForeignKeyIfExists('entidad', 'updated_by');
        $this->dropForeignKeyIfExists('contacto', 'created_by');
        $this->dropForeignKeyIfExists('contacto', 'updated_by');
    }

    private function ensureForeignKey(string $table, string $column, string $references): void
    {
        $fkName = "{$table}_{$column}_foreign";

        if ($this->fkExists($table, $fkName)) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}`
            ADD CONSTRAINT `{$fkName}`
            FOREIGN KEY (`{$column}`) REFERENCES `{$references}`(`id`)
            ON DELETE SET NULL");
    }

    private function dropForeignKeyIfExists(string $table, string $column): void
    {
        $fkName = "{$table}_{$column}_foreign";

        if (!$this->fkExists($table, $fkName)) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$fkName}`");
    }

    private function fkExists(string $table, string $constraintName): bool
    {
        return !empty(DB::select(
            'SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $constraintName]
        ));
    }
};
