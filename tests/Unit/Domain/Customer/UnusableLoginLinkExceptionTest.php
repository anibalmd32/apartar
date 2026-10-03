<?php

declare(strict_types=1);

use App\Domain\Customer\UnusableLoginLinkException;

/**
 * `REQ-IDN-003` SP-2: caducado, usado o inexistente dicen lo mismo; el
 * motivo, solo para el log.
 */
it('dice lo mismo sea cual sea el motivo', function (): void {
    $expired = new UnusableLoginLinkException('token caducado o ya usado');
    $unknown = new UnusableLoginLinkException('token inexistente');

    expect($expired->getMessage())->toBe($unknown->getMessage())
        ->and($expired->getMessage())->toBe(UnusableLoginLinkException::MESSAGE)
        ->and($unknown->reason)->toBe('token inexistente');
});
