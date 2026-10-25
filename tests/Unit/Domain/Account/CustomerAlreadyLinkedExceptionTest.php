<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Account;

use App\Domain\Account\CustomerAlreadyLinkedException;
use PHPUnit\Framework\TestCase;

final class CustomerAlreadyLinkedExceptionTest extends TestCase
{
    public function test_each_reason_has_its_own_message(): void
    {
        $toAnother = CustomerAlreadyLinkedException::toAnotherAccount();
        $second = CustomerAlreadyLinkedException::accountHasAnotherRecord();

        $this->assertSame(422, CustomerAlreadyLinkedException::STATUS);
        $this->assertSame(CustomerAlreadyLinkedException::TO_ANOTHER_ACCOUNT, $toAnother->getMessage());
        $this->assertSame(CustomerAlreadyLinkedException::ACCOUNT_HAS_ANOTHER_RECORD, $second->getMessage());
        $this->assertNotSame($toAnother->reason, $second->reason);
    }
}
