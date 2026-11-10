<?php

declare(strict_types=1);

use App\Domain\Safeguard\SafeguardDestination;

it('ofrece los lugares donde se suele resguardar el dinero, con su nombre en español', function (): void {
    expect(SafeguardDestination::options())->toBe([
        ['value' => 'binance_usdt', 'label' => 'Binance (USDT)'],
        ['value' => 'crypto_wallet', 'label' => 'Otra billetera de criptomonedas'],
        ['value' => 'cash_usd', 'label' => 'Divisas en efectivo'],
        ['value' => 'zelle', 'label' => 'Zelle'],
        ['value' => 'bank_abroad', 'label' => 'Cuenta bancaria en el exterior'],
        ['value' => 'credit_card', 'label' => 'Tarjeta de crédito'],
        ['value' => 'other', 'label' => 'Otro'],
    ]);
});
