<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PagosCliente table — tracks client payments against a specific servicio
 * (license) on a cuenta (bank account).
 *
 * Created to satisfy `tests/Feature/Migration/PagosClienteMigrationTest`
 * (pre-existing test that asserted the schema but had no corresponding
 * migration). The feature surface is minimal — only the schema contract
 * the test enforces:
 *
 *   - columns: id, entidad_id, servicio_id, cuenta_id, fecha, valor,
 *     referencia, observaciones, created_at, updated_at, deleted_at
 *   - indexes:
 *       * idx_entidad_id (single column on entidad_id)
 *       * pagos_cliente_entidad_id_fecha (composite on entidad_id, fecha)
 *   - soft deletes (deleted_at column)
 *   - FKs to entidad, servicios, cuentas — declared as raw SQL to avoid
 *     a circular dependency between this migration and the modules
 *     that own those tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos_cliente', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entidad_id');
            $table->unsignedBigInteger('servicio_id');
            $table->unsignedBigInteger('cuenta_id');
            $table->date('fecha');
            $table->decimal('valor', 14, 2);
            $table->string('referencia', 100)->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('entidad_id', 'pagos_cliente_entidad_id_index');
            $table->index(['entidad_id', 'fecha'], 'pagos_cliente_entidad_id_fecha');
        });

        // FKs added after the table so we don't have to guess column
        // ordering. Wrapped in raw SQL to keep the constraint names
        // deterministic across MariaDB versions.
        \DB::statement('ALTER TABLE `pagos_cliente`
            ADD CONSTRAINT `pagos_cliente_entidad_id_foreign`
            FOREIGN KEY (`entidad_id`) REFERENCES `entidad` (`id`)
            ON DELETE RESTRICT ON UPDATE CASCADE');

        \DB::statement('ALTER TABLE `pagos_cliente`
            ADD CONSTRAINT `pagos_cliente_servicio_id_foreign`
            FOREIGN KEY (`servicio_id`) REFERENCES `servicios` (`id`)
            ON DELETE RESTRICT ON UPDATE CASCADE');

        \DB::statement('ALTER TABLE `pagos_cliente`
            ADD CONSTRAINT `pagos_cliente_cuenta_id_foreign`
            FOREIGN KEY (`cuenta_id`) REFERENCES `cuentas` (`id`)
            ON DELETE RESTRICT ON UPDATE CASCADE');
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos_cliente');
    }
};
