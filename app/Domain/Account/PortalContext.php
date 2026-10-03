<?php

declare(strict_types=1);

namespace App\Domain\Account;

use App\Models\Customer;
use App\Models\Layaway;
use App\Support\LayawayCode;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;

/**
 * Qué fichas puede ver esta sesión del portal (`REQ-ACC-005` RB-3,
 * `REQ-IDN-004`).
 *
 * Es la **única** fuente de esa respuesta: ninguna consulta del portal lee
 * `customers` ni busca un apartado por su código fuera de aquí. Si cada
 * pantalla decidiera por su cuenta qué fichas son «de la sesión», bastaría
 * una que se equivocara para enseñar el apartado de otra persona.
 *
 * ## Parcial: solo el modo acotado (`T-051`)
 *
 * Hoy la única sesión del portal es la acotada —documento + código o enlace
 * por correo—, y su respuesta es **una ficha de una organización**, con el
 * tenant ya fijado por `ResolvePortalTenant`. La sesión de cuenta (Google),
 * que ve las fichas vinculadas de varios negocios, llega con **`T-076`**:
 * añadirá `$account`, hará que `customers()` devuelva varias y que la
 * prevalencia de `REQ-ACC-005` RB-4 se decida aquí. Quien consume esta clase
 * ya recorre una lista de fichas, así que ese día no cambia.
 */
final readonly class PortalContext
{
    public function __construct(public ?Customer $scopedCustomer) {}

    /**
     * El contexto de la petición actual, desde la sesión del guard `customer`.
     */
    public static function fromSession(): self
    {
        $customer = Auth::guard('customer')->user();

        return new self($customer instanceof Customer ? $customer : null);
    }

    public function isScoped(): bool
    {
        return $this->scopedCustomer !== null;
    }

    /**
     * Las fichas visibles. Con la sesión acotada, exactamente una.
     *
     * @return list<Customer>
     */
    public function customers(): array
    {
        return $this->scopedCustomer === null ? [] : [$this->scopedCustomer];
    }

    /**
     * El apartado de ese código, **si es de una de las fichas visibles**
     * (`REQ-LAY-021` RB-1, SP-1; `REQ-ACC-005` SP-6).
     *
     * Un código de otra persona y uno que no existe dan la misma
     * `ModelNotFoundException`, y con ella el mismo `404`. Va por el global
     * scope: un código de otro negocio tampoco existe para esta búsqueda.
     *
     * @throws ModelNotFoundException<Layaway>
     */
    public function findLayaway(string $code): Layaway
    {
        $customerIds = array_map(fn (Customer $customer): int => (int) $customer->getKey(), $this->customers());

        $layaway = $customerIds === []
            ? null
            : Layaway::query()->where('code', LayawayCode::normalize($code))->whereIn('customer_id', $customerIds)->first();

        if (! $layaway instanceof Layaway) {
            throw (new ModelNotFoundException)->setModel(Layaway::class, [$code]);
        }

        return $layaway;
    }
}
