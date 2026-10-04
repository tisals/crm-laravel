<?php

namespace Tests\Unit\Empresas\Application\Filters;

use App\Empresas\Application\Filters\HabeasDataFilter;
use App\Empresas\Domain\Entities\EmpresaEnriquecida;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * PR4 of `complementar-entidad` — work-unit 4.1 (RED + GREEN).
 *
 * Verifies the three-mode behaviour of the Habeas Data filter PLUS the
 * audit-log emission contract per spec `habeas-data-filter/spec.md`:
 *
 *   - `mask`:    NIT becomes `MASKED-CC-{last4}`; PII fields null
 *                → decision='masked'
 *   - `exclude`: returns null  → decision='excluded'
 *   - `raw`:     returns the payload unchanged
 *                → decision='bypassed' (operator-only override)
 *   - any mode + Juridica → decision='passthrough' (no PII touched)
 *
 * The audit log entry MUST carry:
 *   - `entidad_id`    int
 *   - `tipo_persona`  'Natural' | 'Juridica'
 *   - `modo`          the resolved mode string
 *   - `decision`      'masked' | 'excluded' | 'bypassed' | 'passthrough'
 *   - `event_id`      UUIDv4
 *   - `ocurred_at`    ISO 8601 timestamp
 */
class HabeasDataFilterTest extends TestCase
{
    private function payload(): EmpresaEnriquecida
    {
        return EmpresaEnriquecida::fromArray([
            'razon_social' => 'ACME S.A.S.',
            'nit' => '1234567890',
            'numero_empleados' => 10,
            'ciiu_codigo' => '6202',
            'ciiu_descripcion' => 'Desarrollo de sistemas',
            'clase_riesgo_num' => 1,
            'clase_riesgo_desc' => 'Terciario',
            'sector_economico' => 'Servicios',
            'fuente_origen' => 'socrata',
            'enriquecido_at' => '2026-10-03T12:00:00Z',
            'enriquecimiento_hash' => str_repeat('a', 64),
        ]);
    }

    /**
     * Spy-based logger that captures every record() call into $this->records.
     * Avoids Mockery's expectException-at-construction complexity.
     */
    private function spyLogger(): \stdClass
    {
        $bag = new \stdClass();
        $bag->records = [];
        $bag->logger = new class($bag) implements LoggerInterface {
            public function __construct(private \stdClass $bag) {}
            public function emergency(string|\Stringable $message, array $context = []): void { $this->bag->records[] = ['level' => 'emergency', 'message' => (string) $message, 'context' => $context]; }
            public function alert(string|\Stringable $message, array $context = []): void { $this->bag->records[] = ['level' => 'alert', 'message' => (string) $message, 'context' => $context]; }
            public function critical(string|\Stringable $message, array $context = []): void { $this->bag->records[] = ['level' => 'critical', 'message' => (string) $message, 'context' => $context]; }
            public function error(string|\Stringable $message, array $context = []): void { $this->bag->records[] = ['level' => 'error', 'message' => (string) $message, 'context' => $context]; }
            public function warning(string|\Stringable $message, array $context = []): void { $this->bag->records[] = ['level' => 'warning', 'message' => (string) $message, 'context' => $context]; }
            public function notice(string|\Stringable $message, array $context = []): void { $this->bag->records[] = ['level' => 'notice', 'message' => (string) $message, 'context' => $context]; }
            public function info(string|\Stringable $message, array $context = []): void { $this->bag->records[] = ['level' => 'info', 'message' => (string) $message, 'context' => $context]; }
            public function debug(string|\Stringable $message, array $context = []): void { $this->bag->records[] = ['level' => 'debug', 'message' => (string) $message, 'context' => $context]; }
            public function log($level, string|\Stringable $message, array $context = []): void { $this->bag->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context]; }
        };

