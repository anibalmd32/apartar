<?php

declare(strict_types=1);

use App\Domain\Account\AccountProvider;

it('declara exactamente el proveedor de la iteración', function (): void {
    expect(array_column(AccountProvider::cases(), 'value'))->toBe(['google']);
});

it('usa como valor la cadena que se persiste', function (): void {
    expect(AccountProvider::Google->value)->toBe('google');
});
