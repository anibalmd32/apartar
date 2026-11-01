<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Actions\Account\ClaimCustomerAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\ClaimCustomerRequest;
use App\Models\Account;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class ClaimController extends Controller
{
    public const CLAIMED_MESSAGE = 'Listo: tus apartados en %s ya están en tu cuenta.';

    public function create(): Response
    {
        $account = Auth::guard('account')->user();

        return Inertia::render('portal/Claim', [
            'storeUrl' => route('portal.claim.store'),
            'document' => $account instanceof Account ? $account->document_number : null,
        ]);
    }

    public function store(ClaimCustomerRequest $request, ClaimCustomerAction $claim): RedirectResponse
    {
        $customer = $request->claim($claim);
        $request->session()->regenerateToken();

        $business = (string) Organization::query()->whereKey($customer->organization_id)->value('name');

        return to_route('portal.dashboard')->with('status', sprintf(self::CLAIMED_MESSAGE, $business));
    }
}