        return $bag;
    }

    // ── mask mode (PR3 behavior, retested for safety) ────────────────────

    #[Test]
    public function mask_replaces_nit_with_last4_digits(): void
    {
        $f = new HabeasDataFilter();
        $r = $f->apply($this->payload(), 'Natural', HabeasDataFilter::MODE_MASK);

        $this->assertNotNull($r);
        $this->assertSame('MASKED-CC-7890', $r->nit);
        $this->assertSame('ACME S.A.S.', $r->razon_social);
        $this->assertSame('6202', $r->ciiu_codigo);
    }

    #[Test]
    public function mask_handles_nit_with_dash_suffix(): void
    {
        $p = EmpresaEnriquecida::fromArray([
            'razon_social' => 'X',
            'nit' => '900123456-7',
            'numero_empleados' => 0,
            'ciiu_codigo' => '0000',
            'ciiu_descripcion' => 'n/a',
            'clase_riesgo_num' => 1,
            'clase_riesgo_desc' => 'x',
            'sector_economico' => 'x',
            'fuente_origen' => 'socrata',
            'enriquecido_at' => '2026-10-03T00:00:00Z',
            'enriquecimiento_hash' => str_repeat('0', 64),
        ]);

        $f = new HabeasDataFilter();
        $r = $f->apply($p, 'Natural', HabeasDataFilter::MODE_MASK);

        $this->assertSame('MASKED-CC-4567', $r->nit);
    }

    #[Test]
    public function mask_on_juridica_returns_payload_unchanged(): void
    {
        $f = new HabeasDataFilter();
        $p = $this->payload();
        $r = $f->apply($p, 'Juridica', HabeasDataFilter::MODE_MASK);

        $this->assertNotNull($r);
        $this->assertSame('1234567890', $r->nit);
    }

    // ── exclude mode (PR3 behavior, retested for safety) ─────────────────

    #[Test]
    public function exclude_on_natural_returns_null(): void
    {
        $f = new HabeasDataFilter();
        $r = $f->apply($this->payload(), 'Natural', HabeasDataFilter::MODE_EXCLUDE);

        $this->assertNull($r);
    }

    #[Test]
    public function exclude_on_juridica_returns_payload_unchanged(): void
    {
        $f = new HabeasDataFilter();
        $p = $this->payload();
        $r = $f->apply($p, 'Juridica', HabeasDataFilter::MODE_EXCLUDE);

        $this->assertNotNull($r);
        $this->assertSame($p->nit, $r->nit);
    }

    // ── raw mode (PR3 behavior, retested for safety) ─────────────────────

    #[Test]
    public function raw_on_natural_returns_payload_unchanged(): void
    {
        $f = new HabeasDataFilter();
        $p = $this->payload();
        $r = $f->apply($p, 'Natural', HabeasDataFilter::MODE_RAW);

        $this->assertNotNull($r);
        $this->assertSame('1234567890', $r->nit);
    }

    #[Test]
    public function raw_on_juridica_returns_payload_unchanged(): void
    {
        $f = new HabeasDataFilter();
        $p = $this->payload();
        $r = $f->apply($p, 'Juridica', HabeasDataFilter::MODE_RAW);

        $this->assertNotNull($r);
        $this->assertSame($p->nit, $r->nit);
    }

    // ── unknown mode falls back to mask (PR3, retested for safety) ──────

    #[Test]
    public function unknown_falls_back_to_mask(): void
    {
        $f = new HabeasDataFilter();
        $r = $f->apply($this->payload(), 'Natural', 'bogus-mode');

        $this->assertNotNull($r);
        $this->assertSame('MASKED-CC-7890', $r->nit);
    }

    // ── default mode resolution (PR4: explicit per-call mode arg wins) ──

    #[Test]
    public function explicit_mode_argument_overrides_default(): void
    {
        // defaultMode is 'mask' but caller passes 'raw' → raw wins.
        $f = new HabeasDataFilter('mask');
        $r = $f->apply($this->payload(), 'Natural', HabeasDataFilter::MODE_RAW);

        $this->assertNotNull($r);
        $this->assertSame('1234567890', $r->nit);
    }

    #[Test]
    public function default_mode_used_when_no_explicit_argument_was_supplied(): void
    {
        // defaultMode is 'exclude' → return null.
        $f = new HabeasDataFilter('exclude');
        $r = $f->apply($this->payload(), 'Natural');

        $this->assertNull($r);
    }

    // ── audit log emission (PR4 NEW) ────────────────────────────────────

    #[Test]
    public function mask_on_natural_emits_audit_log_with_decision_masked(): void
    {
        $bag = $this->spyLogger();
        $f = new HabeasDataFilter('mask', $bag->logger);

        $r = $f->apply($this->payload(), 'Natural', null, entidadId: 42);

        $this->assertNotNull($r);
        $this->assertCount(1, $bag->records, 'expected exactly one audit log entry');

        $entry = $bag->records[0];
        $this->assertSame('info', $entry['level']);
        $this->assertSame('habeas_data.applied', $entry['message']);

        $ctx = $entry['context'];
        $this->assertSame(42, $ctx['entidad_id']);
        $this->assertSame('Natural', $ctx['tipo_persona']);
        $this->assertSame('mask', $ctx['modo']);
        $this->assertSame('masked', $ctx['decision']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $ctx['event_id']
        );
        $this->assertIsString($ctx['ocurred_at']);
    }

    #[Test]
    public function exclude_on_natural_emits_audit_log_with_decision_excluded(): void
    {
        $bag = $this->spyLogger();
        $f = new HabeasDataFilter('exclude', $bag->logger);

        $r = $f->apply($this->payload(), 'Natural', null, entidadId: 7);

        $this->assertNull($r);
        $this->assertCount(1, $bag->records);

        $entry = $bag->records[0];
        $this->assertSame('habeas_data.excluded', $entry['message']);
        $this->assertSame('excluded', $entry['context']['decision']);
        $this->assertSame(7, $entry['context']['entidad_id']);
    }

    #[Test]
    public function raw_on_natural_emits_warning_audit_log_with_decision_bypassed(): void
    {
        $bag = $this->spyLogger();
        $f = new HabeasDataFilter('raw', $bag->logger);

        $r = $f->apply($this->payload(), 'Natural', null, entidadId: 99);

        $this->assertNotNull($r);
        $this->assertCount(1, $bag->records);

        $entry = $bag->records[0];
        $this->assertSame('warning', $entry['level']);
        $this->assertSame('habeas_data.bypass_warning', $entry['message']);
        $this->assertSame('bypassed', $entry['context']['decision']);
        $this->assertSame('raw', $entry['context']['modo']);
        $this->assertSame(99, $entry['context']['entidad_id']);
    }

    #[Test]
    public function juridica_emits_audit_log_with_decision_passthrough(): void
    {
        $bag = $this->spyLogger();
        $f = new HabeasDataFilter('mask', $bag->logger);

        // Even when caller asks for 'mask' mode, Juridica short-circuits
        // and emits a 'passthrough' audit log.
        $r = $f->apply($this->payload(), 'Juridica', null, entidadId: 3);

        $this->assertNotNull($r);
        $this->assertSame('1234567890', $r->nit);

        $this->assertCount(1, $bag->records);
        $entry = $bag->records[0];
        $this->assertSame('habeas_data.passthrough', $entry['message']);
        $this->assertSame('passthrough', $entry['context']['decision']);
        $this->assertSame('Juridica', $entry['context']['tipo_persona']);
        $this->assertSame(3, $entry['context']['entidad_id']);
    }

    #[Test]
    public function no_audit_log_emitted_when_logger_not_injected(): void
    {
        // PR3 binding does NOT inject a logger → filter must not crash
        // (it should silently skip audit emission).
        $f = new HabeasDataFilter('mask', null);

        $r = $f->apply($this->payload(), 'Natural', null, entidadId: 1);

        $this->assertNotNull($r);
        $this->assertSame('MASKED-CC-7890', $r->nit);
    }

    #[Test]
    public function audit_log_event_id_is_unique_per_invocation(): void
    {
        $bag = $this->spyLogger();
        $f = new HabeasDataFilter('mask', $bag->logger);

        $f->apply($this->payload(), 'Natural', null, entidadId: 1);
        $f->apply($this->payload(), 'Natural', null, entidadId: 2);

        $this->assertCount(2, $bag->records);
        $this->assertNotSame(
            $bag->records[0]['context']['event_id'],
            $bag->records[1]['context']['event_id'],
            'each apply() call must mint a fresh event_id'
        );
    }
}