<?php

declare(strict_types=1);

use App\Domain\Platform\SupportAccessRequiredException;

it('es un 403 con el motivo de cada caso', function (): void {
    expect(SupportAccessRequiredException::STATUS)->toBe(403)
        ->and(SupportAccessRequiredException::readOnly()->getMessage())->toBe(SupportAccessRequiredException::READ_ONLY)
        ->and(SupportAccessRequiredException::readOnly()->organizationSlug)->toBeNull();
});
