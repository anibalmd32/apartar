<?php

declare(strict_types=1);

use App\Domain\Customer\CustomerAlreadyExistsException;
use App\Models\Customer;

it('nombra la ficha que ya tiene ese documento y la conserva', function (): void {
    $customer = (new Customer)->forceFill(['full_name' => 'Luisa Rojas']);

    $exception = new CustomerAlreadyExistsException($customer);

    expect($exception->getMessage())->toBe('Ya tienes un cliente con ese documento: Luisa Rojas.')
        ->and($exception->customer)->toBe($customer);
});
