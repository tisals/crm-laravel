<?php

namespace Tests\Feature\Migration;

use App\Models\Entidad;
use App\Models\Persona;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\CRM\Models\BotSession;
use Modules\CRM\Models\Oportunidad;
use Modules\CRM\Models\Seguimiento;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PR-B: bot_sessions table + seguimiento bot-fact columns.
 *
 * Covers REQ-ISCF-001 (bot_sessions table + 13 cols + 3 FKs + 2 indexes +
 * unique session_key + soft deletes), REQ-ISCF-002 (seguimiento bot columns
 * nullable + existing rows preserved), REQ-ISCF-005 (full reversibility).
 *
 * Strict TDD: every test asserts real behavior ÔÇö column existence, FK
 * actions, unique constraints, default values, JSON casts, soft deletes,
 * and reversible migrations. No smoke tests, no trivial assertions.
 *
 * RED tasks 1b.1ÔÇô1b.5 fail before any production code ships. GREEN tasks
 * 1b.6ÔÇô1b.9 (migrations + models) make these tests pass.
 */
class SchemaFoundationTest extends TestCase
{
    use RefreshDatabase;

    // ÔöÇÔöÇ 1b.1 ÔÇö bot_sessions table exists with full schema ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ

    #[Test]
    public function bot_sessions_table_exists_with_all_columns(): void
    {
        $this->assertTrue(Schema::hasTable('bot_sessions'), 'bot_sessions table must exist');

        // 11 data columns + 2 timestamp columns + 1 soft-delete column.
        $expected = [
            'id',
            'session_key',
            'entidad_id',
            'brand_slug',
            'profile_slug',
            'persona_id',
            'oportunidad_id',
            'estado',
            'started_at',
            'ended_at',
            'metadata',
            'created_at',
            'updated_at',
            'deleted_at',
        ];

        foreach ($expected as $column) {
            $this->assertTrue(
                Schema::hasColumn('bot_sessions', $column),
                "bot_sessions.{$column} column must exist"
            );
        }
    }

    #[Test]
    public function bot_sessions_has_expected_indexes(): void
    {
        $indexes = Schema::getIndexes('bot_sessions');

        $names = array_map(fn ($i) => $i['name'], $indexes);

        $this->assertContains(
            'bot_sessions_brand_slug_profile_slug_index',
            $names,
            'Expected composite index (brand_slug, profile_slug)'
        );
        $this->assertContains(
            'bot_sessions_estado_started_at_index',
            $names,
            'Expected composite index (estado, started_at)'
        );

        $uniqueOnSessionKey = array_filter(
            $indexes,
            fn ($i) => $i['unique'] && in_array('session_key', $i['columns'], true)
        );

        $this->assertNotEmpty(
            $uniqueOnSessionKey,
            'Expected UNIQUE index on session_key'
        );
    }

    // ÔöÇÔöÇ 1b.2 ÔÇö session_key is UNIQUE ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ

    #[Test]
    public function bot_sessions_session_key_is_unique(): void
    {
        $payload = [
            'session_key' => 'duplicate-uuid-12345',
            'brand_slug' => 'hermes',
            'profile_slug' => 'setter-safe-health',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            'estado' => 'Activa',
        ];

        DB::table('bot_sessions')->insert($payload);

        $this->expectException(QueryException::class);
        DB::table('bot_sessions')->insert($payload);
    }

    // ÔöÇÔöÇ 1b.3 ÔÇö estado defaults to 'Activa' + metadata round-trips JSON ÔöÇ

