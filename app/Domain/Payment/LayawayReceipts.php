<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Models\Layaway;
use App\Models\Payment;
use App\Models\Receipt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lo que los comprobantes de un apartado deciden fuera del módulo de cobranza.
 *
 * Todas las preguntas van por el global scope del tenant (`Receipt` lleva
 * `BelongsToOrganization`): se hacen con la organización del apartado fijada,
 * y un comprobante de otro negocio no existe para ellas.
 *
 * ## Si congelan su expiración (`REQ-LAY-006` RB-1, RB-2)
 *
 * Un apartado en `pending_down_payment` con la ventana vencida no expira si
 * tiene un comprobante en revisión o aprobado: el cliente pagó a tiempo, y la
 * demora es del negocio. Castigarle por ella —devolverle el producto a la
 * venta con su dinero ya enviado— es exactamente lo que RB-2 prohíbe.
 *
 * ## Que se rechazan al cancelar (`REQ-LAY-009` SP-3)
 *
 * Un apartado cancelado no acepta pagos, así que sus comprobantes en revisión
 * se rechazan en la misma transacción, con el código `layaway_cancelled`.
 *
 * ## Qué alertas dan en el portal (`REQ-LAY-023`)
 *
 * Cuántos esperan revisión —`receipt_pending`, y lo que sustituye al aviso de
 * la ventana de la inicial cuando ya hay uno en camino (SP-3)— y qué apartados
 * tienen su último comprobante rechazado —`receipt_rejected`—.
 *
 * Los cuerpos los escribió `T-054`, que creó la tabla; antes respondían
 * «ninguno» y lanzaban en cuanto existiera. Sigue sin ser `final` porque los
 * tests de la expiración, las alertas y la cancelación la sustituyen en el
 * contenedor para probar cada caso sin sembrar archivos.
 */
class LayawayReceipts
{
    /**
     * Indica si el apartado tiene algún comprobante en revisión o aprobado.
     */
    public function holdExpiration(Layaway $layaway): bool
    {
        return Receipt::query()
            ->where('layaway_id', $layaway->getKey())
            ->whereIn('status', ReceiptStatus::holdingExpiration())
            ->exists();
    }

    /**
     * Cuántos comprobantes en revisión tiene cada uno de esos apartados, por
     * `id`; los que no tienen ninguno no aparecen.
     *
     * Son los apartados de **un** negocio, y se consulta con su tenant fijado:
     * una consulta para todos, no una por apartado.
     *
     * @param  list<Layaway>  $layaways
     * @return array<int, int>
     */
    public function pendingReviewCounts(array $layaways): array
    {
        if ($layaways === []) {
            return [];
        }

        $counts = [];

        Receipt::query()
            ->whereIn('layaway_id', self::idsOf($layaways))
            ->where('status', ReceiptStatus::PendingReview)
            ->groupBy('layaway_id')
            ->selectRaw('layaway_id, COUNT(*) AS pending')
            ->toBase()
            ->get()
            ->each(function (object $row) use (&$counts): void {
                /** @var object{layaway_id: int|string, pending: int|string} $row */
                $counts[(int) $row->layaway_id] = (int) $row->pending;
            });

        return $counts;
    }

    /**
     * Los `id` de esos apartados cuyo **último** comprobante fue rechazado.
     *
     * Un rechazo ya contestado deja de ser una alerta: el reintento es más
     * nuevo, y lo que queda es él —en revisión—. Por eso basta mirar el último
     * de cada apartado, sin seguir la cadena de `replaces_receipt_id`.
     *
     * @param  list<Layaway>  $layaways
     * @return list<int>
     */
    public function rejectedAwaitingRetry(array $layaways): array
    {
        if ($layaways === []) {
            return [];
        }

        $latest = Receipt::query()
            ->whereIn('layaway_id', self::idsOf($layaways))
            ->groupBy('layaway_id')
            ->selectRaw('MAX(id)');

        return Receipt::query()
            ->whereIn('id', $latest)
            ->where('status', ReceiptStatus::Rejected)
            // Desde `T-062`: un rechazo que el negocio ya resolvió registrando
            // el pago a mano (`REQ-PAY-006` SP-3) no espera ningún reintento.
            ->whereNotExists(fn ($payments) => $payments
                ->selectRaw('1')
                ->from('payments')
                ->whereColumn('payments.layaway_id', 'receipts.layaway_id')
                ->whereColumn('payments.created_at', '>=', DB::raw('COALESCE(receipts.reviewed_at, receipts.updated_at)')))
            ->pluck('layaway_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Rechaza los comprobantes en revisión del apartado que se está cancelando,
     * con el código `layaway_cancelled`, y dice cuántos (`REQ-LAY-009` SP-3).
     *
     * Corre dentro de la transacción de la cancelación, con el apartado ya
     * bloqueado: o se cancela y se rechazan, o ninguna de las dos cosas. Así no
     * quedan comprobantes huérfanos en la bandeja de un apartado que ya no
     * acepta pagos. Los coge con `FOR UPDATE`, para que una aprobación que
     * llegue a la vez espere y después los encuentre rechazados.
     *
     * Sin revisor: lo rechaza el sistema, no una persona. El aviso al cliente
     * es de `T-083`.
     */
    /**
     * El comprobante rechazado al que sustituiría uno nuevo de ese apartado
     * (`REQ-PAY-006` RB-4), o `null`.
     *
     * Es el **último** comprobante del apartado, si se rechazó y **nada lo ha
     * resuelto después**: ni otro comprobante —sería más nuevo— ni un pago
     * que el negocio registró tras el rechazo (SP-3: el rechazo por error se
     * resuelve con un pago manual). Sin esa segunda condición, el comprobante
     * de la cuota siguiente, semanas después, saldría como «reintento» de uno
     * que ya estaba cerrado.
     *
     * Una sola regla para quien encadena (`SubmitReceiptAction`, bajo el
     * bloqueo del apartado) y para quien lo anuncia (el formulario del
     * portal), y la misma que `rejectedAwaitingRetry()` aplica a las alertas.
     */
    public function rejectedToReplace(Layaway $layaway): ?Receipt
    {
        $latest = Receipt::query()->where('layaway_id', $layaway->getKey())->latest('id')->first();

        if (! $latest instanceof Receipt || $latest->status !== ReceiptStatus::Rejected) {
            return null;
        }

        $resolvedAt = $latest->reviewed_at ?? $latest->updated_at;
        $settledSince = Payment::query()
            ->where('layaway_id', $layaway->getKey())
            ->when($resolvedAt !== null, fn ($query) => $query->where('created_at', '>=', $resolvedAt))
            ->exists();

        return $settledSince ? null : $latest;
    }

    public function rejectPendingOnCancellation(Layaway $layaway): int
    {
        $pending = Receipt::query()
            ->where('layaway_id', $layaway->getKey())
            ->where('status', ReceiptStatus::PendingReview)
            ->lockForUpdate()
            ->get();

        $now = CarbonImmutable::now();

        foreach ($pending as $receipt) {
            $receipt->forceFill([
                'status' => ReceiptStatus::Rejected,
                'rejection_reason_code' => RejectionReasonCode::LayawayCancelled,
                'reviewed_by' => null,
                'reviewed_at' => $now,
            ])->save();
        }

        return $pending->count();
    }

    /**
     * @param  list<Layaway>  $layaways
     * @return list<int>
     */
    private static function idsOf(array $layaways): array
    {
        return array_map(fn (Layaway $layaway): int => (int) $layaway->getKey(), $layaways);
    }
}
