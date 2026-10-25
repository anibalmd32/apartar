<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Domain\Account\CatalogNotSubscribableException;
use App\Domain\Telemetry\ServerTelemetry;
use App\Domain\Telemetry\TelemetryEventName;
use App\Models\Account;
use App\Models\Catalog;
use App\Models\CatalogSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class SubscribeToCatalogAction
{
    /**
     * @throws CatalogNotSubscribableException
     */
    public function handle(Account $account, Catalog $catalog): CatalogSubscription
    {
        if (! $catalog->visibility()->isPubliclyAccessible()) {
            throw CatalogNotSubscribableException::for($catalog);
        }

        $now = CarbonImmutable::now();

        $inserted = DB::table('catalog_subscriptions')->insertOrIgnore([
            'account_id' => $account->getKey(),
            'catalog_id' => $catalog->getKey(),
            'subscribed_at' => $now,
            'muted_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $subscription = CatalogSubscription::query()
            ->where('account_id', $account->getKey())
            ->where('catalog_id', $catalog->getKey())
            ->firstOrFail();

        $subscription->wasRecentlyCreated = $inserted === 1;

        if ($subscription->wasRecentlyCreated) {
            ServerTelemetry::record(
                TelemetryEventName::CatalogSubscribed,
                organizationId: (int) $catalog->organization_id,
                catalogId: (int) $catalog->getKey(),
                subjectId: (int) $catalog->getKey(),
                accountId: (int) $account->getKey(),
            );
        }

        return $subscription;
    }
}
