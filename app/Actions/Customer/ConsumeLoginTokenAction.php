<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Auth\PortalLogin;
use App\Domain\Customer\LoginLinkToken;
use App\Domain\Customer\UnusableLoginLinkException;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use App\Models\Customer;
use App\Models\CustomerLoginToken;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Canjea un enlace de acceso y abre la sesión acotada (`REQ-IDN-003` RB-4,
 * RB-7).
 *
 * Se llama desde el `POST` del enlace, nunca desde el `GET` (RB-9): el
 * cliente de correo que precarga los enlaces no puede gastar el token.
 *
 * ## Los pasos
 *
 *  1. El prefijo del token dice la organización, y se fija **antes** de
 *     tocar la tabla: el hash se busca dentro de ella, por el índice
 *     `(organization_id, token_hash)`. Un prefijo que no es de nadie, o que
 *     no es un número, es un enlace que no existe.
 *  2. La fila se bloquea: dos pestañas canjeando el mismo enlace a la vez no
 *     abren dos sesiones con un token de un solo uso.
 *  3. Caducado o ya canjeado → se marca consumido (SP-1) y se rechaza; y se
 *     rechaza **igual** que uno que no existe (SP-2).
 *  4. Si sirve, se marca consumido, se anota el acceso de la ficha y se abre
 *     la sesión con `PortalLogin`, con el mismo recuerdo que la vía del
 *     código.
 *
 * Funciona con la organización suspendida (SP-8): el cliente tiene derecho a
 * ver su deuda, y aquí no se pregunta por el estado del negocio.
 */
final class ConsumeLoginTokenAction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantResolver $resolver,
        private readonly PortalLogin $login,
    ) {}

    /**
     * @throws UnusableLoginLinkException caducado, ya usado o inexistente, sin distinguir
     */
    public function handle(string $plainToken): Customer
    {
        $organizationId = LoginLinkToken::organizationIdIn($plainToken)
            ?? throw new UnusableLoginLinkException('token sin prefijo de organización');

        try {
            $organization = $this->resolver->forOrganizationId($organizationId);
        } catch (ModelNotFoundException) {
            throw new UnusableLoginLinkException('prefijo de una organización que no existe');
        }

        $outcome = $this->context->runFor($organization, fn (): Customer|string => DB::transaction(function () use ($plainToken): Customer|string {
            $token = CustomerLoginToken::query()
                ->where('token_hash', LoginLinkToken::hash($plainToken))
                ->lockForUpdate()
                ->first();

            if (! $token instanceof CustomerLoginToken) {
                return 'token inexistente';
            }

            $now = CarbonImmutable::now();

            if (! $token->isUsable()) {
                // SP-1 · el caducado queda gastado: no se vuelve a evaluar.
                if ($token->consumed_at === null) {
                    $token->forceFill(['consumed_at' => $now])->save();
                }

                return 'token caducado o ya usado';
            }

            $token->forceFill(['consumed_at' => $now])->save();

            $customer = Customer::query()->findOrFail($token->customer_id);
            $customer->forceFill(['last_login_at' => $now])->save();

            return $customer;
        }));

        // El rechazo se lanza **fuera** de la transacción: si se lanzara
        // dentro, la marca de consumido de SP-1 se desharía con él.
        if (is_string($outcome)) {
            throw new UnusableLoginLinkException($outcome);
        }

        $this->login->open($outcome);

        return $outcome;
    }
}