    #[Test]
    public function bot_sessions_estado_defaults_to_activa(): void
    {
        DB::table('bot_sessions')->insert([
            'session_key' => 'uuid-default-estado',
            'brand_slug' => 'hermes',
            'profile_slug' => 'setter-safe-health',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('bot_sessions')->where('session_key', 'uuid-default-estado')->first();

        $this->assertSame(
            'Activa',
            $row->estado,
            'estado must default to "Activa"'
        );
    }

    #[Test]
    public function bot_sessions_metadata_round_trips_as_array_via_model_cast(): void
    {
        $meta = ['source' => 'webhook', 'attempt' => 1, 'tags' => ['a', 'b']];

        $session = BotSession::create([
            'session_key' => 'uuid-metadata-roundtrip',
            'brand_slug' => 'hermes',
            'profile_slug' => 'marketing-sailus',
            'metadata' => $meta,
            'started_at' => now(),
        ]);

        $reloaded = BotSession::find($session->id);

        $this->assertSame(
            $meta,
            $reloaded->metadata,
            'metadata must round-trip as an array (cast on read)'
        );
    }

    // ÔöÇÔöÇ 1b.4 ÔÇö seguimiento bot columns exist + nullable ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ

    #[Test]
    public function seguimiento_bot_columns_exist_and_are_nullable(): void
    {
        $this->assertTrue(Schema::hasColumn('seguimiento', 'bot_fact_type'));
        $this->assertTrue(Schema::hasColumn('seguimiento', 'bot_confidence'));
        $this->assertTrue(Schema::hasColumn('seguimiento', 'bot_source_profile'));
    }

    #[Test]
    public function seguimiento_bot_columns_accept_null_on_insert(): void
    {
        // REQ-ISCF-002: existing rows (and new ones without bot facts) must
        // accept NULL on all three new columns.
        $entidad = Entidad::create([
            'tipo_persona' => 'Natural',
            'nombre' => 'PR-B Test',
            'estado' => 'Activo',
            'identificacion' => 'PR-B-001',
        ]);

        $seguimiento = Seguimiento::create([
            'entidad_id' => $entidad->id,
            'tipo' => 'Nota',
            'fecha' => '2026-08-01',
            'estado' => 'Pendiente',
        ]);

        $reloaded = Seguimiento::find($seguimiento->id);

        $this->assertNull($reloaded->bot_fact_type, 'bot_fact_type must be NULL by default');
        $this->assertNull($reloaded->bot_confidence, 'bot_confidence must be NULL by default');
        $this->assertNull($reloaded->bot_source_profile, 'bot_source_profile must be NULL by default');
    }

    // ÔöÇÔöÇ 1b.5 ÔÇö both migrations reverse cleanly ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ

    #[Test]
    public function both_migrations_reverse_cleanly(): void
    {
        // Precondition: both the table and the columns exist (forces RED
        // when either migration is missing).
        $this->assertTrue(
            Schema::hasTable('bot_sessions'),
            'precondition: bot_sessions should exist before rollback'
        );
        $this->assertTrue(
            Schema::hasColumn('seguimiento', 'bot_fact_type'),
            'precondition: bot_fact_type should exist before rollback'
        );

        // Roll back the most recent two migrations. By timestamp ordering
        // these are: 2026_08_28_000002_create_bot_sessions_table and
        // 2026_08_28_000003_add_bot_columns_to_seguimiento_table.
        $this->artisan('migrate:rollback', ['--step' => 2])->assertExitCode(0);

        $this->assertFalse(
            Schema::hasTable('bot_sessions'),
            'bot_sessions must be dropped after rollback'
        );
        $this->assertFalse(
            Schema::hasColumn('seguimiento', 'bot_fact_type'),
            'seguimiento.bot_fact_type must be gone after rollback'
        );
        $this->assertFalse(
            Schema::hasColumn('seguimiento', 'bot_confidence'),
            'seguimiento.bot_confidence must be gone after rollback'
        );
        $this->assertFalse(
            Schema::hasColumn('seguimiento', 'bot_source_profile'),
            'seguimiento.bot_source_profile must be gone after rollback'
        );
    }

    // ÔöÇÔöÇ 1b.7 ÔÇö BotSession model relations ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ

    #[Test]
    public function bot_session_belongs_to_entidad(): void
    {
        $entidad = Entidad::create([
            'tipo_persona' => 'Natural',
            'nombre' => 'Parent',
            'estado' => 'Activo',
            'identificacion' => 'PR-B-REL-001',
        ]);

        $session = BotSession::create([
            'session_key' => 'uuid-rel-entidad',
            'entidad_id' => $entidad->id,
            'brand_slug' => 'hermes',
            'profile_slug' => 'setter-tis',
            'started_at' => now(),
        ]);

        $this->assertSame(
            (int) $entidad->id,
            (int) $session->fresh()->entidad->id,
            'BotSession.entidad must resolve the related Entidad'
        );
    }

    #[Test]
    public function bot_session_belongs_to_persona(): void
    {
        $persona = Persona::create([
            'nombres' => 'Bot',
            'apellidos' => 'Sessioner',
            'email_principal' => 'bot-sessioner@example.test',
        ]);

        $session = BotSession::create([
            'session_key' => 'uuid-rel-persona',
            'persona_id' => $persona->id,
            'brand_slug' => 'hermes',
            'profile_slug' => 'marketing-sailus',
            'started_at' => now(),
        ]);

        $this->assertSame(
            (int) $persona->id,
            (int) $session->fresh()->persona->id,
            'BotSession.persona must resolve the related Persona'
        );
    }

    #[Test]
    public function bot_session_belongs_to_oportunidad(): void
    {
        // Oportunidad.entidad_id is NOT NULL in the schema, so we must
        // attach a parent entidad before proving the BotSession->oportunidad
        // relation resolves correctly.
        $entidad = Entidad::create([
            'tipo_persona' => 'Natural',
            'nombre' => 'Op Parent',
            'estado' => 'Activo',
            'identificacion' => 'PR-B-REL-002',
        ]);

        $oportunidad = Oportunidad::create([
            'codigo' => 'OPP-BS-001',
            'entidad_id' => $entidad->id,
            'fecha' => '2026-08-01',
            'estado' => 'Borrador',
        ]);

        $session = BotSession::create([
            'session_key' => 'uuid-rel-oportunidad',
            'oportunidad_id' => $oportunidad->id,
            'brand_slug' => 'hermes',
            'profile_slug' => 'sst-support-safe-health',
            'started_at' => now(),
        ]);

        $this->assertSame(
            (int) $oportunidad->id,
            (int) $session->fresh()->oportunidad->id,
            'BotSession.oportunidad must resolve the related Oportunidad'
        );
    }

    // ÔöÇÔöÇ Triangulation: FK nullOnDelete + soft deletes + casts ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ

    #[Test]
    public function bot_session_fk_to_entidad_is_null_on_delete(): void
    {
        $entidad = Entidad::create([
            'tipo_persona' => 'Natural',
            'nombre' => 'FK NullDelete',
            'estado' => 'Activo',
            'identificacion' => 'PR-B-NULL-001',
        ]);

        $session = BotSession::create([
            'session_key' => 'uuid-null-delete',
            'entidad_id' => $entidad->id,
            'brand_slug' => 'hermes',
            'profile_slug' => 'setter-safe-health',
            'started_at' => now(),
        ]);

        // Sanity: FK is wired before delete.
        $this->assertSame((int) $entidad->id, (int) $session->fresh()->entidad_id);

        // Hard-delete parent (forceDelete bypasses SoftDeletes).
        $entidad->forceDelete();

        // nullOnDelete: session row keeps existing with entidad_id = null.
        $survivor = BotSession::withTrashed()->find($session->id);
        $this->assertNotNull($survivor, 'BotSession must survive parent entidad delete');
        $this->assertNull(
            $survivor->entidad_id,
            'BotSession.entidad_id must be null after parent delete (nullOnDelete)'
        );
    }

    #[Test]
    public function bot_session_supports_soft_deletes(): void
    {
        $session = BotSession::create([
            'session_key' => 'uuid-soft-delete',
            'brand_slug' => 'hermes',
            'profile_slug' => 'marketing-sailus',
            'started_at' => now(),
        ]);

        $id = $session->id;

        $session->delete();

        // Default scope hides it, but withTrashed brings it back.
        $this->assertNull(
            BotSession::find($id),
            'Soft-deleted session must be hidden from default query'
        );
        $this->assertNotNull(
            BotSession::withTrashed()->find($id),
            'Soft-deleted session must still exist with withTrashed()'
        );
        $this->assertNotNull(
            BotSession::withTrashed()->find($id)->deleted_at,
            'deleted_at must be set after soft delete'
        );
    }

    #[Test]
    public function seguimiento_bot_confidence_is_cast_to_decimal_3(): void
    {
        $entidad = Entidad::create([
            'tipo_persona' => 'Natural',
            'nombre' => 'Cast Test',
            'estado' => 'Activo',
            'identificacion' => 'PR-B-CAST-001',
        ]);

        $seguimiento = Seguimiento::create([
            'entidad_id' => $entidad->id,
            'tipo' => 'Nota',
            'fecha' => '2026-08-01',
            'estado' => 'Pendiente',
            'bot_fact_type' => 'intencion_compra',
            'bot_confidence' => 0.85,
            'bot_source_profile' => 'setter-safe-health',
        ]);

        $reloaded = Seguimiento::find($seguimiento->id);

        $this->assertSame(
            '0.850',
            (string) $reloaded->bot_confidence,
            'bot_confidence must be cast as decimal:3 ("0.850")'
        );
        $this->assertSame('intencion_compra', $reloaded->bot_fact_type);
        $this->assertSame('setter-safe-health', $reloaded->bot_source_profile);
    }

    #[Test]
    public function bot_sessions_ended_at_accepts_null_and_values(): void
    {
        $session = BotSession::create([
            'session_key' => 'uuid-ended-null',
            'brand_slug' => 'hermes',
            'profile_slug' => 'setter-tis',
            'started_at' => now(),
            // ended_at omitted ÔÇö must be NULL
        ]);

        $this->assertNull(
            $session->fresh()->ended_at,
            'ended_at must default to NULL when omitted'
        );

        $end = now()->addHour();
        $session2 = BotSession::create([
            'session_key' => 'uuid-ended-set',
            'brand_slug' => 'hermes',
            'profile_slug' => 'setter-tis',
            'started_at' => now(),
            'ended_at' => $end,
        ]);

        $this->assertNotNull(
            $session2->fresh()->ended_at,
            'ended_at must persist when set'
        );
    }
}
