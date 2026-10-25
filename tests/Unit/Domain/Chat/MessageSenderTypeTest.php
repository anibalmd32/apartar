<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Chat;

use App\Domain\Chat\MessageSenderType;
use PHPUnit\Framework\TestCase;

final class MessageSenderTypeTest extends TestCase
{
    public function test_there_are_exactly_two_senders(): void
    {
        $this->assertSame(['customer', 'team'], array_map(fn (MessageSenderType $type): string => $type->value, MessageSenderType::cases()));
    }
}
