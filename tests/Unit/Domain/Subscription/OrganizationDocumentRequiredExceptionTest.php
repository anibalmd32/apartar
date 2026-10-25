<?php

declare(strict_types=1);

use App\Domain\Subscription\OrganizationDocumentRequiredException;

it('pide el RIF en Ajustes', function (): void {
    expect((new OrganizationDocumentRequiredException)->getMessage())->toContain('RIF')->toContain('Ajustes')
        ->and(OrganizationDocumentRequiredException::STATUS)->toBe(422);
});
