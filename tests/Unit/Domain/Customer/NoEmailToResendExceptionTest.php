<?php

declare(strict_types=1);

use App\Domain\Customer\NoEmailToResendException;

it('sin correo, se ofrece dictar el código', function (): void {
    expect(NoEmailToResendException::STATUS)->toBe(422)
        ->and((new NoEmailToResendException)->getMessage())->toContain('díctaselo');
});
