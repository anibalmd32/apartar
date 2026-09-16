<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Payment\PaymentAccountInUseException;
use App\Domain\Payment\RecordedReceipts;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanLimitReachedException;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\TenantContext;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Dónde le pagan al negocio: alta, edición y baja de sus cuentas de cobro
 * (`REQ-ORG-007`).
 *
 * Es la pieza que hace posible todo H4. Sin una cuenta declarada, un cliente
 * que quiere apartar **no sabe a dónde transferir** y un comprobante no tiene a
 * qué cuenta apuntar; por eso `REQ-CAT-022` exige al menos una activa para
 * publicar un catálogo. Esa comprobación no está aquí —la hace quien publica,
 * `T-032`—: aquí no hay nada que publicar.
 *
 * ## El límite del plan, por segunda vez
 *
 * RB-5 acota las cuentas por plan, y esa es la métrica `payment_accounts` que
 * `LimitEnforcer` sabe contar desde `T-013`. **Esta tarea es la razón exacta
 * por la que aquella adelantó el enforcer a H0** en vez de dejarlo para H5:
 * contar filas a mano aquí habría repetido el mismo apaño que allí se evitó, y
 * [05 §5] dice que ninguna `Action` comprueba límites por su cuenta.
 *
 * Su contrato manda dos cosas y las dos se cumplen aquí:
 *
 *  - `assertCanCreate()` va **dentro de la transacción que escribe**, porque el
 *    contador se lee bloqueado con `FOR UPDATE` y un `lockForUpdate()` en
 *    *autocommit* no serializa nada;
 *  - `synchronise()` va al terminar de mutar, **en esa misma transacción**.
 *
 * Lo que consume cupo es una cuenta **activa**, que es lo que mide la métrica.
 * De ahí que crear una desactivada no lo gaste y **reactivarla sí**: es la
 * misma simetría que `EnableTeamMemberAction`, y sin ella desactivar y
 * reactivar sería la forma de rebasar cualquier plan sin pagarlo.
 *
 * ## Lo que esta acción no hace, y es deliberado
 *
 * **No rechaza dos cuentas idénticas** (SP-6) y **no despublica nada al
 * desactivar la última activa** (SP-4). Las dos se avisan y se permiten, y las
 * dos razones son la misma: hay negocios con dos cuentas en el mismo banco, y
 * un ajuste rutinario de configuración que tumbara la vitrina sería un castigo
 * desproporcionado. Los avisos los produce `warningsFor()`.
 */
final class UpsertPaymentAccountAction
{
    use LogsSensitiveActivity;

    /**
     * Campos de la cuenta que viajan al «antes y después» de la auditoría.
     *
     * Están los ocho de la fila, y eso es deliberado: cambiar el titular o el
     * identificador de una cuenta de cobro es **redirigir el dinero de los
     * clientes**, así que la pregunta que esta entrada tiene que responder no
     * es «¿se tocó?» sino «¿a dónde apuntaba antes?».
     *
     * @var list<string>
     */
    private const AUDITED_FIELDS = [
        'method',
        'bank',
        'holder_name',
        'holder_document',
        'identifier',
        'instructions',
        'is_active',
        'position',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly LimitEnforcer $limits,
        private readonly RecordedReceipts $receipts,
    ) {}

