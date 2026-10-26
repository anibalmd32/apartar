<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Account\FeedFilters;
use App\Domain\Account\FeedQuery;
use App\Domain\Account\PortalContext;
use App\Domain\Exchange\CurrentRate;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Tenancy\OrganizationBrand;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Portal\PushSubscriptionController;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Layaway;
use App\Queries\BusinessBalance;
use App\Queries\PortalAlert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property list<BusinessBalance> $resource
 */
final class PortalDashboardResource extends JsonResource
{
    use ProjectsMoney;

    public const FEED_PREVIEW_SIZE = 6;

    /**
     * @param  list<BusinessBalance>  $blocks
     * @param  list<PortalAlert>  $alerts
     */
    public function __construct(array $blocks, private readonly array $alerts, private readonly PortalContext $portal)
    {
        parent::__construct($blocks);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $customer = $this->portal->scopedCustomer;
        $account = $this->portal->account;

        return [
            'session' => [
                'mode' => $account instanceof Account ? 'account' : 'scoped',
                'displayName' => match (true) {
                    $account instanceof Account => $account->display_name,
                    $customer instanceof Customer => $customer->full_name,
                    default => '',
                },
            ],
            'businesses' => array_map(fn (BusinessBalance $block): array => app(TenantContext::class)->runFor(
                $block->organization,
                fn (): array => $this->block($block, $request),
            ), $this->resource),
            'totals' => $account instanceof Account ? $this->totals() : null,
            'connectGoogleUrl' => $account instanceof Account
                ? null
                : route('account.google.redirect', ['luego' => route('portal.dashboard', absolute: false)]),
            'subscriptionsUrl' => $account instanceof Account ? route('portal.subscriptions') : null,
            'claimUrl' => $account instanceof Account ? route('portal.claim') : null,
            'feedPreview' => $account instanceof Account ? $this->feedPreview($account, $request) : null,
            'push' => [
                'publicKey' => PushSubscriptionController::isConfigured() ? (string) config('webpush.vapid.public_key') : null,
                'subscribeUrl' => route('portal.push.store'),
                'hasEmail' => $account instanceof Account || ($customer instanceof Customer && $customer->email !== null),
            ],
            'alerts' => array_map(fn (PortalAlert $alert): array => (new PortalAlertResource($alert))->resolve($request), $this->alerts),
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, url: string}
     */
    private function feedPreview(Account $account, Request $request): array
    {
        $page = app(FeedQuery::class)->forAccount($account, new FeedFilters, limit: self::FEED_PREVIEW_SIZE);

        return [
            'items' => array_map(fn (array $item): array => FeedItemResource::toArray($item, $request), $page['items']),
            'url' => route('portal.feed'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function totals(): array
    {
        $across = fn (callable $amountOf): array => $this->moneyAcrossBusinesses(array_map(
            fn (BusinessBalance $block): array => ['organization' => $block->organization, 'amount' => $amountOf($block)],
            $this->resource,
        ));

        $dates = array_filter(array_map(fn (BusinessBalance $block) => $block->nextDueDate(), $this->resource));
        usort($dates, fn ($a, $b): int => $a->getTimestamp() <=> $b->getTimestamp());
        $next = $dates[0] ?? null;

        return [
            'activeLayaways' => array_sum(array_map(fn (BusinessBalance $block): int => count($block->active), $this->resource)),
            'committed' => $across(fn (BusinessBalance $block) => $block->committed()),
            'paid' => $across(fn (BusinessBalance $block) => $block->paid()),
            'remaining' => $across(fn (BusinessBalance $block) => $block->remaining()),
            'overdueInstallments' => array_sum(array_map(fn (BusinessBalance $block): int => $block->overdueInstallments(), $this->resource)),
            'nextDueDate' => $next?->toDateString(),
            'nextDueDateLabel' => LayawaySummaryResource::dayLabel($next),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function block(BusinessBalance $block, Request $request): array
    {
        $summaries = fn (array $layaways): array => array_map(
            fn (Layaway $layaway): array => (new LayawaySummaryResource($layaway))->resolve($request),
            $layaways,
        );

        return [
            'business' => OrganizationBrand::of($block->organization, app(LimitEnforcer::class)->hasFeature(PlanFeature::Branding))->toArray(),
            'closed' => $block->businessIsClosed(),
            'rate' => app(CurrentRate::class)->view()->toArray(),
            'totals' => [
                'activeLayaways' => count($block->active),
                'committed' => $this->money($block->committed()),
                'paid' => $this->money($block->paid()),
                'remaining' => $this->money($block->remaining()),
                'overdueInstallments' => $block->overdueInstallments(),
                'nextDueDate' => $block->nextDueDate()?->toDateString(),
                'nextDueDateLabel' => LayawaySummaryResource::dayLabel($block->nextDueDate()),
            ],
            'layaways' => $summaries($block->active),
            'history' => $summaries($block->history),
        ];
    }
}
