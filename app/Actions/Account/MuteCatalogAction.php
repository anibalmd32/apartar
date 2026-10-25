<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\Account;
use App\Models\CatalogSubscription;
use Carbon\CarbonImmutable;

final class MuteCatalogAction
{
    public function handle(Account $account, int $catalogId, bool $muted): ?CatalogSubscription
    {
        $subscription = $account->subscriptions()->where('catalog_id', $catalogId)->first();

        if (! $subscription instanceof CatalogSubscription) {
            return null;
        }

        $subscription->forceFill([
            'muted_at' => $muted ? ($subscription->muted_at ?? CarbonImmutable::now()) : null,
        ])->save();

        return $subscription;
    }
}
