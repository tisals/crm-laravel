<?php

namespace Tests\Unit\Application\Services;

use App\Application\Services\RbacService;
use App\Domain\Repositories\PermisoRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RbacServiceTest extends TestCase
{
    private RbacService $service;

    private PermisoRepositoryInterface $repositoryMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repositoryMock = $this->createMock(PermisoRepositoryInterface::class);
        $this->service = new RbacService($this->repositoryMock);
    }

    #[Test]
    public function it_returns_true_when_permission_exists(): void
    {
        $this->repositoryMock
            ->expects($this->once())
            ->method('hasPermissionForRol')
            ->with(1, 'entidad.index')
            ->willReturn(true);

        $result = $this->service->hasPermission(1, 'entidad.index');

        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_false_when_permission_missing(): void
    {
        $this->repositoryMock
            ->expects($this->once())
            ->method('hasPermissionForRol')
            ->with(2, 'entidad.index')
            ->willReturn(false);

        $result = $this->service->hasPermission(2, 'entidad.index');

        $this->assertFalse($result);
    }

    #[Test]
    public function it_handles_wildcard_permission(): void
    {
        $this->repositoryMock
            ->expects($this->once())
            ->method('hasPermissionForRol')
            ->with(1, 'entidad.index')
            ->willReturn(true);

        $result = $this->service->hasPermission(1, 'entidad.index');

        $this->assertTrue($result);
    }
}
