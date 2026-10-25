<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Account;

use App\Domain\Account\UnverifiedGoogleEmailException;
use PHPUnit\Framework\TestCase;

final class UnverifiedGoogleEmailExceptionTest extends TestCase
{
    public function test_the_message_is_for_the_person_and_the_reason_for_the_log(): void
    {
        $exception = new UnverifiedGoogleEmailException('email_verified ausente o falso');

        $this->assertSame(UnverifiedGoogleEmailException::MESSAGE, $exception->getMessage());
        $this->assertSame('email_verified ausente o falso', $exception->reason);
        $this->assertStringNotContainsString('email_verified', $exception->getMessage());
    }
}
