<?php

namespace Tests\Unit\Events;

use App\Events\PipelineEtapaChanged;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PipelineEtapaChangedTest extends TestCase
{
    #[Test]
    public function event_has_correct_properties(): void
    {
        $event = new PipelineEtapaChanged(
            oportunidadId: 42,
            previousEtapaId: 10,
            newEtapaId: 20,
            pipelineId: 1,
            userId: 99,
        );

        $this->assertEquals(42, $event->oportunidadId);
        $this->assertEquals(10, $event->previousEtapaId);
        $this->assertEquals(20, $event->newEtapaId);
        $this->assertEquals(1, $event->pipelineId);
        $this->assertEquals(99, $event->userId);
    }

    #[Test]
    public function event_allows_null_previous_etapa(): void
    {
        $event = new PipelineEtapaChanged(
            oportunidadId: 1,
            previousEtapaId: null,
            newEtapaId: 5,
            pipelineId: 1,
        );

        $this->assertNull($event->previousEtapaId);
        $this->assertEquals(5, $event->newEtapaId);
    }

    #[Test]
    public function event_allows_null_user_id(): void
    {
        $event = new PipelineEtapaChanged(
            oportunidadId: 1,
            previousEtapaId: null,
            newEtapaId: 5,
            pipelineId: 1,
        );

        $this->assertNull($event->userId);
    }

    #[Test]
    public function timestamp_is_formatted_as_iso_8601(): void
    {
        $event = new PipelineEtapaChanged(
            oportunidadId: 1,
            previousEtapaId: null,
            newEtapaId: 5,
            pipelineId: 1,
        );

        $this->assertIsString($event->timestamp);
        $this->assertStringContainsString('T', $event->timestamp, 'ISO 8601 format must contain T separator');
        $this->assertStringContainsString(':', $event->timestamp, 'ISO 8601 format must contain time');
    }

    #[Test]
    public function event_is_dispatchable(): void
    {
        $event = new PipelineEtapaChanged(
            oportunidadId: 1,
            previousEtapaId: null,
            newEtapaId: 5,
            pipelineId: 1,
        );

        // ShouldDispatch contract: the trait provides dispatch() method
        $this->assertTrue(
            method_exists($event, 'dispatch'),
            'PipelineEtapaChanged must have dispatch method from Dispatchable trait'
        );
    }
}
