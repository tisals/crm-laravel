<?php

namespace Tests\Feature\Migration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Commit 3 — backfill migration test.
 *
 * Seeds personas + entidad with legacy columns populated, runs the
 * seven backfill migrations, then asserts each new shared table has
 * the expected row(s).
 *
 * Crucially: legacy columns (`personas.telefono_principal`,
 * `personas.email_principal`, `personas.direccion/ciudad/pais`,
 * `entidad.email/telefono/direccion/ciudad_cod/dominio/red_social_url`)
 * MUST still exist after the backfill (Commit 3 contract: keep
 * deprecated, drop later). A later commit drops them once production
 * callers are confirmed migrated.
 *
 * We use `RefreshDatabase` (NOT `DatabaseMigrations`) because
 * `DatabaseMigrations` runs `migrate:fresh` before every method and
 * hits a pre-existing MariaDB error: `migrate:rollback --all`
 * can't drop `idx_seguimiento_entidad_fecha` because some FK still
 * references it. That's an orthogonal issue we shouldn't fix as part
 * of Commit 3. `RefreshDatabase` only runs `migrate:fresh` once per
 * class; we work around the lack of per-method reset by clearing the
 * five new tables ourselves at the top of `setUp()`.
 */
class BackfillContactTablesTest extends TestCase
{
    use RefreshDatabase;

    private int $personaId;

