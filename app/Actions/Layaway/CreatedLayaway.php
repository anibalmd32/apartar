<?php

declare(strict_types=1);

namespace App\Actions\Layaway;

use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Payment;

final readonly class CreatedLayaway
{
    public function __construct(
        public Layaway $layaway,
        public Customer $customer,
        public ?Payment $payment,
        public bool $created,
    ) {}
}
