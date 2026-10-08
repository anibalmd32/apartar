<?php

declare(strict_types=1);

use App\Domain\Payment\ReceiptInboxSort;

/**
 * La lista blanca de la bandeja de comprobantes (`REQ-PAY-004` RB-4,
 * `REQ-BOF-045` RB-4). El orden FIFO por defecto no es un caso: es lo que
 * pasa cuando no se pide ninguno.
 */
it('dice qué columna cualificada ordena cada caso', function (ReceiptInboxSort $sort, string $column): void {
    expect($sort->column())->toBe($column);
})->with([
    [ReceiptInboxSort::Submitted, 'receipts.created_at'],
    [ReceiptInboxSort::PaidAt, 'receipts.paid_at'],
]);

it('acepta de la URL cada caso por su valor, y nada más', function (): void {
    $whitelist = ReceiptInboxSort::whitelist();

    foreach (ReceiptInboxSort::cases() as $sort) {
        expect($whitelist->resolve($sort->value))->toBe($sort);
    }

    expect($whitelist->resolve('amount'))->toBeNull()
        ->and($whitelist->resolve('created_at'))->toBeNull()
        ->and($whitelist->resolve('fifo'))->toBeNull();
});
