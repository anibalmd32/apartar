<?php

declare(strict_types=1);

use App\Domain\Payment\DuplicateCriterion;
use App\Domain\Payment\DuplicateReceiptException;

/**
 * El comprobante repetido (`REQ-PAY-003`, `T-059`): un `422` con el mensaje
 * de RB-6 y el campo del formulario que le toca a cada criterio.
 */
it('responde con un 422', function (): void {
    expect(DuplicateReceiptException::STATUS)->toBe(422);
});

/**
 * RB-6: el mismo texto para los dos criterios, y ningún dato del comprobante
 * que ya estaba —ni de quién, ni cuándo, ni con qué referencia—.
 */
it('dice lo mismo sea cual sea el criterio, y nada más', function (DuplicateCriterion $criterion): void {
    $exception = new DuplicateReceiptException($criterion);

    expect($exception->getMessage())->toBe('Este comprobante ya fue reportado.')
        ->and($exception->criterion)->toBe($criterion);
})->with(DuplicateCriterion::cases());

it('señala el archivo cuando coincide el hash y la referencia cuando coincide la pareja', function (): void {
    expect((new DuplicateReceiptException(DuplicateCriterion::FileHash))->field())->toBe('file')
        ->and((new DuplicateReceiptException(DuplicateCriterion::BankReference))->field())->toBe('reference');
});

/**
 * La violación del único de la carrera (SP-5) viaja dentro, para el log; al
 * cliente solo le llega el mensaje.
 */
it('conserva la causa', function (): void {
    $cause = new RuntimeException('Duplicate entry for key receipts_organization_id_file_hash_unique');

    expect((new DuplicateReceiptException(DuplicateCriterion::FileHash, $cause))->getPrevious())->toBe($cause);
});
