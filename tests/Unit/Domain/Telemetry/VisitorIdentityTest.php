<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Telemetry;

use App\Domain\Telemetry\VisitorIdentity;
use Tests\TestCase;

final class VisitorIdentityTest extends TestCase
{
    private const VISITOR = '0190f0f0-0000-7000-8000-000000000001';

    public function test_an_unknown_visitor_is_nobody(): void
    {
        $identity = VisitorIdentity::of(self::VISITOR);

        $this->assertNull($identity->accountId);
        $this->assertNull($identity->customerIn(1));
    }

    public function test_the_record_of_each_organization_is_kept_apart(): void
    {
        VisitorIdentity::remember(self::VISITOR, null, 1, 10);
        VisitorIdentity::remember(self::VISITOR, 7, 2, 20);
        VisitorIdentity::remember(self::VISITOR, null);

        $identity = VisitorIdentity::of(self::VISITOR);

        $this->assertSame(7, $identity->accountId);
        $this->assertSame(10, $identity->customerIn(1));
        $this->assertSame(20, $identity->customerIn(2));
        $this->assertNull($identity->customerIn(3));
        $this->assertNull($identity->customerIn(null));
    }
}
