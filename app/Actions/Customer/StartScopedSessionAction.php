<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Auth\PortalLogin;
use App\Domain\Customer\AccessVerificationFailedException;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use App\Models\Customer;
use App\Models\CustomerMerge;
use App\Models\Layaway;
use App\Support\DocumentNumber;
use App\Support\LayawayCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Abre la sesión acotada del portal con documento + código de apartado
 * (`REQ-IDN-008`).
 *
 * Es la **vía universal** (RB-1): no pide correo ni cuenta de Google, y por
 * eso es la que garantiza que nadie se quede fuera de su propia información.
 *
 * ## Los dos datos, y de la misma ficha
 *
 *  1. El código se normaliza (SP-7) y, si no tiene la forma canónica, es un
 *     código que no existe: no se consulta nada.
 *  2. **El código dice la organización** (RB-3): `layaways.code` es único en
 *     toda la plataforma, así que se resuelve sin tenant previo
 *     (`TenantResolver::forLayawayCode()`), y a partir de ahí todo se lee con
 *     ese tenant fijado.
 *  3. **El documento tiene que ser el de la ficha de ese apartado** (RB-2).
 *     No basta con que la cédula tenga *algún* apartado en ese negocio: el
 *     código es de una ficha concreta, y la cédula es semipública.
 *
 * Los tres fallos —código inexistente, código ajeno, documento que no casa—
 * son **la misma** `AccessVerificationFailedException` (RB-5). El límite de
 * intentos lo pone `StartScopedSessionRequest`, antes de llegar aquí, para
 * que un intento bloqueado no cueste ni una consulta.
 *
 * ## Lo que abre
 *
 * Una sesión del guard `customer` sobre **la ficha**, no sobre el apartado
 * (RB-4): da acceso a todos los apartados de esa persona en ese negocio, con
 * recuerdo de 30 días. Funciona con apartados en cualquier estado (RB-7) y con
 * la organización suspendida o cerrada (RB-8): aquí no se pregunta ni por el
 * estado del apartado ni por el del negocio.
 *
 * La ficha absorbida por una fusión (SP-6, `REQ-IDN-006`): su apartado ya
 * apunta a la superviviente, y el documento con el que se apartó sigue
 * abriendo la sesión porque queda en `customer_merges`.
 */
final class StartScopedSessionAction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantResolver $resolver,
        private readonly PortalLogin $login,
    ) {}

    /**
     * Verifica documento + código y abre la sesión de esa ficha.
     *
     * @throws AccessVerificationFailedException para los tres fallos, sin distinguirlos
     */
    public function handle(DocumentNumber $document, string $layawayCode): Customer
    {
        $code = LayawayCode::normalize($layawayCode);

        if (! LayawayCode::isWellFormed($code)) {
            throw new AccessVerificationFailedException('código mal formado');
        }

        try {
            $organization = $this->resolver->forLayawayCode($code);
        } catch (ModelNotFoundException) {
            throw new AccessVerificationFailedException('código inexistente');
        }

        $customer = $this->context->runFor($organization, function () use ($code, $document): Customer {
            $layaway = Layaway::query()->where('code', $code)->first();

            $customer = $layaway instanceof Layaway
                ? Customer::query()->whereKey($layaway->customer_id)->where('document_number', $document->value)->first()
                : null;

            // `REQ-IDN-008` SP-6 · el documento de una ficha que se fusionó con
            // la del apartado: la persona entra con el documento con el que
            // apartó, y llega a la superviviente.
            if (! $customer instanceof Customer && $layaway instanceof Layaway && CustomerMerge::query()
                ->where('survivor_customer_id', $layaway->customer_id)
                ->where('absorbed_document_number', $document->value)
                ->exists()) {
                $customer = Customer::query()->find($layaway->customer_id);
            }

            if (! $customer instanceof Customer) {
                throw new AccessVerificationFailedException('el documento no es el de la ficha del apartado');
            }

            $customer->forceFill(['last_login_at' => CarbonImmutable::now()])->save();

            return $customer;
        });

        // RB-4 · el guard `customer`, con su recuerdo de 30 días.
        $this->login->open($customer);

        return $customer;
    }
}
