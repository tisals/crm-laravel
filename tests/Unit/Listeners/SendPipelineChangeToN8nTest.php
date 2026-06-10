<?php

namespace Tests\Unit\Listeners;

use App\Events\PipelineEtapaChanged;
use App\Infrastructure\Webhook\CrmWebhookSender;
use App\Listeners\SendPipelineChangeToN8n;
use Illuminate\Contracts\Queue\ShouldQueue;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SendPipelineChangeToN8nTest extends TestCase
{
    private CrmWebhookSender|MockInterface $senderMock;

    private SendPipelineChangeToN8n $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $this->senderMock = Mockery::mock(CrmWebhookSender::class);
        $this->listener = new SendPipelineChangeToN8n($this->senderMock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function listener_implements_should_queue(): void
    {
        $this->assertInstanceOf(ShouldQueue::class, $this->listener);
    }

    #[Test]
    public function handler_sends_webhook_when_configured(): void
    {
        config([
            'webhook.n8n_pipeline.url' => 'https://n8n.example.com/webhook',
            'webhook.n8n_pipeline.secret' => 'test-secret',
        ]);

        // Mock Oportunidad query chain
        $oportunidadMock = Mockery::mock()
            ->shouldReceive('with')
            ->andReturnSelf()
            ->shouldReceive('find')
            ->andReturn((object) [
                'codigo' => 'OP-001',
                'pipeline' => (object) ['nombre' => 'Cotización', 'codigo' => 'COTIZACION'],
                'pipelineEtapa' => (object) ['nombre' => 'Borrador'],
                'contacto' => (object) ['nombres' => 'María', 'apellidos' => 'García', 'email_contacto' => 'maria@test.com'],
                'entidad' => (object) [
                    'nombre' => 'Acme Corp',
                    'usuarios' => collect([(object) ['nombre' => 'Carlos', 'email' => 'carlos@test.com']]),
                ],
            ])
            ->getMock();

        $this->app->instance(
            \Modules\CRM\Models\Oportunidad::class,
            $oportunidadMock
        );

        $event = new PipelineEtapaChanged(
            oportunidadId: 42,
            previousEtapaId: 10,
            newEtapaId: 20,
            pipelineId: 1,
            userId: 99,
        );

        $capturedEvent = null;
        $capturedData = null;

        $this->senderMock
            ->shouldReceive('send')
            ->once()
            ->with(
                Mockery::capture($capturedEvent),
                Mockery::capture($capturedData),
                Mockery::any(),
            );

        $this->listener->handle($event);

        $this->assertSame('pipeline.etapa_changed', $capturedEvent);
        $this->assertIsArray($capturedData);
        $this->assertArrayHasKey('oportunidad_id', $capturedData);
        $this->assertSame(42, $capturedData['oportunidad_id']);
        $this->assertSame('maria@test.com', $capturedData['contacto_email']);
        $this->assertSame('Carlos', $capturedData['asesor_nombre']);
    }

    #[Test]
    public function handler_does_nothing_when_not_configured(): void
    {
        config(['webhook.n8n_pipeline.url' => null]);

        $event = new PipelineEtapaChanged(
            oportunidadId: 1,
            previousEtapaId: null,
            newEtapaId: 5,
            pipelineId: 1,
        );

        $this->senderMock->shouldNotReceive('send');

        $this->listener->handle($event);

        // Assert no exception was thrown
        $this->assertTrue(true);
    }

    #[Test]
    public function handler_does_nothing_when_url_empty(): void
    {
        config(['webhook.n8n_pipeline.url' => '']);

        $event = new PipelineEtapaChanged(
            oportunidadId: 1,
            previousEtapaId: null,
            newEtapaId: 5,
            pipelineId: 1,
        );

        $this->senderMock->shouldNotReceive('send');

        $this->listener->handle($event);

        $this->assertTrue(true);
    }

    #[Test]
    public function handler_uses_n8n_pipeline_config_prefix(): void
    {
        config([
            'webhook.n8n_pipeline.url' => 'https://n8n.example.com/webhook',
            'webhook.n8n_pipeline.secret' => 'test-secret',
        ]);

        $oportunidadMock = Mockery::mock()
            ->shouldReceive('with')
            ->andReturnSelf()
            ->shouldReceive('find')
            ->andReturn((object) [
                'codigo' => null,
                'pipeline' => null,
                'pipelineEtapa' => null,
                'contacto' => null,
                'entidad' => (object) ['nombre' => null, 'usuarios' => null],
            ])
            ->getMock();

        $this->app->instance(
            \Modules\CRM\Models\Oportunidad::class,
            $oportunidadMock
        );

        $event = new PipelineEtapaChanged(
            oportunidadId: 10,
            previousEtapaId: 1,
            newEtapaId: 2,
            pipelineId: 5,
        );

        $capturedPrefix = null;

        $this->senderMock
            ->shouldReceive('send')
            ->once()
            ->with(
                Mockery::any(),
                Mockery::any(),
                Mockery::capture($capturedPrefix),
            );

        $this->listener->handle($event);

        $this->assertSame('n8n_pipeline', $capturedPrefix);
    }

    #[Test]
    public function handler_creates_dedup_key(): void
    {
        config([
            'webhook.n8n_pipeline.url' => 'https://n8n.example.com/webhook',
            'webhook.n8n_pipeline.secret' => 'test-secret',
        ]);

        $oportunidadMock = Mockery::mock()
            ->shouldReceive('with')
            ->andReturnSelf()
            ->shouldReceive('find')
            ->andReturn((object) [
                'codigo' => null,
                'pipeline' => null,
                'pipelineEtapa' => null,
                'contacto' => null,
                'entidad' => (object) ['nombre' => null, 'usuarios' => null],
            ])
            ->getMock();

        $this->app->instance(
            \Modules\CRM\Models\Oportunidad::class,
            $oportunidadMock
        );

        $event = new PipelineEtapaChanged(
            oportunidadId: 77,
            previousEtapaId: null,
            newEtapaId: 3,
            pipelineId: 1,
        );

        $capturedData = null;

        $this->senderMock
            ->shouldReceive('send')
            ->once()
            ->with(
                Mockery::any(),
                Mockery::capture($capturedData),
                Mockery::any(),
            );

        $this->listener->handle($event);

        $expectedDedup = 'pipeline-change-'.$event->oportunidadId.'-'.$event->timestamp;
        $this->assertIsArray($capturedData);
        $this->assertArrayHasKey('dedup_key', $capturedData);
        $this->assertSame($expectedDedup, $capturedData['dedup_key']);
    }

    #[Test]
    public function handler_passes_all_payload_fields(): void
    {
        config([
            'webhook.n8n_pipeline.url' => 'https://n8n.example.com/webhook',
            'webhook.n8n_pipeline.secret' => 'test-secret',
        ]);

        $oportunidadMock = Mockery::mock()
            ->shouldReceive('with')
            ->andReturnSelf()
            ->shouldReceive('find')
            ->andReturn((object) [
                'codigo' => 'OP-055',
                'pipeline' => (object) ['nombre' => 'Recuperación', 'codigo' => 'RECUPERACION'],
                'pipelineEtapa' => (object) ['nombre' => 'Rescate_Inicial'],
                'contacto' => (object) ['nombres' => 'Ana', 'apellidos' => 'López', 'email_contacto' => 'ana@test.com'],
                'entidad' => (object) [
                    'nombre' => 'Empresa XYZ',
                    'usuarios' => collect([(object) ['nombre' => 'Pedro', 'email' => 'pedro@test.com']]),
                ],
            ])
            ->getMock();

        $this->app->instance(
            \Modules\CRM\Models\Oportunidad::class,
            $oportunidadMock
        );

        $event = new PipelineEtapaChanged(
            oportunidadId: 55,
            previousEtapaId: 3,
            newEtapaId: 7,
            pipelineId: 2,
            userId: 123,
        );

        $capturedData = null;

        $this->senderMock
            ->shouldReceive('send')
            ->once()
            ->with(
                Mockery::any(),
                Mockery::capture($capturedData),
                Mockery::any(),
            );

        $this->listener->handle($event);

        $this->assertIsArray($capturedData);
        $this->assertSame(55, $capturedData['oportunidad_id']);
        $this->assertSame(3, $capturedData['previous_etapa_id']);
        $this->assertSame(7, $capturedData['new_etapa_id']);
        $this->assertSame(2, $capturedData['pipeline_id']);
        $this->assertSame(123, $capturedData['user_id']);
        $this->assertSame($event->timestamp, $capturedData['timestamp']);
        $this->assertArrayHasKey('dedup_key', $capturedData);
        // Enriched fields
        $this->assertSame('OP-055', $capturedData['oportunidad_codigo']);
        $this->assertSame('Recuperación', $capturedData['pipeline_nombre']);
        $this->assertSame('RECUPERACION', $capturedData['pipeline_codigo']);
        $this->assertSame('Rescate_Inicial', $capturedData['etapa_nombre']);
        $this->assertSame('Ana López', $capturedData['contacto_nombre']);
        $this->assertSame('ana@test.com', $capturedData['contacto_email']);
        $this->assertSame('Empresa XYZ', $capturedData['entidad_nombre']);
        $this->assertSame('Pedro', $capturedData['asesor_nombre']);
        $this->assertSame('pedro@test.com', $capturedData['asesor_email']);
    }

    #[Test]
    public function handler_injects_sender_via_constructor(): void
    {
        $reflection = new \ReflectionClass(SendPipelineChangeToN8n::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor, 'SendPipelineChangeToN8n must have a constructor');

        $params = $constructor->getParameters();
        $this->assertCount(1, $params, 'Constructor must have exactly 1 parameter');
        $this->assertEquals(
            CrmWebhookSender::class,
            (string) $params[0]->getType(),
            'Constructor parameter must be typed as CrmWebhookSender'
        );
    }
}
