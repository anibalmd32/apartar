<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesNeighbourOrganization;
use Tests\Concerns\FailsOnCounterDrift;
use Tests\Concerns\FakesPwnedPasswords;
use Tests\Concerns\RecalculatesUsage;
use Tests\Concerns\SubscribesWithoutLimits;

abstract class TestCase extends BaseTestCase
{
    use CreatesNeighbourOrganization, FailsOnCounterDrift, FakesPwnedPasswords, RecalculatesUsage, SubscribesWithoutLimits;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->fakeUncompromisedPasswordService();

        $this->watchCounterDrift();
    }

    protected function assertPostConditions(): void
    {
        parent::assertPostConditions();

        $this->assertNoCounterDrift();
    }
}
