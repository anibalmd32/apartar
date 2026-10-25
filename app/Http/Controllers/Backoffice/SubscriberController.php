<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanFeature;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriberRowResource;
use App\Models\Customer;
use App\Queries\SubscriberListQuery;
use App\Queries\SubscriberStatsQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class SubscriberController extends Controller
{
    public function index(Request $request, SubscriberListQuery $list, SubscriberStatsQuery $stats, LimitEnforcer $limits): Response
    {
        Gate::forUser($request->user())->authorize('viewAny', Customer::class);

        $page = $list->forOrganization();
        $numbers = $stats->forOrganization();
        $full = $limits->hasFeature(PlanFeature::FullAnalytics);

        return Inertia::render('backoffice/subscribers/Subscribers', [
            'subscribers' => SubscriberRowResource::collectionOf($page['rows']),
            'nextPageUrl' => $page['nextPageUrl'],
            'stats' => $full
                ? $numbers
                : ['total' => $numbers['total'], 'people' => null, 'last7Days' => null, 'byCatalog' => null, 'previous7Days' => null, 'customers' => null, 'weekly' => null],
        ]);
    }
}
