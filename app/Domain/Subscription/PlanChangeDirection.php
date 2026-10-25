<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use App\Actions\Subscription\StartFreeSubscriptionAction;
use App\Models\Plan;

enum PlanChangeDirection: string
{
    case Upgrade = 'upgrade';
    case Downgrade = 'downgrade';
    case Same = 'same';

    public static function between(Plan $current, Plan $target): self
    {
        if ($current->is($target)) {
            return self::Same;
        }

        if ($target->is(StartFreeSubscriptionAction::freePlan())) {
            return self::Downgrade;
        }

        return $target->ranksAbove($current) ? self::Upgrade : self::Downgrade;
    }
}
