<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Domain\Telemetry\ServerTelemetry;
use App\Domain\Telemetry\TelemetryEventName;
use App\Models\Account;
use Illuminate\Support\Facades\DB;

final class UnsubscribeFromCatalogAction
{
    public function handle(Account $account, int $catalogId): bool
    {
        $deleted = $account->subscriptions()->where('catalog_id', $catalogId)->delete() > 0;

        if ($deleted) {
            $organizationId = DB::table('catalogs')->where('id', $catalogId)->value('organization_id');

            ServerTelemetry::record(
                TelemetryEventName::CatalogUnsubscribed,
                organizationId: is_numeric($organizationId) ? (int) $organizationId : null,
                catalogId: is_numeric($organizationId) ? $catalogId : null,
                subjectId: $catalogId,
                accountId: (int) $account->getKey(),
            );
        }

        return $deleted;
    }
}
