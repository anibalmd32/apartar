<?php

declare(strict_types=1);

namespace App\Actions\Notification;

use App\Domain\Notification\NotificationType;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Tenancy\TenantContext;
use App\Models\Account;
use App\Models\Catalog;
use App\Models\CatalogSubscription;
use App\Models\Organization;
use App\Models\Product;
use App\Notifications\CustomerMessageNotification;
use App\Support\CatalogShareLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DispatchCatalogDigestAction
{
    public const LOCAL_HOUR = 10;

    public const MIN_PRODUCTS = 1;

    public function __construct(
        private readonly TenantContext $context,
        private readonly LimitEnforcer $limits,
    ) {}

    /**
     * @return array{sent: int, potentialReach: int}
     */
    public function handle(Organization $organization, CarbonImmutable $now): array
    {
        return $this->context->runFor($organization, function () use ($organization, $now): array {
            $catalogs = Catalog::query()->get()->filter(fn (Catalog $catalog): bool => $catalog->visibility()->isPubliclyAccessible());
            $ids = $catalogs->modelKeys();

            if (! $this->limits->hasFeature(PlanFeature::SubscriberDigest)) {
                $reach = $ids === [] ? 0 : CatalogSubscription::query()->whereIn('catalog_id', $ids)->whereNull('muted_at')->distinct()->count('account_id');

                return ['sent' => 0, 'potentialReach' => $reach];
            }

            $sent = 0;
            $date = $now->setTimezone($organization->timezone)->toDateString();

            foreach ($catalogs as $catalog) {
                $fresh = Product::query()
                    ->published()
                    ->inCatalog($catalog)
                    ->where('catalog_product.created_at', '>=', $now->subDay())
                    ->count();

                if ($fresh < self::MIN_PRODUCTS) {
                    continue;
                }

                $accountIds = CatalogSubscription::query()->where('catalog_id', $catalog->getKey())->whereNull('muted_at')->pluck('account_id');

                foreach ($accountIds as $accountId) {
                    $inserted = DB::table('catalog_digest_dispatches')->insertOrIgnore([
                        'catalog_id' => $catalog->getKey(),
                        'account_id' => $accountId,
                        'digest_date' => $date,
                        'products_count' => $fresh,
                        'sent_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $account = Account::query()->find($accountId);

                    if ($inserted !== 1 || ! $account instanceof Account) {
                        continue;
                    }

                    $account->notify(new CustomerMessageNotification(
                        NotificationType::CatalogDigest,
                        $organization->name,
                        __('notifications.catalog_digest.title', ['negocio' => $organization->name], 'es'),
                        [trans_choice('notifications.catalog_digest.line', $fresh, ['count' => $fresh, 'catalog' => $catalog->name], 'es')],
                        CatalogShareLink::of($catalog->slug),
                        $catalog->name,
                        push: true,
                    ));
                    $sent++;
                }
            }

            return ['sent' => $sent, 'potentialReach' => 0];
        });
    }
}