    /**
     * Crea una cuenta o guarda los cambios de una existente.
     *
     * @param  PaymentAccount|null  $account  la que se edita; `null` crea una nueva
     * @param  User  $actor  quien guarda; solo el `owner` gobierna esto (RB-1 de `REQ-ORG-003`)
     *
     * @throws AuthorizationException si el actor no gobierna las cuentas de cobro
     * @throws PlanLimitReachedException si el plan no admite otra cuenta activa (SP-2)
     */
    public function handle(UpsertPaymentAccountData $data, User $actor, ?PaymentAccount $account = null): PaymentAccount
    {
        Gate::forUser($actor)->authorize('managePaymentAccounts', $this->context->organization());

        return DB::transaction(function () use ($data, $account, $actor): PaymentAccount {
            $wasActive = $account instanceof PaymentAccount && $account->is_active;
            $before = $this->auditedFieldsOf($account);

            // SP-2 · solo se pide cupo cuando de verdad se ocupa uno: al crear
            // una cuenta activa y al reactivar una que estaba apagada. Editar
            // el titular de una cuenta que ya cuenta no consume nada, y
            // pedirlo daría un 422 a quien no está añadiendo nada.
            if ($data->isActive && ! $wasActive) {
                $this->limits->assertCanCreate(UsageMetric::PaymentAccounts);
            }

            $attributes = [
                'method' => $data->method,
                'bank' => $data->bank,
                'holder_name' => $data->holderName,
                'holder_document' => $data->holderDocument,
                'identifier' => $data->identifier,
                'instructions' => $data->instructions,
                'is_active' => $data->isActive,

                // Guardar sin tocar el orden **conserva el que había**: editar
                // el titular de una cuenta no puede moverla de sitio en la
                // lista que sus clientes ya conocen. Una nueva va al final.
                'position' => $data->position
                    ?? ($account instanceof PaymentAccount ? $account->position : $this->nextPosition()),
            ];

            $isNew = ! $account instanceof PaymentAccount;

            if ($account instanceof PaymentAccount) {
                $account->forceFill($attributes)->save();
            } else {
                $account = PaymentAccount::query()->create($attributes);
            }

            // RB-1 · «alta, edición y desactivación de cuentas de cobro». El
            // alta y la edición se distinguen porque responden a preguntas
            // distintas: una es «¿desde cuándo cobramos aquí?» y la otra «¿qué
            // número había antes de este?».
            $this->audit(
                $isNew ? AuditEvent::PaymentAccountCreated : AuditEvent::PaymentAccountUpdated,
                subject: $account,
                actor: $actor,
                before: $before,
                after: $this->auditedFieldsOf($account),
            );

            // El consumo se materializa en la misma transacción
            // (`REQ-SUB-003` RB-3): quien mire la pantalla un instante después
            // ve «2 de 3 cuentas», no la cifra de antes de guardar.
            $this->limits->synchronise(UsageMetric::PaymentAccounts);

            return $account;
        });
    }

    /**
     * Borra la cuenta… salvo que tenga comprobantes, en cuyo caso la desactiva
     * (RB-6, SP-3).
     *
     * **La degradación no es una cortesía: es la trazabilidad del dinero.** Un
     * comprobante es la prueba de a dónde se pagó, y borrar la cuenta lo deja
     * apuntando al vacío en un producto cuya función es conciliar pagos. El
     * mismo motivo por el que un usuario nunca se borra (`REQ-ORG-005` RB-1).
     *
     * Quién tiene comprobantes lo responde `RecordedReceipts`, sustituible en
     * el contenedor: `receipts` llega con `T-054` y **hasta entonces la
     * respuesta es que ninguna cuenta los tiene**, que es literalmente cierto.
     * Cuando esa tabla exista y nadie haya rellenado el cuerpo, esa clase
     * **lanza** en vez de responder que no — el fallo cerrado que evita que
     * esta regla quede apagada en silencio.
     *
     * @throws AuthorizationException si el actor no gobierna las cuentas de cobro
     * @throws PaymentAccountInUseException si tenía comprobantes: **la cuenta ya quedó desactivada**
     */
    public function delete(PaymentAccount $account, User $actor): void
    {
        Gate::forUser($actor)->authorize('managePaymentAccounts', $this->context->organization());

        $inUse = $this->receipts->existFor($account);

        DB::transaction(function () use ($account, $inUse, $actor): void {
            $before = $this->auditedFieldsOf($account);

            if ($inUse) {
                $account->forceFill(['is_active' => false])->save();
            } else {
                $account->delete();
            }

            // **Un solo evento para las dos salidas**, con `properties`
            // diciendo cuál fue. RB-1 nombra la desactivación porque RB-6 es la
            // vía normal, pero el borrado de verdad ocurre cuando no hay
            // comprobantes — y esa es precisamente la operación cuyo único
            // rastro va a ser esta entrada, porque la fila desaparece.
            $this->audit(
                AuditEvent::PaymentAccountRemoved,
                subject: $account,
                actor: $actor,
                before: $before,
                after: ['estado' => $inUse ? 'desactivada por tener comprobantes' : 'eliminada'],
            );

            // RB-5 · las dos ramas liberan la unidad, porque la métrica cuenta
            // las **activas**: una cuenta desactivada ya no ocupa sitio en el
            // plan, y cobrarle por ella al negocio sería cobrarle por un dato
            // que conservamos nosotros para nuestra propia trazabilidad.
            $this->limits->synchronise(UsageMetric::PaymentAccounts);
        });

        if ($inUse) {
            // Fuera de la transacción **a propósito**: lanzar dentro la
            // desharía y la cuenta volvería a estar activa. Lo que esto
            // comunica no es un fallo, es que la operación se completó de otra
            // forma, y SP-3 exige informarlo. El porqué de que sea una
            // excepción y no un booleano está escrito en la clase.
            throw PaymentAccountInUseException::deactivatedInstead($account);
        }
    }

