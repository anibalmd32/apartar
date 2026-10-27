<?php

declare(strict_types=1);

use App\Domain\Subscription\OrganizationDocumentRequiredException;

it('pide el RIF o la cédula del dueño en Ajustes', function (): void {
    expect((new OrganizationDocumentRequiredException)->getMessage())->toContain('RIF')->toContain('cédula del dueño')->toContain('Ajustes')
        ->and(OrganizationDocumentRequiredException::STATUS)->toBe(422);
});
