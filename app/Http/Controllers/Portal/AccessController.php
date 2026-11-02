<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Actions\Customer\StartScopedSessionAction;
use App\Auth\AccountSession;
use App\Domain\Account\PortalContext;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use App\Http\Controllers\Catalog\LayawayController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\StartScopedSessionRequest;
use App\Jobs\LinkVisitorEventsToCustomer;
use App\Models\Layaway;
use App\Models\Organization;
use App\Support\LayawayCode;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class AccessController extends Controller
{
    public const CODE_PARAMETER = 'codigo';

    public function create(Request $request, AccountSession $account, TenantContext $context, TenantResolver $resolver): Response|RedirectResponse
    {
        $code = $this->codeFrom($request);
        $next = $code === null ? route('portal.dashboard', absolute: false) : route('portal.pay', [self::CODE_PARAMETER => $code], absolute: false);

        if (Auth::guard('account')->check() || Auth::guard('customer')->check()) {
            if ($code === null || $this->sessionSees(PortalContext::fromSession(), $context, $code)) {
                return redirect($next);
            }

            Auth::guard('customer')->logout();
            $account->close();
        }

        if ($code !== null) {
            redirect()->setIntendedUrl(route('portal.pay', [self::CODE_PARAMETER => $code]));
        }

        return Inertia::render('portal/Access', [
            'status' => $request->session()->get('status'),
            'code' => $code,
            'businessName' => $code === null ? null : $this->businessJustReserved($request, $resolver, $code),
            'googleUrl' => route('account.google.redirect', ['luego' => $next]),
        ]);
    }

    private function businessJustReserved(Request $request, TenantResolver $resolver, string $code): ?string
    {
        $confirmed = $request->session()->get(LayawayController::SESSION_KEY, []);

        if (! is_array($confirmed) || ! in_array($code, $confirmed, true)) {
            return null;
        }

        try {
            return $resolver->forLayawayCode($code)->name;
        } catch (ModelNotFoundException) {
            return null;
        }
    }

    public function storeCode(StartScopedSessionRequest $request, StartScopedSessionAction $start): RedirectResponse
    {
        $customer = $request->authenticate($start);

        LinkVisitorEventsToCustomer::dispatchFor($request, $customer);

        $request->session()->regenerate();

        if ($customer->document_number === null && $request->filled('document')) {
            $request->session()->put(DocumentController::TYPED_DOCUMENT, (string) $request->string('document'));
        }

        return redirect()->intended(route('portal.dashboard'));
    }

    private function sessionSees(PortalContext $portal, TenantContext $context, string $code): bool
    {
        foreach ($portal->customers() as $customer) {
            $organization = Organization::query()->find($customer->organization_id);

            if ($organization instanceof Organization && $context->runFor($organization, fn (): bool => Layaway::query()
                ->where('code', $code)
                ->where('customer_id', $customer->getKey())
                ->exists())) {
                return true;
            }
        }

        return false;
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
