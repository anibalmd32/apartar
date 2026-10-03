<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Layaway\CancelLayawayAction;
use App\Actions\Layaway\MarkDeliveredAction;
use App\Domain\Layaway\IllegalLayawayTransitionException;
use App\Domain\Layaway\LayawayNotDeliverableException;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\CancelLayawayRequest;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Un apartado visto por el negocio, y lo que el negocio puede hacer con él
 * (`REQ-LAY-009`, `REQ-LAY-010`, `REQ-LAY-011`).
 *
 * Es la vista **mínima** que necesitan los tres diálogos de `T-053`: la ficha
 * del apartado, su cronograma y los botones de cancelar, entregar y
 * reprogramar. Los listados de cobranza que llevan hasta aquí, la ficha de
 * cliente y el historial de pagos son de `T-065` y `T-061`.
 *
 * El `{layaway}` viaja por su código y se resuelve ya filtrado por el tenant:
 * el de otro negocio da `404`, no `403` (`REQ-ORG-002` RB-6, `REQ-LAY-009`
 * SP-7). Ninguna de estas rutas se cierra con la organización suspendida
 * (`REQ-LAY-009` RB-9): es la gestión de la cobranza que `REQ-SUB-007` RB-4
 * preserva.
 */
final class LayawayController extends Controller
{
    use ProjectsMoney;

    public function __construct(private readonly TenantContext $context) {}

    public function show(Request $request, Layaway $layaway): Response
    {
        Gate::forUser($request->user())->authorize('manage', $layaway);

        $timezone = new DateTimeZone($this->context->organization()->timezone);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $installments = $layaway->installments()->get()->all();
        $schedule = Installment::scheduleOf($installments, $timezone);
        $collecting = in_array($layaway->status, [LayawayStatus::Active, LayawayStatus::Overdue], true);
        $customer = Customer::query()->find($layaway->customer_id);

        return Inertia::render('backoffice/layaways/Show', [
            'layaway' => [
                'code' => $layaway->code,
                'status' => $layaway->status->value,
                'statusLabel' => $layaway->status->label(),
                'productName' => $layaway->product_name_snapshot,
                'quantity' => $layaway->quantity,
                'total' => $this->money($layaway->total_cents),
                'downPayment' => $this->money($layaway->terms()->totalDownPayment()),
                'paid' => $this->money($layaway->paid_cents),
                'remaining' => $this->money($layaway->remaining_cents),
                'createdAt' => $this->moment($layaway->created_at, $timezone),
                'cancelledAt' => $this->moment($layaway->cancelled_at, $timezone),
                'deliveredAt' => $this->moment($layaway->delivered_at, $timezone),
                // `REQ-LAY-009` RB-8 · íntegro al negocio, nunca al cliente.
                'cancellationReason' => $layaway->cancellation_reason,
                'cancelUrl' => $layaway->status->canTransitionTo(LayawayStatus::Cancelled) ? route('layaways.cancel', $layaway) : null,
                'deliverUrl' => $layaway->status === LayawayStatus::Completed && $layaway->delivered_at === null ? route('layaways.deliver', $layaway) : null,
            ],
            'customer' => $customer instanceof Customer ? [
                'fullName' => $customer->full_name,
                'documentNumber' => $customer->document_number,
                'phone' => $customer->phone,
            ] : null,
            'installments' => array_map(function (Installment $installment) use ($collecting, $schedule, $today): array {
                $covered = $installment->paid_cents->cents >= $installment->amount_cents->cents;
                $window = $collecting && ! $covered ? $schedule->validMoveWindow($installment->number, $today) : null;
                $movable = $window !== null && ! $window->isEmpty();

                return [
                    'number' => $installment->number,
                    'dueDate' => $installment->due_date->toDateString(),
                    'dueDateLabel' => $installment->due_date->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
                    'amount' => $this->money($installment->amount_cents),
                    'paid' => $this->money($installment->paid_cents),
                    'statusLabel' => $installment->status->label(),
                    // `REQ-LAY-011` RB-3 · la ventana, para que el diálogo no ofrezca días imposibles.
                    'reschedule' => $movable ? [
                        'url' => route('installments.reschedule', $installment),
                        'earliest' => $window->earliest->toDateString(),
                        'latest' => $window->latest?->toDateString(),
                        'window' => $window->describe(),
                    ] : null,
                ];
            }, $installments),
        ]);
    }

    /**
     * `REQ-LAY-009` · con el estado comprobado bajo el bloqueo: un terminal
     * responde `422` (SP-1), igual que una aprobación concurrente que lo
     * completó primero (SP-4).
     */
    public function cancel(CancelLayawayRequest $request, Layaway $layaway, CancelLayawayAction $cancel): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $cancel->handle($layaway, $actor, $request->reason());
        } catch (IllegalLayawayTransitionException $exception) {
            throw ValidationException::withMessages([
                'reason' => sprintf('Este apartado está %s y ya no se puede cancelar.', mb_strtolower($exception->from->label())),
            ]);
        }

        return to_route('layaways.show', $layaway)->with('status', 'Apartado cancelado. El producto volvió al inventario.');
    }

    /**
     * `REQ-LAY-010` · idempotente (SP-2): la segunda pulsación también es un
     * éxito.
     */
    public function deliver(Request $request, Layaway $layaway, MarkDeliveredAction $deliver): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $deliver->handle($layaway, $actor);
        } catch (LayawayNotDeliverableException $exception) {
            throw ValidationException::withMessages(['delivery' => $exception->getMessage()]);
        }

        return to_route('layaways.show', $layaway)->with('status', 'Entrega registrada.');
    }

    private function moment(?DateTimeInterface $at, DateTimeZone $timezone): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->setTimezone($timezone)->locale('es')->isoFormat('D [de] MMMM [de] YYYY, h:mm a');
    }
}
