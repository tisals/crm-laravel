<?php

namespace Tests\Feature\API;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QuickSanityTest extends TestCase
{
    #[Test]
    public function it_passes(): void
    {
        $this->assertTrue(true);
    }
}