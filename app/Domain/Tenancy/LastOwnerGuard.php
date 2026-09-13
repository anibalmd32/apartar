<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Garantiza que una organización nunca se queda sin `owner` activo
 * (`REQ-ORG-003` RB-2 y SP-3, `REQ-ORG-005` RB-3 y SP-3).
 *
 * El mecanismo vive aparte de sus dos usos —degradar un rol (`T-013`,
 * `REQ-ORG-004`) y desactivar a alguien (`T-013`, `REQ-ORG-005`)— porque la
 * invariante es la misma y escribirla dos veces significaría, tarde o
 * temprano, corregirla en un sitio y no en el otro.
 *
 * **Lo que de verdad hace falta es el bloqueo, no el conteo.** Contar owners
 * antes de escribir parece suficiente hasta que dos de ellos se degradan a la
 * vez: sin bloqueo, ambas transacciones leen "hay dos", ambas concluyen que
 * pueden proceder, y la organización se queda sin ninguno. El `SELECT … FOR
 * UPDATE` sobre las filas de los owners serializa a las dos: la segunda espera
 * a que la primera termine y entonces lee el estado de verdad —queda uno— y
 * falla. De ese estado no se sale solo, así que prevenirlo es más barato que
 * cualquier forma de repararlo.
 *
 * El conteo va **dentro** de la misma transacción que la escritura: quien
 * llama pasa su cambio como `$callback` y no antes, porque comprobar en una
 * transacción y escribir en otra deja exactamente el hueco que el bloqueo
 * venía a cerrar.
 */
final class LastOwnerGuard
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Ejecuta `$callback` solo si, después de aplicarlo a `$subject`, la
     * organización conserva al menos un `owner` activo.
     *
     * @template TReturn
     *
     * @param  User  $subject  usuario que dejaría de ser `owner` activo
     * @param  string  $operation  lo que se intenta hacer, para el mensaje («degradar», «desactivar»)
     * @param  Closure(): TReturn  $callback  la escritura, que ocurre dentro de la transacción del bloqueo
     * @return TReturn
     *
     * @throws LastOwnerException si `$subject` es el último `owner` activo
     * @throws CrossTenantRelationException si `$subject` no es de la organización activa
     */
    public function whileKeepingAnOwner(User $subject, string $operation, Closure $callback): mixed
    {
        $this->assertBelongsToActiveOrganization($subject);

        return DB::transaction(function () use ($subject, $operation, $callback): mixed {
            $remaining = $this->activeOwners()->reject(fn (User $owner): bool => $owner->is($subject));

            if ($remaining->isEmpty()) {
                throw LastOwnerException::wouldLeaveNone($subject->name, $operation);
            }

            return $callback();
        });
    }

    /**
     * Indica si `$subject` es hoy el único `owner` activo, sin escribir nada.
     *
     * Para la interfaz: es lo que permite deshabilitar el botón y explicar por
     * qué, en lugar de dejar que la persona lo pulse y reciba un `422`. **No
     * sustituye a la comprobación**: entre esta lectura y la escritura cabe
     * otra petición, y por eso `whileKeepingAnOwner()` vuelve a mirar con el
     * bloqueo puesto.
     */
    public function isLastActiveOwner(User $subject): bool
    {
        $this->assertBelongsToActiveOrganization($subject);

        $owners = $this->activeOwners(locked: false);

        return $owners->isNotEmpty()
            && $owners->reject(fn (User $owner): bool => $owner->is($subject))->isEmpty();
    }

    /**
     * Los `owner` activos de la organización del contexto.
     *
     * La consulta va por el global scope del tenant, sin excluirlo: leer los
     * owners de "todas las organizaciones" no tendría sentido aquí, y pedir la
     * exclusión pondría a este archivo en la lista de `REQ-QAX-008` RB-4b, que
     * es una lista cerrada por buenos motivos.
     *
     * @return Collection<int, User>
     */
    private function activeOwners(bool $locked = true): Collection
    {
        $query = User::query()
            ->where('role', OrganizationRole::Owner)
            ->whereNull('disabled_at');

        if ($locked) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * El sujeto tiene que ser de la organización activa.
     *
     * Sin esto, operar sobre alguien de otra organización contaría los owners
     * de la nuestra —el global scope filtra por la activa— y la invariante se
     * comprobaría sobre el negocio equivocado. El binding de ruta ya habría
     * dado `404` (`REQ-ORG-002` RB-6); esto es la red de abajo, para los
     * caminos que no pasan por una ruta.
     */
    private function assertBelongsToActiveOrganization(User $subject): void
    {
        if ($subject->organization_id !== $this->context->organizationId()) {
            throw CrossTenantRelationException::between(User::class, Organization::class);
        }
    }
}
