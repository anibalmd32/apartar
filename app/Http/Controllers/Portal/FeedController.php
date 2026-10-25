<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Account\FeedFilters;
use App\Domain\Account\FeedQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\FeedItemResource;
use App\Models\Account;
use App\Queries\AccountSubscriptionsQuery;
use App\Queries\SubscriptionRow;
use App\Support\CatalogCursor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

final class FeedController extends Controller
{
    public function __invoke(Request $request, FeedQuery $feed, AccountSubscriptionsQuery $subscriptions): Response
    {
        $account = Auth::guard('account')->user();

        if (! $account instanceof Account) {
            throw new LogicException('FeedController va detrás de account-session.');
        }

        $filters = FeedFilters::fromRequest($request);

        return Inertia::render('portal/Feed', [
            'items' => function () use ($feed, $account, $filters, $request): array {
                $cursor = $request->query(CatalogCursor::NAME);
                $page = $feed->forAccount($account, $filters, is_string($cursor) ? $cursor : null);

                return [
                    'data' => array_map(fn (array $item): array => FeedItemResource::toArray($item, $request), $page['items']),
                    'nextCursor' => $page['nextCursor'],
                ];
            },
            'subscriptions' => fn (): array => array_map(
                fn (SubscriptionRow $row): array => $row->toArray(),
                $subscriptions->forAccount($account),
            ),
            'filters' => $filters->toArray(),
        ]);
    }
}
