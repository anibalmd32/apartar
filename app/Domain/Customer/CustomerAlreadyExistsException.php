<?php

declare(strict_types=1);

namespace App\Domain\Customer;

use App\Models\Customer;
use RuntimeException;

final class CustomerAlreadyExistsException extends RuntimeException
{
    public function __construct(public readonly Customer $customer)
    {
        parent::__construct(sprintf('Ya tienes un cliente con ese documento: %s.', $customer->full_name));
    }
}
