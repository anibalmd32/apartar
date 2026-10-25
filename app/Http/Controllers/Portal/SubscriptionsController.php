<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Actions\Account\MuteCatalogAction;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Queries\AccountSubscriptionsQuery;
use App\Queries\SubscriptionRow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

final class SubscriptionsController extends Controller
{
    public function index(AccountSubscriptionsQuery $subscriptions): Response
    {
        return Inertia::render('portal/Subscriptions', [
            'account' => ['displayName' => $this->account()->display_name],
            'subscriptions' => array_map(
                fn (SubscriptionRow $row): array => $row->toArray(),
                $subscriptions->forAccount($this->account()),
            ),
        ]);
    }

    public function update(Request $request, int $catalog, MuteCatalogAction $mute): RedirectResponse
    {
        $muted = $request->validate(['muted' => ['required', 'boolean']])['muted'];

        if ($mute->handle($this->account(), $catalog, (bool) $muted) === null) {
            abort(404);
        }

        return back();
    }

    private function account(): Account
    {
        $account = Auth::guard('account')->user();

        if (! $account instanceof Account) {
            throw new LogicException('SubscriptionsController va detrás de account-session.');
        }

        return $account;
    }
}
