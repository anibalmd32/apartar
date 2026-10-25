<?php

declare(strict_types=1);

use App\Domain\Payment\DuplicateCriterion;
use App\Domain\Payment\DuplicateReceiptException;

it('responde con un 422', function (): void {
    expect(DuplicateReceiptException::STATUS)->toBe(422);
});

it('dice lo mismo sea cual sea el criterio, y nada más', function (DuplicateCriterion $criterion): void {
    $exception = new DuplicateReceiptException($criterion);

    expect($exception->getMessage())->toBe('Este comprobante ya fue reportado.')
        ->and($exception->criterion)->toBe($criterion);
})->with(DuplicateCriterion::cases());

it('señala el archivo cuando coincide el hash y la referencia cuando coincide la pareja', function (): void {
    expect((new DuplicateReceiptException(DuplicateCriterion::FileHash))->field())->toBe('file')
        ->and((new DuplicateReceiptException(DuplicateCriterion::BankReference))->field())->toBe('reference');
});

it('conserva la causa', function (): void {
    $cause = new RuntimeException('Duplicate entry for key receipts_organization_id_file_hash_unique');

    expect((new DuplicateReceiptException(DuplicateCriterion::FileHash, $cause))->getPrevious())->toBe($cause);
});
