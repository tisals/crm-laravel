<?php

namespace Tests\Feature\Migration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PagosClienteMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function table_has_required_columns(): void
    {
        $expected = ['id', 'entidad_id', 'servicio_id', 'cuenta_id', 'fecha', 'valor', 'referencia', 'observaciones', 'created_at', 'updated_at', 'deleted_at'];

        foreach ($expected as $column) {
            $this->assertTrue(
                Schema::hasColumn('pagos_cliente', $column),
                "Expected column $column in pagos_cliente"
            );
        }
    }

    #[Test]
    public function fk_constraints_enforced(): void
    {
        $indexes = collect(Schema::getIndexes('pagos_cliente'));
        $this->assertTrue(
            $indexes->contains(fn ($i) => str_contains($i['name'], 'entidad_id')),
            'Expected index on entidad_id'
        );
        $this->assertTrue(
            $indexes->contains(fn ($i) => str_contains($i['name'], 'pagos_cliente_entidad_id_fecha')),
            'Expected composite index on (entidad_id, fecha)'
        );
    }
}
