<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Applies the Definition-of-Done (DOD) cap across the database AFTER all
 * data seeders have run.
 *
 * DOD: no entity may have more than $maxOps opportunities and no entity may
 * have more than $maxContactos contacts. When the cap is exceeded, the
 * OLDEST rows are removed (preserving the most recent work).
 *
 * This seeder runs LAST in DatabaseSeeder so that all dependent data
 * (detalle_oportunidad, seguimiento, entidad_usuario assignments, etc.)
 * has been seeded against the full set of opportunities/contacts BEFORE
 * the cap removes the oldest rows.
 *
 * detalle_oportunidad has CASCADE on delete against oportunidad, so
 * detalle rows for truncated opportunities are removed automatically.
 * seguimiento has nullOnDelete against oportunidad/contacto, so
 * seguimiento rows are preserved with their FKs nulled.
 */
class DodCapSeeder extends Seeder
{
    public const MAX_OPS_POR_ENTIDAD = 10;

    public const MAX_CONTACTOS_POR_ENTIDAD = 10;

    public function run(): void
    {
        $oppStats = (new OportunidadCsvSeeder)->applyDodCap(
            maxOps: self::MAX_OPS_POR_ENTIDAD,
            maxContactos: self::MAX_CONTACTOS_POR_ENTIDAD
        );

        $contactoStats = (new ContactoCsvSeeder)->applyDodCap(
            maxOps: self::MAX_OPS_POR_ENTIDAD,
            maxContactos: self::MAX_CONTACTOS_POR_ENTIDAD
        );

        $totalOpsEliminadas = $oppStats['oportunidades_eliminadas'] ?? 0;
        $totalContactosEliminados = $contactoStats['contactos_eliminados'] ?? 0;

        $this->command->info('');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('📏 DOD CAP (Definition of Done)');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('  Max oportunidades por entidad : '.self::MAX_OPS_POR_ENTIDAD);
        $this->command->info('  Max contactos por entidad     : '.self::MAX_CONTACTOS_POR_ENTIDAD);
        $this->command->info("  Ops truncadas                : {$totalOpsEliminadas}");
        $this->command->info("  Contactos truncados          : {$totalContactosEliminados}");

        if ($totalOpsEliminadas === 0 && $totalContactosEliminados === 0) {
            $this->command->info('  ✅ Ninguna entidad excedió la DOD.');
        } else {
            $this->command->warn("  ⚠️  Se truncaron filas para cumplir la DOD. Revisar si era esperado.");
        }
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }
}
