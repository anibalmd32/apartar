<?php

declare(strict_types=1);

use App\Domain\Layaway\NextPaymentKind;

it('declara la inicial y la cuota con los valores que lee el frontend', function (): void {
    expect(array_column(NextPaymentKind::cases(), 'value'))->toBe(['down_payment', 'installment']);
});
