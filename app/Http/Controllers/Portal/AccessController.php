<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Actions\Customer\StartScopedSessionAction;
use App\Auth\AccountSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\StartScopedSessionRequest;
use App\Jobs\LinkVisitorEventsToCustomer;
use App\Support\LayawayCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class AccessController extends Controller
{
    public const CODE_PARAMETER = 'codigo';

    public function create(Request $request): Response|RedirectResponse
    {
        $code = $this->codeFrom($request);
        $next = $code === null ? 'portal.dashboard' : 'portal.pay';

        if (Auth::guard('account')->check() || Auth::guard('customer')->check()) {
            return redirect()->route($next);
        }

        if ($code !== null) {
            redirect()->setIntendedUrl(route('portal.pay'));
        }

        return Inertia::render('portal/Access', [
            'status' => $request->session()->get('status'),
            'code' => $code,
            'googleUrl' => route('account.google.redirect', ['luego' => route($next, absolute: false)]),
        ]);
    }

    public function storeCode(StartScopedSessionRequest $request, StartScopedSessionAction $start): RedirectResponse
    {
        $customer = $request->authenticate($start);

        LinkVisitorEventsToCustomer::dispatchFor($request, $customer);

        $request->session()->regenerate();

        return redirect()->intended(route('portal.dashboard'));
    }

    private function codeFrom(Request $request): ?string
    {
        $raw = $request->query(self::CODE_PARAMETER);

        if (! is_string($raw)) {
            return null;
        }

        $code = LayawayCode::normalize($raw);

        return LayawayCode::isWellFormed($code) ? $code : null;
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
