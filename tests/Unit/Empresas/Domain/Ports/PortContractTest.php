<?php

namespace Tests\Unit\Empresas\Domain\Ports;

use App\Empresas\Domain\Ports\EnriquecimientoRepository;
use App\Empresas\Domain\Ports\McpEmpresaClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * PR3 of `complementar-entidad` — work-unit 3.3 (RED).
 *
 * The Domain/Ports layer defines the CONTRACT that the application
 * service depends on (Dependency Inversion). These tests pin the
 * method signatures via Reflection so future refactors cannot silently
 * change the wire contract used by `EnriquecerEmpresaService` (PR4).
 */
class PortContractTest extends TestCase
{
    // ── McpEmpresaClient ───────────────────────────────────────────────

    #[Test]
    public function mcp_empresa_client_is_an_interface(): void
    {
        $reflection = new ReflectionClass(McpEmpresaClient::class);
        $this->assertTrue($reflection->isInterface(), 'McpEmpresaClient MUST be a PHP interface.');
    }

    #[Test]
    public function mcp_empresa_client_exposes_buscar_por_dominio(): void
    {
        $reflection = new ReflectionClass(McpEmpresaClient::class);
        $this->assertTrue($reflection->hasMethod('buscarPorDominio'));

        $m = $reflection->getMethod('buscarPorDominio');
        $this->assertCount(1, $m->getParameters(), 'buscarPorDominio takes exactly 1 parameter.');
        $this->assertSame('dominio', $m->getParameters()[0]->getName());
        $this->assertSame('string', (string) $m->getParameters()[0]->getType());

        $returnType = (string) $m->getReturnType();
        $this->assertSame('array', $returnType, 'buscarPorDominio returns array (list of EmpresaCandidato).');
    }

    #[Test]
    public function mcp_empresa_client_exposes_consultar_enriquecida(): void
    {
        $reflection = new ReflectionClass(McpEmpresaClient::class);
        $this->assertTrue($reflection->hasMethod('consultarEnriquecida'));

        $m = $reflection->getMethod('consultarEnriquecida');
        $this->assertCount(1, $m->getParameters());
        $this->assertSame('razon_social_o_nit', $m->getParameters()[0]->getName());
        $this->assertSame('string', (string) $m->getParameters()[0]->getType());
    }

    // ── EnriquecimientoRepository ─────────────────────────────────────

    #[Test]
    public function enriquecimiento_repository_is_an_interface(): void
    {
        $reflection = new ReflectionClass(EnriquecimientoRepository::class);
        $this->assertTrue($reflection->isInterface(), 'EnriquecimientoRepository MUST be a PHP interface.');
    }

    #[Test]
    public function enriquecimiento_repository_exposes_upsert(): void
    {
        $reflection = new ReflectionClass(EnriquecimientoRepository::class);
        $this->assertTrue($reflection->hasMethod('upsert'));

        $m = $reflection->getMethod('upsert');
        $this->assertCount(2, $m->getParameters(), 'upsert(int $entidadId, EmpresaEnriquecida $data).');
        $params = $m->getParameters();
        $this->assertSame('entidadId', $params[0]->getName());
        $this->assertSame('int', (string) $params[0]->getType());
        $this->assertSame('data', $params[1]->getName());
        $this->assertStringContainsString('EmpresaEnriquecida', (string) $params[1]->getType());
    }

    #[Test]
    public function enriquecimiento_repository_exposes_find_by_entidad_id(): void
    {
        $reflection = new ReflectionClass(EnriquecimientoRepository::class);
        $this->assertTrue($reflection->hasMethod('findByEntidadId'));

        $m = $reflection->getMethod('findByEntidadId');
        $this->assertCount(1, $m->getParameters());
        $this->assertSame('entidadId', $m->getParameters()[0]->getName());

        $returnType = (string) $m->getReturnType();
        // Return is `?EntidadEnriquecimiento` so the type may be reported as
        // 'App\Models\EntidadEnriquecimiento' or prefixed nullable.
        $this->assertStringContainsString('EntidadEnriquecimiento', $returnType);
    }

    #[Test]
    public function enriquecimiento_repository_exposes_candidatos(): void
    {
        $reflection = new ReflectionClass(EnriquecimientoRepository::class);
        $this->assertTrue($reflection->hasMethod('candidatos'));

        $m = $reflection->getMethod('candidatos');
        $this->assertCount(1, $m->getParameters());
        $this->assertSame('entidadId', $m->getParameters()[0]->getName());
        $this->assertSame('array', (string) $m->getReturnType());
    }

    #[Test]
    public function enriquecimiento_repository_exposes_needs_selection(): void
    {
        $reflection = new ReflectionClass(EnriquecimientoRepository::class);
        $this->assertTrue($reflection->hasMethod('needsSelection'));

        $m = $reflection->getMethod('needsSelection');
        $this->assertCount(1, $m->getParameters());
        $this->assertSame('entidadId', $m->getParameters()[0]->getName());
        $this->assertSame('bool', (string) $m->getReturnType());
    }
}