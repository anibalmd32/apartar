<?php

declare(strict_types=1);

use App\Domain\Subscription\OrganizationDocumentRequiredException;

/** `REQ-SUB-006` RB-5, SP-4 · dice qué falta y dónde ponerlo, con `422`. */
it('pide el RIF en Ajustes', function (): void {
    expect((new OrganizationDocumentRequiredException)->getMessage())->toContain('RIF')->toContain('Ajustes')
        ->and(OrganizationDocumentRequiredException::STATUS)->toBe(422);
});
