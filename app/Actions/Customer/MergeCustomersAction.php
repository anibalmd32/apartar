<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Customer\CannotMergeCustomerException;
use App\Domain\Customer\MergeSummary;
use App\Models\Customer;
use App\Models\CustomerLoginToken;
use App\Models\CustomerMerge;
use App\Models\Layaway;
use App\Models\Receipt;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Fusiona dos fichas que resultaron ser la misma persona (`REQ-IDN-006`).
 *
 * Todo en una transacción, con **las dos fichas bloqueadas en orden de id**
 * —dos fusiones cruzadas de la misma pareja se esperan en vez de bloquearse
 * en cruz (SP-4)—:
 *
 *  1. **Las dos existen, son distintas y son de este negocio** (RB-1, RB-7,
 *     SP-1), comprobado bajo el bloqueo: una fusión anterior de la misma
 *     transacción o una concurrente puede haberse llevado una.
 *  2. **El vínculo de cuenta** (RB-4): si solo la absorbida está vinculada, el
 *     vínculo pasa a la superviviente; si las dos lo están a cuentas
 *     distintas, no se fusiona.
 *  3. **Se mueve todo lo suyo** (RB-2): apartados —y con ellos sus pagos—,
 *     comprobantes y los enlaces de acceso en vuelo. Los balances de los
 *     apartados no se recalculan (RB-6): son del apartado.
 *  4. **Queda el rastro de adónde fue** (`CustomerMerge`): su URL, su sesión
 *     del portal y su documento siguen llevando a la superviviente. Las
 *     fusiones que apuntaban a la absorbida pasan a apuntar a la superviviente.
 *  5. **La absorbida se borra** (RB-5): si le quedara algo, las claves
 *     `restrict` de `layaways` y `receipts` lo impedirían y todo vuelve atrás
 *     (SP-6).
 *  6. **La auditoría**, con lo movido.
 */
final class MergeCustomersAction
{
    use LogsSensitiveActivity;

    /**
     * Lo que movería, sin moverlo: el resumen que se confirma (RB-8).
     */
    public function preview(Customer $survivor, Customer $absorbed): MergeSummary
    {
        return new MergeSummary(
            layaways: Layaway::query()->where('customer_id', $absorbed->getKey())->count(),
            receipts: Receipt::query()->where('customer_id', $absorbed->getKey())->count(),
            loginTokens: CustomerLoginToken::query()->where('customer_id', $absorbed->getKey())->count(),
            accountMoves: $absorbed->account_id !== null && $survivor->account_id === null,
        );
    }

    /**
     * @throws AuthorizationException si quien fusiona no gestiona los clientes de ese negocio
     * @throws CannotMergeCustomerException si la fusión no se puede hacer
     */
    public function handle(Customer $survivor, Customer $absorbed, User $actor): MergeSummary
    {
        if ((int) $survivor->getKey() === (int) $absorbed->getKey()) {
            throw CannotMergeCustomerException::sameRecord();
        }

        if ((int) $survivor->organization_id !== (int) $absorbed->organization_id) {
            throw CannotMergeCustomerException::otherOrganization();
        }

        Gate::forUser($actor)->authorize('merge', [Customer::class, $survivor, $absorbed]);

        return DB::transaction(function () use ($survivor, $absorbed, $actor): MergeSummary {
            // Lo de la absorbida, **antes** que las fichas: es el orden del
            // resto de la aplicación —un comprobante bloquea su apartado y
            // después toca la ficha; un enlace de acceso, su token y después
            // la ficha—. Al revés, una subida o un clic a mitad de la fusión
            // se bloquearían en cruz con ella.
            Layaway::query()->where('customer_id', $absorbed->getKey())->orderBy('id')->lockForUpdate()->pluck('id');
            CustomerLoginToken::query()->where('customer_id', $absorbed->getKey())->orderBy('id')->lockForUpdate()->pluck('id');

            $locked = Customer::query()
                ->whereKey([$survivor->getKey(), $absorbed->getKey()])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $keeper = $locked->get($survivor->getKey());
            $gone = $locked->get($absorbed->getKey());

            if (! $keeper instanceof Customer || ! $gone instanceof Customer) {
                throw CannotMergeCustomerException::gone();
            }

            if ($keeper->account_id !== null && $gone->account_id !== null && (int) $keeper->account_id !== (int) $gone->account_id) {
                throw CannotMergeCustomerException::bothLinked();
            }

            $summary = $this->preview($keeper, $gone);
            // La ficha absorbida se borra: lo que de ella no se mueve queda aquí.
            $before = ['absorbed' => [
                'id' => (int) $gone->getKey(),
                'full_name' => $gone->full_name,
                'document_number' => $gone->document_number,
                'phone' => $gone->phone,
                'email' => $gone->email,
                'terms_accepted_at' => $gone->terms_accepted_at?->toIso8601String(),
                'created_at' => $gone->created_at?->toIso8601String(),
            ]];

            Layaway::query()->where('customer_id', $gone->getKey())->update(['customer_id' => $keeper->getKey()]);
            Receipt::query()->where('customer_id', $gone->getKey())->update(['customer_id' => $keeper->getKey()]);
            CustomerLoginToken::query()->where('customer_id', $gone->getKey())->update(['customer_id' => $keeper->getKey()]);

            if ($summary->accountMoves) {
                // El único `(organization_id, account_id)` no admite las dos a
                // la vez: primero se suelta, después se ata.
                $account = $gone->account_id;
                $gone->forceFill(['account_id' => null])->save();
                $keeper->forceFill(['account_id' => $account])->save();
            }

            CustomerMerge::query()->where('survivor_customer_id', $gone->getKey())->update(['survivor_customer_id' => $keeper->getKey()]);

            $merge = new CustomerMerge;
            $merge->forceFill([
                'absorbed_customer_id' => $gone->getKey(),
                'absorbed_document_number' => $gone->document_number,
                // Su cookie de «recordarme» sigue llevando a la superviviente.
                'absorbed_remember_token' => $gone->getRememberToken(),
                'survivor_customer_id' => $keeper->getKey(),
                'merged_by' => $actor->getKey(),
            ])->save();

            $gone->delete();

            $this->audit(
                AuditEvent::CustomerMerged,
                subject: $keeper,
                actor: $actor,
                before: $before,
                after: ['moved' => $summary->toArray()],
            );

            return $summary;
        }, self::ATTEMPTS);
    }

    /**
     * Intentos ante un interbloqueo que el orden de bloqueo no evite —la
     * clave ajena de una inserción concurrente—: la transacción entera se
     * repite, y con ella las comprobaciones.
     */
    private const ATTEMPTS = 3;
}
