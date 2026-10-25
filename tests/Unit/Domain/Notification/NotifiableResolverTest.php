<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Notification;

use App\Domain\Notification\NotifiableResolver;
use App\Models\Customer;
use PHPUnit\Framework\TestCase;

final class NotifiableResolverTest extends TestCase
{
    public function test_a_record_without_an_account_is_its_own_recipient(): void
    {
        $customer = (new Customer)->forceFill(['id' => 1, 'account_id' => null]);

        $this->assertSame($customer, (new NotifiableResolver)->for($customer));
    }
}
