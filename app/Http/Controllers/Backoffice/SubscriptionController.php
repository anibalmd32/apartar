<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `GET /backoffice/suscripcion` (`REQ-SUB-001`, `REQ-SUB-003` RB-6, RB-7,
 * `REQ-SUB-004` RB-7, `T-067`): el plan contratado, lo consumido de cada
 * métrica y las funciones que incluye y las que no.
 *
 * Es el destino de cada «Ver planes» —el `422` del límite, `LimitBanner`, el
 * aviso de suspensión del layout—, y por eso **sobrevive a la suspensión**: es
 * donde se regulariza. El plan y su consumo no viajan como prop propia: ya
 * los comparte `HandleInertiaRequests` (`planState`) con cada página del
 * backoffice, y la página los lee con `usePlanLimits`, como el resto.
 *
 * Solo lectura. Cambiar de plan, con su previsualización, es de `T-068`; las
 * facturas, de `T-069`.
 */
final class SubscriptionController extends Controller
{
    public function show(Request $request): Response
    {
        Gate::authorize('view', Subscription::query()->firstOrFail());

        return Inertia::render('backoffice/subscription/Show', [
            'pricingUrl' => route('pricing'),
        ]);
    }
}
