<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Actions\Customer\StartScopedSessionAction;
use App\Auth\AccountSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\StartScopedSessionRequest;
use App\Jobs\LinkVisitorEventsToCustomer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class AccessController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        if (Auth::guard('account')->check() || Auth::guard('customer')->check()) {
            return redirect()->route('portal.dashboard');
        }

        return Inertia::render('portal/Access', [
            'status' => $request->session()->get('status'),
            'googleUrl' => route('account.google.redirect', ['luego' => route('portal.dashboard', absolute: false)]),
        ]);
    }

    public function storeCode(StartScopedSessionRequest $request, StartScopedSessionAction $start): RedirectResponse
    {
        $customer = $request->authenticate($start);

        LinkVisitorEventsToCustomer::dispatchFor($request, $customer);

        $request->session()->regenerate();

        return redirect()->intended(route('portal.dashboard'));
    }

    public function destroy(Request $request, AccountSession $account): RedirectResponse
    {
        Auth::guard('customer')->logout();
        $account->close();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.access');
    }
}
