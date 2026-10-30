<?php

declare(strict_types=1);

use App\Actions\Layaway\CreatedLayaway;
use App\Models\Customer;
use App\Models\Layaway;

it('dice si el apartado nació ahora o ya existía con esa clave', function (): void {
    $layaway = new Layaway;
    $customer = new Customer;

    $fresh = new CreatedLayaway($layaway, $customer, null, true);
    $replayed = new CreatedLayaway($layaway, $customer, null, false);

    expect($fresh->created)->toBeTrue()
        ->and($replayed->created)->toBeFalse()
        ->and($fresh->layaway)->toBe($layaway)
        ->and($fresh->customer)->toBe($customer)
        ->and($fresh->payment)->toBeNull();
});