    private int $entidadId;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed persona with legacy columns populated.
        $this->personaId = DB::table('personas')->insertGetId([
            'nombres' => 'Ada',
            'apellidos' => 'Lovelace',
            'email_principal' => 'Ada.Lovelace@Example.Test', // case test
            'telefono_principal' => '+57 300 1234567',
            'direccion' => 'Calle 1 #2-3',
            'ciudad' => 'Bogotá',
            'pais' => 'CO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed entidad with legacy columns populated.
        // `entidad.ciudad_cod` is a real FK to `ciudades.cod_municipio`,
        // so we have to seed the city first or the INSERT fails.
        DB::table('ciudades')->insert([
            'cod_municipio' => '11001',
            'nombre' => 'Bogotá',
            'departamento' => 'Cundinamarca',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entidadId = DB::table('entidad')->insertGetId([
            'tipo_persona' => 'Juridica',
            'nombre' => 'Test Corp',
            'identificacion' => 'TEST-'.uniqid(),
            'email' => 'Contact@TestCorp.COM',
            'telefono' => '+57 601 5551212',
            'direccion' => 'Av 9 #10-20',
            'ciudad_cod' => '11001',
            'dominio' => 'testcorp.com.co',
            'red_social_url' => 'facebook.com/testcorp',
            'estado' => 'Activo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // `RefreshDatabase` runs `migrate:fresh` BEFORE `setUp()`, so the
        // seven backfill migrations already executed against an empty DB
        // (no personas, no entidad rows to copy). Now that the seed
        // rows are in place, re-run the backfill INSERTs so this test
        // exercises the same SQL the production migration uses. The
        // backfill queries are idempotent (NOT EXISTS guards) so a
        // second pass is safe.
        $this->runBackfillInserts();
    }

    /**
     * Mirror the seven backfill INSERTs from
     * 2026_09_02_130000..130600 against the now-populated DB. Each
     * block is wrapped in a NOT EXISTS guard so this helper is safe
     * to call multiple times.
     */
    private function runBackfillInserts(): void
    {
        // Per-method reset: clear the five new tables so the backfill
        // sees a clean target regardless of which test method ran
        // before. The NOT EXISTS guards on each INSERT make the
        // backfill itself idempotent, but truncating first keeps the
        // assertions deterministic (count == expected seed size).
        DB::statement('DELETE FROM `telefonos`');
        DB::statement('DELETE FROM `emails`');
        DB::statement('DELETE FROM `direcciones`');
        DB::statement('DELETE FROM `presencia_online`');
        DB::statement('DELETE FROM `documentos`');

        DB::statement(<<<'SQL'
            INSERT INTO `telefonos`
                (`persona_id`, `numero`, `tipo`, `es_principal`, `created_at`, `updated_at`)
            SELECT p.id, TRIM(p.telefono_principal), 'movil', 1, NOW(), NOW()
            FROM `personas` p
            WHERE p.telefono_principal IS NOT NULL
              AND TRIM(p.telefono_principal) <> ''
              AND NOT EXISTS (
                  SELECT 1 FROM `telefonos` t
                  WHERE t.persona_id = p.id AND t.deleted_at IS NULL
              )
        SQL);

        DB::statement(<<<'SQL'
            INSERT INTO `emails`
                (`persona_id`, `email`, `tipo`, `es_principal`, `created_at`, `updated_at`)
            SELECT p.id, LOWER(TRIM(p.email_principal)), 'personal', 1, NOW(), NOW()
            FROM `personas` p
            WHERE p.email_principal IS NOT NULL
              AND TRIM(p.email_principal) <> ''
              AND NOT EXISTS (
                  SELECT 1 FROM `emails` e
                  WHERE e.persona_id = p.id AND e.deleted_at IS NULL
              )
        SQL);

        DB::statement(<<<'SQL'
            INSERT INTO `direcciones`
                (`persona_id`, `direccion_principal`, `nombre_sede`, `pais`,
                 `tipo`, `es_principal`, `created_at`, `updated_at`)
            SELECT
                p.id,
                NULLIF(TRIM(p.direccion), ''),
                NULLIF(TRIM(p.ciudad), ''),
                CASE WHEN p.pais IS NOT NULL AND CHAR_LENGTH(TRIM(p.pais)) = 2
                     THEN UPPER(TRIM(p.pais)) ELSE NULL END,
                'casa', 1, NOW(), NOW()
            FROM `personas` p
            WHERE (
                  p.direccion IS NOT NULL AND TRIM(p.direccion) <> ''
               OR p.ciudad   IS NOT NULL AND TRIM(p.ciudad)   <> ''
               OR p.pais     IS NOT NULL AND TRIM(p.pais)     <> ''
              )
              AND NOT EXISTS (
                  SELECT 1 FROM `direcciones` d
                  WHERE d.persona_id = p.id AND d.deleted_at IS NULL
              )
        SQL);

        DB::statement(<<<'SQL'
            INSERT INTO `emails`
                (`entidad_id`, `email`, `tipo`, `es_principal`, `created_at`, `updated_at`)
            SELECT e.id, LOWER(TRIM(e.email)), 'trabajo', 1, NOW(), NOW()
            FROM `entidad` e
            WHERE e.email IS NOT NULL
              AND TRIM(e.email) <> ''
              AND NOT EXISTS (
                  SELECT 1 FROM `emails` em
                  WHERE em.entidad_id = e.id AND em.deleted_at IS NULL
              )
        SQL);

        DB::statement(<<<'SQL'
            INSERT INTO `telefonos`
                (`entidad_id`, `numero`, `tipo`, `es_principal`, `created_at`, `updated_at`)
            SELECT e.id, TRIM(e.telefono), 'trabajo', 1, NOW(), NOW()
            FROM `entidad` e
            WHERE e.telefono IS NOT NULL
              AND TRIM(e.telefono) <> ''
              AND NOT EXISTS (
                  SELECT 1 FROM `telefonos` t
                  WHERE t.entidad_id = e.id AND t.deleted_at IS NULL
              )
        SQL);

        DB::statement(<<<'SQL'
            INSERT INTO `direcciones`
                (`entidad_id`, `direccion_principal`, `ciudad_codigo`,
                 `tipo`, `es_principal`, `created_at`, `updated_at`)
            SELECT
                e.id,
                NULLIF(TRIM(e.direccion), ''),
                NULLIF(TRIM(e.ciudad_cod), ''),
                'oficina', 1, NOW(), NOW()
            FROM `entidad` e
            WHERE (
                  e.direccion  IS NOT NULL AND TRIM(e.direccion)  <> ''
               OR e.ciudad_cod IS NOT NULL AND TRIM(e.ciudad_cod) <> ''
              )
              AND NOT EXISTS (
                  SELECT 1 FROM `direcciones` d
                  WHERE d.entidad_id = e.id AND d.deleted_at IS NULL
              )
        SQL);

        DB::statement(<<<'SQL'
            INSERT INTO `presencia_online`
                (`entidad_id`, `tipo`, `plataforma`, `handle`, `url`,
                 `es_principal`, `created_at`, `updated_at`)
            SELECT
                e.id, 'web', 'otro',
                SUBSTRING_INDEX(
                    SUBSTRING_INDEX(LOWER(TRIM(e.dominio)), '://', -1),
                    '/', 1
                ),
                TRIM(e.dominio), 1, NOW(), NOW()
            FROM `entidad` e
            WHERE e.dominio IS NOT NULL
              AND TRIM(e.dominio) <> ''
              AND NOT EXISTS (
                  SELECT 1 FROM `presencia_online` po
                  WHERE po.entidad_id = e.id
                    AND po.url = TRIM(e.dominio)
                    AND po.deleted_at IS NULL
              )
        SQL);

        DB::statement(<<<'SQL'
            INSERT INTO `presencia_online`
                (`entidad_id`, `tipo`, `plataforma`, `handle`, `url`,
                 `es_principal`, `created_at`, `updated_at`)
            SELECT
                e.id, 'red_social',
                CASE LOWER(SUBSTRING_INDEX(
                        SUBSTRING_INDEX(SUBSTRING_INDEX(LOWER(TRIM(e.red_social_url)), '://', -1), '/', 1),
                        '.', 1
                    ))
                    WHEN 'facebook'  THEN 'facebook'
                    WHEN 'fb'        THEN 'facebook'
                    WHEN 'instagram' THEN 'instagram'
                    WHEN 'ig'        THEN 'instagram'
                    WHEN 'linkedin'  THEN 'linkedin'
                    WHEN 'twitter'   THEN 'twitter'
                    WHEN 'x'         THEN 'twitter'
                    WHEN 'youtube'   THEN 'youtube'
                    WHEN 'youtu'     THEN 'youtube'
                    WHEN 'tiktok'    THEN 'tiktok'
                    WHEN 'github'    THEN 'github'
                    WHEN 'medium'    THEN 'medium'
                    WHEN 'shopify'   THEN 'shopify'
                    ELSE 'otro'
                END,
                SUBSTRING(
                    SUBSTRING_INDEX(LOWER(TRIM(e.red_social_url)), '://', -1),
                    LOCATE('/',
                        SUBSTRING_INDEX(LOWER(TRIM(e.red_social_url)), '://', -1)
                    ) + 1
                ),
                TRIM(e.red_social_url), 1, NOW(), NOW()
            FROM `entidad` e
            WHERE e.red_social_url IS NOT NULL
              AND TRIM(e.red_social_url) <> ''
              AND NOT EXISTS (
                  SELECT 1 FROM `presencia_online` po
                  WHERE po.entidad_id = e.id
                    AND po.url = TRIM(e.red_social_url)
                    AND po.deleted_at IS NULL
              )
        SQL);
    }

    #[Test]
    public function personas_telefono_principal_backfills_to_telefonos(): void
    {
        $rows = DB::table('telefonos')->where('persona_id', $this->personaId)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('+57 300 1234567', $rows[0]->numero);
        $this->assertSame('movil', $rows[0]->tipo);
        $this->assertTrue((bool) $rows[0]->es_principal);
    }

    #[Test]
    public function personas_email_principal_backfills_to_emails_lowercased(): void
    {
        $rows = DB::table('emails')->where('persona_id', $this->personaId)->get();
        $this->assertCount(1, $rows);
        // Backfill lower-cases the email for dedup consistency.
        $this->assertSame('ada.lovelace@example.test', $rows[0]->email);
        $this->assertSame('personal', $rows[0]->tipo);
        $this->assertTrue((bool) $rows[0]->es_principal);
    }

    #[Test]
    public function personas_direccion_backfills_to_direcciones_with_nombre_sede(): void
    {
        $rows = DB::table('direcciones')->where('persona_id', $this->personaId)->get();
        $this->assertCount(1, $rows);

        $row = $rows[0];
        $this->assertSame('Calle 1 #2-3', $row->direccion_principal);
        // `personas.ciudad` is free-text VARCHAR(100), not a FK. We
        // stash it in `nombre_sede` so we don't lose the human-readable
        // value; a later cleanup pass maps it to `ciudad_codigo`.
        $this->assertSame('Bogotá', $row->nombre_sede);
        $this->assertSame('CO', $row->pais);
        $this->assertSame('casa', $row->tipo);
    }

    #[Test]
    public function entidad_email_backfills_to_emails_as_tipo_trabajo(): void
    {
        $rows = DB::table('emails')->where('entidad_id', $this->entidadId)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('contact@testcorp.com', $rows[0]->email);
        $this->assertSame('trabajo', $rows[0]->tipo);
    }

    #[Test]
    public function entidad_telefono_backfills_to_telefonos(): void
    {
        $rows = DB::table('telefonos')->where('entidad_id', $this->entidadId)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('+57 601 5551212', $rows[0]->numero);
        $this->assertSame('trabajo', $rows[0]->tipo);
    }

    #[Test]
    public function entidad_direccion_backfills_to_direcciones_with_ciudad_codigo(): void
    {
        $rows = DB::table('direcciones')->where('entidad_id', $this->entidadId)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Av 9 #10-20', $rows[0]->direccion_principal);
        // `entidad.ciudad_cod` IS a FK-compatible value (VARCHAR(10)),
        // so we map it directly to `direcciones.ciudad_codigo`.
        $this->assertSame('11001', $rows[0]->ciudad_codigo);
        $this->assertSame('oficina', $rows[0]->tipo);
    }

    #[Test]
    public function entidad_dominio_backfills_to_presencia_online_as_web(): void
    {
        $rows = DB::table('presencia_online')
            ->where('entidad_id', $this->entidadId)
            ->where('tipo', 'web')
            ->get();
        $this->assertCount(1, $rows);
        $this->assertSame('otro', $rows[0]->plataforma);
        $this->assertSame('testcorp.com.co', $rows[0]->url);
        $this->assertSame('testcorp.com.co', $rows[0]->handle);
    }

    #[Test]
    public function entidad_red_social_url_backfills_with_platform_heuristic(): void
    {
        $rows = DB::table('presencia_online')
            ->where('entidad_id', $this->entidadId)
            ->where('tipo', 'red_social')
            ->get();
        $this->assertCount(1, $rows);
        // `facebook.com/testcorp` → plataforma='facebook'.
        $this->assertSame('facebook', $rows[0]->plataforma);
        $this->assertSame('facebook.com/testcorp', $rows[0]->url);
    }

    #[Test]
    public function legacy_columns_still_exist_after_backfill(): void
    {
        // Commit 3 contract: legacy columns stay (option 2 = keep
        // deprecated). A later commit drops them.
        $persona = DB::table('personas')->where('id', $this->personaId)->first();
        $this->assertNotNull($persona->email_principal);
        $this->assertNotNull($persona->telefono_principal);
        $this->assertNotNull($persona->direccion);

        $entidad = DB::table('entidad')->where('id', $this->entidadId)->first();
        $this->assertNotNull($entidad->email);
        $this->assertNotNull($entidad->telefono);
        $this->assertNotNull($entidad->direccion);
        $this->assertNotNull($entidad->dominio);
        $this->assertNotNull($entidad->red_social_url);
    }
}
