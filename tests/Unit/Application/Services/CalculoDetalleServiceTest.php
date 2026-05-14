<?php

namespace Tests\Unit\Application\Services;

use App\Application\Services\CalculoDetalleService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CalculoDetalleServiceTest extends TestCase
{
    private CalculoDetalleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CalculoDetalleService();
    }

    #[Test]
    public function it_calculates_vr_total_and_iva(): void
    {
        $result = $this->service->calculate(2, 100000, 19);

        $this->assertEquals(200000, $result['vr_total']);
        $this->assertEquals(38000, $result['iva']);
    }

    #[Test]
    public function it_handles_zero_quantity(): void
    {
        $result = $this->service->calculate(0, 100000, 19);

        $this->assertEquals(0, $result['vr_total']);
        $this->assertEquals(0, $result['iva']);
    }

    #[Test]
    public function it_handles_zero_iva_percentage(): void
    {
        $result = $this->service->calculate(3, 50000, 0);

        $this->assertEquals(150000, $result['vr_total']);
        $this->assertEquals(0, $result['iva']);
    }
}
