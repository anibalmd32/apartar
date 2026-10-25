<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Chat;

use App\Domain\Chat\NoCustomerRecordException;
use PHPUnit\Framework\TestCase;

final class NoCustomerRecordExceptionTest extends TestCase
{
    public function test_it_is_a_403_that_explains_how_to_get_a_record(): void
    {
        $this->assertSame(403, NoCustomerRecordException::STATUS);
        $this->assertSame(NoCustomerRecordException::MESSAGE, (new NoCustomerRecordException)->getMessage());
    }
}
