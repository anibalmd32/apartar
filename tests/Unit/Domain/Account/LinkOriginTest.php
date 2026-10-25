<?php

declare(strict_types=1);

use App\Domain\Account\LinkOrigin;

it('declara exactamente las dos vías de vinculación', function (): void {
    expect(array_column(LinkOrigin::cases(), 'value'))->toBe(['layaway_creation', 'verified_claim']);
});

it('usa como valor la cadena que se persiste', function (LinkOrigin $origin, string $value): void {
    expect($origin->value)->toBe($value);
})->with([
    [LinkOrigin::LayawayCreation, 'layaway_creation'],
    [LinkOrigin::VerifiedClaim, 'verified_claim'],
]);
