<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Organization\UpsertPaymentAccountAction;
use App\Domain\Payment\PaymentAccountInUseException;
use App\Domain\Payment\PaymentAccountView;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\UpsertPaymentAccountRequest;
use App\Models\PaymentAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dónde le pagan al negocio (`REQ-ORG-007`).
 *
 * Fino como el de Equipo, y por lo mismo: cada operación es una línea a su
 * acción, que es quien puede garantizar la transacción, el bloqueo del cupo y
 * la degradación del borrado. Lo que este archivo decide es **qué se ve**, y
 * ahí hay tres cosas que no son decoración:
 *
 *  - **los seis métodos viajan con sus etiquetas** resueltas desde
 *    `PaymentMethod`, para que el desplegable no sea un segundo censo de los
 *    casos del enum escrito en el `.tsx`;
 *  - **el consumo del plan viaja con el listado** (`REQ-SUB-003` RB-6), para
 *    que la pantalla diga «2 de 3 cuentas» antes de que nadie choque con el
 *    techo;
 *  - **los avisos de SP-4 y SP-6 llegan como `status`**, no como errores. Las
 *    dos operaciones se completaron; lo que hace falta es que el dueño sepa qué
 *    ha quedado, no que crea que algo falló.
 *
 * El `{account}` se resuelve **ya filtrado** por el tenant, así que el
 * identificador de otra organización da `404` y no `403` (`REQ-ORG-002` RB-6):
 * no se confirma que exista.
 */
final class PaymentAccountController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly LimitEnforcer $limits,
    ) {}

    public function index(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('managePaymentAccounts', $this->context->organization());

        return Inertia::render('backoffice/payment-accounts/Index', [
            'accounts' => $this->accounts(),
            'methods' => $this->methods(),
            'usage' => $this->limits->usageOf(UsageMetric::PaymentAccounts)->toArray(),
        ]);
    }

    public function store(UpsertPaymentAccountRequest $request, UpsertPaymentAccountAction $upsert): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $account = $upsert->handle($request->toData(), $actor);

        return $this->back('Cuenta de cobro guardada.', $upsert->warningsFor($account));
    }

    public function update(
        UpsertPaymentAccountRequest $request,
        PaymentAccount $account,
        UpsertPaymentAccountAction $upsert,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();

        $account = $upsert->handle($request->toData(), $actor, $account);

        return $this->back('Cuenta de cobro actualizada.', $upsert->warningsFor($account));
    }

    /**
     * Borra la cuenta, o informa de que se ha desactivado (RB-6, SP-3).
     *
     * La degradación llega como excepción y **no se responde como error**: la
     * operación se completó, solo que de otra forma que la pedida. Convertirla
     * en un `422` diría que no pasó nada cuando la cuenta ya está desactivada,
     * que es peor que no avisar.
     */
    public function destroy(Request $request, PaymentAccount $account, UpsertPaymentAccountAction $upsert): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $upsert->delete($account, $actor);
        } catch (PaymentAccountInUseException $exception) {
            return $this->back($exception->getMessage(), $upsert->warningsFor($exception->account));
        }

        return $this->back('Cuenta de cobro eliminada.');
    }

    /**
     * Vuelve a la pantalla con lo que haya que contar.
     *
     * Los avisos se pegan al mensaje en vez de viajar en una prop aparte porque
     * son consecuencia de **esta** operación, no estado de la pantalla: al
     * recargar no tienen que seguir ahí.
     *
     * @param  list<string>  $warnings
     */
    private function back(string $status, array $warnings = []): RedirectResponse
    {
        return to_route('payment-accounts.index')
            ->with('status', trim($status.' '.implode(' ', $warnings)));
    }

    /**
     * Las cuentas tal como las pinta la pantalla, **en su orden** (RB-1).
     *
     * `position` manda y el identificador desempata, que es lo que hace que el
     * orden se conserve entre recargas: sin el segundo criterio, dos cuentas
     * con la misma posición saldrían en el orden que quisiera MySQL y la lista
     * bailaría sin que nadie la tocara.
     *
     * La consulta no lleva un solo `where` de organización: el global scope ya
     * filtra (`REQ-ORG-002` RB-1). Y **las desactivadas salen en la lista**,
     * marcadas, por lo mismo que salen los miembros dados de baja: esconderlas
     * convertiría la degradación del borrado en un borrado.
     *
     * @return list<array<string, mixed>>
     */
    private function accounts(): array
    {
        return PaymentAccount::query()
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (PaymentAccount $account): array => PaymentAccountView::of($account)->toArray())
            ->values()
            ->all();
    }

    /**
     * Los seis métodos con lo que la pantalla necesita saber de cada uno.
     *
     * Sale del enum y no de una lista en el `.tsx` por el mismo motivo que las
     * definiciones de ajustes de `T-014`: dos censos de los mismos seis casos
     * coinciden hasta el día en que uno cambia, y el que se queda viejo es
     * siempre el de la pantalla — la que decide qué se puede escribir.
     *
     * @return list<array<string, mixed>>
     */
    private function methods(): array
    {
        return array_map(fn (PaymentMethod $method): array => [
            'value' => $method->value,
            'label' => $method->label(),
            'identifierLabel' => $method->identifierLabel(),
            'isCash' => $method->isCash(),
        ], PaymentMethod::cases());
    }
}
