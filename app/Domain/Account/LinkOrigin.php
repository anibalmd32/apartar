<?php

declare(strict_types=1);

namespace App\Domain\Account;

enum LinkOrigin: string
{
    case LayawayCreation = 'layaway_creation';

    case VerifiedClaim = 'verified_claim';
}
