<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Account;

use App\Domain\Account\ClaimVerificationFailedException;
use PHPUnit\Framework\TestCase;

final class ClaimVerificationFailedExceptionTest extends TestCase
{
    public function test_the_reason_never_reaches_the_message(): void
    {
        $exception = new ClaimVerificationFailedException('el documento no es el de la ficha del apartado');

        $this->assertSame(ClaimVerificationFailedException::MESSAGE, $exception->getMessage());
        $this->assertSame('el documento no es el de la ficha del apartado', $exception->reason);
    }
}