    /**
     * Avisos que acompañan a un guardado **ya escrito** (SP-4, SP-6).
     *
     * Se calculan después y sobre el estado resultante, no antes y sobre la
     * intención: lo que hay que avisar es lo que el negocio **tiene ahora**,
     * y preguntárselo a los datos de entrada obligaría a simular el resultado.
     *
     * Ninguno bloquea nada. Son avisos porque las dos situaciones son legítimas
     * y solo quien conoce su negocio puede decidirlas.
     *
     * @return list<string>
     */
    public function warningsFor(PaymentAccount $account): array
    {
        $warnings = [];

        if ($this->hasTwin($account)) {
            $warnings[] = sprintf(
                'Ya tenías otra cuenta de %s con ese %s. Se ha guardado igual: hay negocios con dos cuentas en el mismo banco.',
                $account->method->label(),
                mb_strtolower($account->method->identifierLabel()),
            );
        }

        if ($this->noneLeftActive()) {
            $warnings[] = 'Te has quedado sin ninguna cuenta de cobro activa. Tus catálogos publicados siguen publicados, '
                .'pero tus clientes no verán a dónde pagarte hasta que actives una.';
        }

        return $warnings;
    }

    /**
     * Los campos auditados de una cuenta, o nada si todavía no existe.
     *
     * @return array<string, mixed>
     */
    private function auditedFieldsOf(?PaymentAccount $account): array
    {
        if (! $account instanceof PaymentAccount) {
            return [];
        }

        $fields = [];

        foreach (self::AUDITED_FIELDS as $field) {
            $fields[$field] = $account->getAttribute($field);
        }

        return $fields;
    }

    /**
     * Si existe otra cuenta con el mismo método y el mismo identificador
     * (SP-6).
     *
     * Se comparan en PHP y no con un `where`, y es lo correcto aquí: lo que
     * hace iguales a dos cuentas es el identificador **reducido** —sin guiones
     * ni mayúsculas—, que no es lo que hay en la columna. Podría normalizarse
     * al guardar, pero entonces el catálogo enseñaría `04141234567` en lugar
     * del `0414-1234567` que el negocio escribió. El coste del recorrido está
     * acotado por el propio techo del plan.
     */
    private function hasTwin(PaymentAccount $account): bool
    {
        $twin = UpsertPaymentAccountData::comparable($account->identifier);

        return PaymentAccount::query()
            ->where('method', $account->method)
            ->whereKeyNot($account->getKey())
            ->get()
            ->contains(fn (PaymentAccount $other): bool => UpsertPaymentAccountData::comparable($other->identifier) === $twin);
    }

    /**
     * Si la organización se ha quedado sin ninguna cuenta activa (SP-4).
     */
    private function noneLeftActive(): bool
    {
        return ! PaymentAccount::query()->where('is_active', true)->exists();
    }

    /**
     * Posición de una cuenta nueva: la última.
     *
     * El `max` va por el global scope, así que cuenta las de esta organización
     * y solo las de esta organización (`REQ-ORG-002` RB-1).
     */
    private function nextPosition(): int
    {
        return (int) PaymentAccount::query()->max('position') + 1;
    }
}
