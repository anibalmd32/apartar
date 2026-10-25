<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Notification;

use App\Domain\Notification\NotificationAudience;
use PHPUnit\Framework\TestCase;

final class NotificationAudienceTest extends TestCase
{
    public function test_there_are_three_audiences(): void
    {
        $this->assertSame(['customer', 'owner', 'subscriber'], array_map(fn (NotificationAudience $audience): string => $audience->value, NotificationAudience::cases()));
    }
}
