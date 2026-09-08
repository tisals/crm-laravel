<?php

namespace Tests\Feature\API;

use App\Models\Ciudad;
use App\Models\Contacto;
use App\Models\Entidad;
use App\Models\Oportunidad;
use App\Models\Permiso;
use App\Models\Persona as PersonaModel;
use App\Models\Rol;
use App\Models\Seguimiento;
use App\Models\Usuario;
use Database\Seeders\PipelineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Commit 6 (tenant-data-model-correction) — depth projection tests.
 *
 * Covers the `?depth=1|2|3` query param on the five canonical read
 * endpoints. Each endpoint exercises four cases:
 *
 *   - ?depth=1 (Shallow): bare identity, NO direct relations
 *   - ?depth=2 (Default, canonical): identity + direct relations
 *   - ?depth=3 (Deep):    identity + direct + second-degree relations
 *   - missing ?depth=:    defaults to depth=2
 *   - ?depth=99:          clamps to default (depth=2) — no 4xx
 */
class DepthProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PipelineSeeder::class);
    }

    private function authenticate(): string
    {
        $rol = Rol::create(['nombre' => 'Admin', 'estado' => 'Activo']);
        Permiso::create(['rol_id' => $rol->id, 'vista' => '*']);

        $usuario = Usuario::create([
            'nombre' => 'Admin User',
            'email' => 'admin-'.uniqid().'@test.local',
            'password_hash' => bcrypt('password123'),
            'rol_id' => $rol->id,
            'estado' => 'Activo',
        ]);

        return $usuario->createToken('test-token')->plainTextToken;
    }

    /**
     * Canonical fixture for follow-ups: an entidad, a persona, a contacto
     * linked to the persona, and an oportunidad linked to the contacto.
     *
     * @return array{entidad: Entidad, persona: PersonaModel, contacto: Contacto, oportunidad: Oportunidad}
     */
    private function createReferences(): array
    {
        if (! Ciudad::where('cod_municipio', '05001')->exists()) {
            Ciudad::create([
                'cod_municipio' => '05001',
                'nombre' => 'Medellín',
                'departamento' => 'Antioquia',
            ]);
        }

        $entidad = Entidad::create([
            'tipo_persona' => 'Juridica',
            'nombre' => 'Acme Depth',
            'identificacion' => 'DEPTH-'.uniqid(),
            'estado' => 'Activo',
        ]);
        $persona = PersonaModel::create([
            'nombres' => 'Ada',
            'apellidos' => 'Lovelace',
            'email_principal' => 'ada@depth.test',
            'entidad_id' => $entidad->id,
        ]);

        // Pivot row so persona → entidad binding resolves (per Commit 5+).
        DB::table('entidad_persona')->insert([
            'entidad_id' => $entidad->id,
            'persona_id' => $persona->id,
            'categoria' => 'asignacion',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contacto = Contacto::create([
            'entidad_id' => $entidad->id,
            'persona_id' => $persona->id,
            'nombres' => $persona->nombres,
            'apellidos' => $persona->apellidos,
            'email_contacto' => 'ada@depth.test',
            'estado' => 'Activo',
        ]);

        $oportunidad = Oportunidad::create([
            'codigo' => 'GD-'.uniqid(),
            'entidad_id' => $entidad->id,
            'persona_id' => $contacto->persona_id,
            'fecha' => '2026-05-10',
            'estado' => 'Activa',
            'is_latest' => true,
        ]);

        return [
            'entidad' => $entidad,
            'persona' => $persona,
            'contacto' => $contacto,
            'oportunidad' => $oportunidad,
        ];
    }

    // ─── PERSONA ────────────────────────────────────────────────────────

    #[Test]
    public function persona_show_with_depth_1_returns_bare_identity(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/personas/{$refs['persona']->id}?depth=1");

        $r->assertStatus(200)
            ->assertJsonPath('data.id', $refs['persona']->id)
            ->assertJsonPath('data.nombres', 'Ada');

        // Shallow: NO relations block, NO primary-email lookup.
        $r->assertJsonMissingPath('data.relations');
        $r->assertJsonMissingPath('data.email_principal');
        $r->assertJsonMissingPath('data.telefono_principal');
        $r->assertJsonMissingPath('data.direccion');
        $r->assertJsonMissingPath('data.entidad');
    }

    #[Test]
    public function persona_show_with_depth_2_includes_direct_relations(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/personas/{$refs['persona']->id}?depth=2");

        $r->assertStatus(200)
            ->assertJsonPath('data.id', $refs['persona']->id)
            ->assertJsonPath('data.relations.contacto_id', $refs['contacto']->id)
            ->assertJsonPath('data.relations.entidad_id', $refs['entidad']->id);

        // Default depth: NO nested entidad snapshot (that's depth=3).
        $r->assertJsonMissingPath('data.entidad');
    }

    #[Test]
    public function persona_show_with_depth_3_includes_nested_entidad_snapshot(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/personas/{$refs['persona']->id}?depth=3");

        $r->assertStatus(200)
            ->assertJsonPath('data.id', $refs['persona']->id)
            ->assertJsonPath('data.relations.entidad_id', $refs['entidad']->id)
            ->assertJsonPath('data.entidad.id', $refs['entidad']->id)
            ->assertJsonPath('data.entidad.nombre', $refs['entidad']->nombre);
    }

    #[Test]
    public function persona_show_with_missing_depth_defaults_to_depth_2(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/personas/{$refs['persona']->id}");

        $r->assertStatus(200)
            ->assertJsonPath('data.id', $refs['persona']->id)
            ->assertJsonPath('data.relations.entidad_id', $refs['entidad']->id);

        $r->assertJsonMissingPath('data.entidad');
    }

    #[Test]
    public function persona_show_with_invalid_depth_clamps_to_default(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/personas/{$refs['persona']->id}?depth=99");

        $r->assertStatus(200)
            ->assertJsonPath('data.id', $refs['persona']->id)
            ->assertJsonPath('data.relations.entidad_id', $refs['entidad']->id);

        // Clamped to depth=2: NO nested entidad snapshot.
        $r->assertJsonMissingPath('data.entidad');
    }

    // ─── OPORTUNIDAD ────────────────────────────────────────────────────

    #[Test]
    public function oportunidad_show_with_depth_1_returns_bare_identity(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/oportunidades/{$refs['oportunidad']->id}?depth=1");

        $r->assertStatus(200)
            ->assertJsonPath('data.id', $refs['oportunidad']->id)
            ->assertJsonPath('data.codigo', $refs['oportunidad']->codigo);

        // Shallow: NO detalles[], NO nested entidad.
        $r->assertJsonMissingPath('data.detalles');
        $r->assertJsonMissingPath('data.valor');
        $r->assertJsonMissingPath('data.entidad');
    }

    #[Test]
    public function oportunidad_show_with_depth_2_includes_direct_relations(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/oportunidades/{$refs['oportunidad']->id}?depth=2");

        $r->assertStatus(200)
            ->assertJsonPath('data.id', $refs['oportunidad']->id)
            ->assertJsonPath('data.entidad_nombre', $refs['entidad']->nombre);

        // Default: detalles present, but NO nested entidad object.
        $r->assertJsonStructure(['data' => ['detalles']]);
        $r->assertJsonMissingPath('data.entidad');
    }

    #[Test]
    public function oportunidad_show_with_depth_3_includes_nested_entidad_object(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/oportunidades/{$refs['oportunidad']->id}?depth=3");

        $r->assertStatus(200)
            ->assertJsonPath('data.id', $refs['oportunidad']->id)
            ->assertJsonPath('data.entidad.id', $refs['entidad']->id)
            ->assertJsonPath('data.entidad.nombre', $refs['entidad']->nombre);
    }

    #[Test]
    public function oportunidad_index_with_invalid_depth_clamps_to_default(): void
    {
        $token = $this->authenticate();
        $this->createReferences();

        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/oportunidades?depth=0');

        // depth=0 is out-of-range; clamp to Default.
        $r->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function oportunidad_index_with_depth_1_omits_eager_loaded_relations(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/oportunidades?depth=1');

        $r->assertStatus(200);
        $first = $r->json('data.data.0');

        $this->assertSame($refs['oportunidad']->id, $first['id']);
        $this->assertArrayNotHasKey('detalles', $first, 'depth=1 must not include detalles');
        $this->assertArrayNotHasKey('valor', $first, 'depth=1 must not include valor');
    }

    // ─── ENTIDAD ────────────────────────────────────────────────────────

    #[Test]
    public function entidad_show_with_depth_1_returns_bare_identity(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/entidad/{$refs['entidad']->id}?depth=1");

        $r->assertStatus(200)
            ->assertJsonPath('data.id', $refs['entidad']->id)
            ->assertJsonPath('data.nombre', $refs['entidad']->nombre);

        // Shallow: NO principal-row lookups (direccion/email/telefono/dominio/ciudad_cod),
        // NO nested collections.
        $r->assertJsonMissingPath('data.direccion');
        $r->assertJsonMissingPath('data.email');
        $r->assertJsonMissingPath('data.telefono');
        $r->assertJsonMissingPath('data.dominio');
        $r->assertJsonMissingPath('data.ciudad_cod');
        $r->assertJsonMissingPath('data.direcciones');
        $r->assertJsonMissingPath('data.emails');
    }

    #[Test]
    public function entidad_show_with_depth_2_includes_principal_row_lookups(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        // Insert a primary direccion row so the lookup has something to return.
        DB::table('direcciones')->insert([
            'entidad_id' => $refs['entidad']->id,
            'direccion_principal' => 'Calle 1 #2-3',
            'tipo' => 'oficina',
            'es_principal' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/entidad/{$refs['entidad']->id}?depth=2");

        $r->assertStatus(200)
            ->assertJsonPath('data.id', $refs['entidad']->id)
            ->assertJsonPath('data.direccion', 'Calle 1 #2-3');

        // Default: NO nested collection (that's depth=3).
        $r->assertJsonMissingPath('data.direcciones');
        $r->assertJsonMissingPath('data.emails');
    }

    #[Test]
    public function entidad_show_with_depth_3_includes_nested_collections(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        DB::table('emails')->insert([
            'entidad_id' => $refs['entidad']->id,
            'email' => 'info@depth.test',
            'tipo' => 'trabajo',
            'es_principal' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/entidad/{$refs['entidad']->id}?depth=3");

        $r->assertStatus(200)
            ->assertJsonPath('data.id', $refs['entidad']->id);

        $emails = $r->json('data.emails');
        $this->assertIsArray($emails);
        $this->assertCount(1, $emails);
        $this->assertSame('info@depth.test', $emails[0]['email']);
    }

    #[Test]
    public function entidad_show_with_missing_depth_defaults_to_depth_2(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/entidad/{$refs['entidad']->id}");

        $r->assertStatus(200)
            ->assertJsonPath('data.id', $refs['entidad']->id)
            ->assertJsonStructure(['data' => ['direccion', 'email', 'telefono', 'dominio', 'ciudad_cod']]);

        // Default depth: NO nested collections.
        $r->assertJsonMissingPath('data.direcciones');
    }

    // ─── SEGUIMIENTO ────────────────────────────────────────────────────

    #[Test]
    public function seguimiento_show_with_depth_1_returns_bare_identity(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $seguimiento = Seguimiento::create([
            'oportunidad_id' => $refs['oportunidad']->id,
            'persona_id' => $refs['persona']->id,
            'entidad_id' => $refs['entidad']->id,
            'tipo' => 'Llamada',
            'fecha' => '2026-05-15',
            'autor_id' => Usuario::first()?->id,
        ]);

        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/seguimientos/{$seguimiento->id}?depth=1");

        $r->assertStatus(200)
            ->assertJsonPath('data.id', $seguimiento->id)
            ->assertJsonPath('data.tipo', 'Llamada');

        // Shallow: NO accessors (contacto_nombre, oportunidad_codigo, etc.).
        $r->assertJsonMissingPath('data.entidad_nombre');
        $r->assertJsonMissingPath('data.contacto_nombre');
        $r->assertJsonMissingPath('data.oportunidad_codigo');
        $r->assertJsonMissingPath('data.autor_nombre');
    }

    #[Test]
    public function seguimiento_show_with_depth_2_includes_direct_relation_accessors(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $seguimiento = Seguimiento::create([
            'oportunidad_id' => $refs['oportunidad']->id,
            'persona_id' => $refs['persona']->id,
            'entidad_id' => $refs['entidad']->id,
            'tipo' => 'Llamada',
            'fecha' => '2026-05-15',
        ]);

        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/seguimientos/{$seguimiento->id}?depth=2");

        $r->assertStatus(200)
            ->assertJsonPath('data.id', $seguimiento->id)
            ->assertJsonPath('data.contacto_nombre', "Ada Lovelace");

        // Default: NO nested persona/oportunidad objects.
        $r->assertJsonMissingPath('data.persona');
        $r->assertJsonMissingPath('data.oportunidad');
    }

    #[Test]
    public function seguimiento_show_with_depth_3_includes_nested_persona_and_oportunidad(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $seguimiento = Seguimiento::create([
            'oportunidad_id' => $refs['oportunidad']->id,
            'persona_id' => $refs['persona']->id,
            'entidad_id' => $refs['entidad']->id,
            'tipo' => 'Llamada',
            'fecha' => '2026-05-15',
        ]);

        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/seguimientos/{$seguimiento->id}?depth=3");

        $r->assertStatus(200)
            ->assertJsonPath('data.id', $seguimiento->id)
            ->assertJsonPath('data.persona.id', $refs['persona']->id)
            ->assertJsonPath('data.persona.nombres', 'Ada')
            ->assertJsonPath('data.oportunidad.id', $refs['oportunidad']->id)
            ->assertJsonPath('data.oportunidad.codigo', $refs['oportunidad']->codigo);
    }

    #[Test]
    public function seguimiento_show_with_invalid_depth_clamps_to_default(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $seguimiento = Seguimiento::create([
            'oportunidad_id' => $refs['oportunidad']->id,
            'persona_id' => $refs['persona']->id,
            'entidad_id' => $refs['entidad']->id,
            'tipo' => 'Llamada',
            'fecha' => '2026-05-15',
        ]);

        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/seguimientos/{$seguimiento->id}?depth=abc");

        // Invalid value clamps to depth=2 — no 4xx.
        $r->assertStatus(200)
            ->assertJsonPath('data.contacto_nombre', 'Ada Lovelace');
    }

    // ─── CONTACTO ───────────────────────────────────────────────────────

    #[Test]
    public function contacto_show_with_depth_1_returns_bare_identity(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/contacto/{$refs['contacto']->id}?depth=1");

        $r->assertStatus(200)
            ->assertJsonPath('data.id', $refs['contacto']->id)
            ->assertJsonPath('data.nombres', 'Ada');

        // Shallow: NO entidad_id pivot lookup, NO entidad_nombre accessor.
        $r->assertJsonMissingPath('data.entidad_nombre');
        $r->assertJsonMissingPath('data.persona');
        $r->assertJsonMissingPath('data.entidad');
    }

    #[Test]
    public function contacto_show_with_depth_2_includes_entidad_binding(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/contacto/{$refs['contacto']->id}?depth=2");

        $r->assertStatus(200)
            ->assertJsonPath('data.id', $refs['contacto']->id)
            ->assertJsonPath('data.entidad_id', $refs['entidad']->id)
            ->assertJsonPath('data.entidad_nombre', $refs['entidad']->nombre);

        // Default: NO nested persona/entidad objects.
        $r->assertJsonMissingPath('data.persona');
        $r->assertJsonMissingPath('data.entidad');
    }

    #[Test]
    public function contacto_show_with_depth_3_includes_nested_persona_and_entidad(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/contacto/{$refs['contacto']->id}?depth=3");

        $r->assertStatus(200)
            ->assertJsonPath('data.id', $refs['contacto']->id)
            ->assertJsonPath('data.persona.id', $refs['persona']->id)
            ->assertJsonPath('data.persona.nombres', 'Ada')
            ->assertJsonPath('data.entidad.id', $refs['entidad']->id)
            ->assertJsonPath('data.entidad.nombre', $refs['entidad']->nombre);
    }

    #[Test]
    public function contacto_show_with_missing_depth_defaults_to_depth_2(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/contacto/{$refs['contacto']->id}");

        $r->assertStatus(200)
            ->assertJsonPath('data.id', $refs['contacto']->id)
            ->assertJsonPath('data.entidad_id', $refs['entidad']->id);
    }

    #[Test]
    public function contacto_index_with_depth_1_returns_bare_items(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/contacto?depth=1');

        $r->assertStatus(200);

        $items = $r->json('data.data');
        $this->assertIsArray($items);

        $first = collect($items)->firstWhere('id', $refs['contacto']->id);
        $this->assertNotNull($first, 'fixture contacto must appear in the index');
        $this->assertSame('Ada', $first['nombres']);
        $this->assertArrayNotHasKey('entidad_nombre', $first);
    }

    #[Test]
    public function persona_index_with_depth_1_returns_bare_items(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/personas?depth=1');

        $r->assertStatus(200);

        $items = $r->json('data.data');
        $this->assertIsArray($items);

        // The authenticate() helper auto-creates a persona for the
        // usuario (per the Usuario::booted() hook), so there will be
        // at least one extra persona in the list. Find the Ada one.
        $ada = collect($items)->firstWhere('id', $refs['persona']->id);
        $this->assertNotNull($ada, 'fixture persona must appear in the index');

        // Bare identity — NO relations, NO primary-email lookup.
        $this->assertSame('Ada', $ada['nombres']);
        $this->assertArrayNotHasKey('relations', $ada);
        $this->assertArrayNotHasKey('email_principal', $ada);
    }

    #[Test]
    public function entidad_index_with_depth_1_returns_bare_items(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/entidad?depth=1');

        $r->assertStatus(200);

        $items = $r->json('data.data');
        $this->assertIsArray($items);

        $acme = collect($items)->firstWhere('id', $refs['entidad']->id);
        $this->assertNotNull($acme, 'fixture entidad must appear in the index');
        $this->assertSame('Acme Depth', $acme['nombre']);
        $this->assertArrayNotHasKey('direccion', $acme);
        $this->assertArrayNotHasKey('email', $acme);
    }

    #[Test]
    public function seguimiento_index_with_depth_1_returns_bare_items(): void
    {
        $token = $this->authenticate();
        $refs = $this->createReferences();

        $seguimiento = Seguimiento::create([
            'oportunidad_id' => $refs['oportunidad']->id,
            'persona_id' => $refs['persona']->id,
            'entidad_id' => $refs['entidad']->id,
            'tipo' => 'Llamada',
            'fecha' => '2026-05-15',
        ]);

        $r = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/seguimientos?depth=1');

        $r->assertStatus(200);

        $items = $r->json('data.data');
        $this->assertIsArray($items);

        $seg = collect($items)->firstWhere('id', $seguimiento->id);
        $this->assertNotNull($seg, 'fixture seguimiento must appear in the index');
        $this->assertSame('Llamada', $seg['tipo']);
        $this->assertArrayNotHasKey('contacto_nombre', $seg);
        $this->assertArrayNotHasKey('entidad_nombre', $seg);
    }
}