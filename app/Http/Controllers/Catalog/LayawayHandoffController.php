<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Auth\PortalLogin;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\AccessController;
use App\Models\Catalog;
use App\Models\Customer;
use App\Models\Layaway;
use App\Support\LayawayCode;
use App\Support\LayawayHandoff;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class LayawayHandoffController extends Controller
{
    public function __invoke(Request $request, Catalog $catalog, string $code, PortalLogin $login): RedirectResponse
    {
        $code = LayawayCode::normalize($code);
        $fallback = redirect()->route('portal.access', [AccessController::CODE_PARAMETER => $code]);

        if (! LayawayHandoff::take($request->session(), $code)) {
            Log::info('catalog.layaway.handoff_refused', ['ip' => $request->ip()]);

            return $fallback;
        }

        if (Auth::guard('account')->check()) {
            return $fallback;
        }

        $layaway = Layaway::query()->where('code', $code)->where('catalog_id', $catalog->getKey())->first();
        $customer = $layaway instanceof Layaway ? Customer::query()->find($layaway->customer_id) : null;

        if (! $customer instanceof Customer) {
            return $fallback;
        }

        $customer->forceFill(['last_login_at' => CarbonImmutable::now()])->save();
        $login->open($customer);
        $request->session()->regenerate();

        return redirect()->route('portal.pay', [AccessController::CODE_PARAMETER => $code]);
    }
}
