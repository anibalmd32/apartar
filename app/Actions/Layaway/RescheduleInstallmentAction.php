<?php

declare(strict_types=1);

namespace App\Actions\Layaway;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Layaway\InvalidRescheduleException;
use App\Domain\Layaway\LayawayBalanceRecalculator;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\StaffReason;
use App\Domain\Tenancy\TenantContext;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\ModelState;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * El negocio mueve la fecha de una cuota (`REQ-LAY-011`).
 *
 * ## Solo la fecha
 *
 * `due_date` y nada más (RB-1): el importe, el número y el apartado de una
 * cuota son inmutables, y `Installment` lanza si alguien los toca. No hay
 * reprogramación masiva (RB-7): una cuota por llamada.
 *
 * ## Bajo el bloqueo del apartado
 *
 * El apartado y **todas** sus cuotas se cogen antes de validar, porque la
 * ventana depende de las vecinas: dos reprogramaciones de cuotas contiguas a
 * la vez se serializan, y la segunda se valida contra la fecha que dejó la
 * primera (SP-5). No hay stock de por medio, así que el orden canónico del
 * producto no aplica.
 *
 * ## Después de mover
 *
 *  - Los estados de las cuotas y del apartado (RB-5), con la misma regla que
 *    la mora (`DetectOverdueAction::refresh()`): una cuota vencida que se
 *    mueve al futuro sale de `overdue`, y si no queda ninguna el apartado
 *    vuelve a `active`.
 *  - `next_due_date`, que es el vencimiento de la primera cuota sin cubrir
 *    (RB-5). Lo escribe `LayawayBalanceRecalculator` (`REQ-PAY-008` RB-7), al
 *    que se llama **después** de la mora para que vea las cuotas ya con su
 *    estado.
 *  - La auditoría, con la fecha de antes, la de después y el motivo (RB-4).
 *    No se avisa al cliente (RB-6).
 *
 * Las fechas son días del calendario del negocio (RB-8): «hoy» es el de su
 * zona.
 */
final class RescheduleInstallmentAction
{
    use LogsSensitiveActivity;

    public function __construct(
        private readonly TenantContext $context,
        private readonly DetectOverdueAction $overdue,
        private readonly LayawayBalanceRecalculator $balance,
    ) {}

    /**
     * @throws AuthorizationException si quien reprograma no gestiona la cobranza del negocio
     * @throws InvalidArgumentException si el motivo no tiene entre 10 y 500 caracteres (RB-4)
     * @throws InvalidRescheduleException si la cuota está pagada, el apartado no se cobra o la fecha cae fuera de la ventana
     */
    public function handle(Installment $installment, CarbonImmutable $newDueDate, User $actor, string $reason): Installment
    {
        /** @var Layaway $parent */
        $parent = $installment->layaway()->firstOrFail();
        Gate::forUser($actor)->authorize('manage', $parent);

        $reason = new StaffReason($reason);

        return DB::transaction(function () use ($installment, $newDueDate, $actor, $reason): Installment {
            /** @var Layaway $layaway */
            $layaway = Layaway::query()->whereKey($installment->layaway_id)->lockForUpdate()->firstOrFail();

            if (! in_array($layaway->status, [LayawayStatus::Active, LayawayStatus::Overdue], true)) {
                throw InvalidRescheduleException::layawayNotCollecting($layaway->status);
            }

            $installments = $layaway->installments()->lockForUpdate()->get()->all();
            $target = $this->find($installments, $installment->number);

            if ($target->paid_cents->cents >= $target->amount_cents->cents) {
                throw InvalidRescheduleException::alreadyPaid($target->number);
            }

            $timezone = new DateTimeZone($this->context->organization()->timezone);
            $today = CarbonImmutable::now($timezone)->startOfDay();
            $window = Installment::scheduleOf($installments, $timezone)->validMoveWindow($target->number, $today);
            $newDay = CarbonImmutable::parse($newDueDate->toDateString(), $timezone)->startOfDay();

            if (! $window->contains($newDay)) {
                throw InvalidRescheduleException::outsideWindow($target->number, $window);
            }

            $before = $target->due_date->toDateString();
            $target->forceFill(['due_date' => $newDay->toDateString()])->save();

            $this->audit(
                AuditEvent::InstallmentRescheduled,
                subject: $layaway,
                actor: $actor,
                // La clave nombra la cuota: lo que no cambia no queda en el diff.
                before: ["installments.{$target->number}.due_date" => $before],
                after: ["installments.{$target->number}.due_date" => $newDay->toDateString(), 'reason' => $reason->text],
            );

            $this->overdue->refresh($layaway, CarbonImmutable::now());

            // RB-5 · `next_due_date` con la fecha nueva, cuando las cuotas ya
            // tienen su estado de mora (`REQ-PAY-008` RB-7).
            $this->balance->recalculate($layaway);

            ModelState::adopt($installment, $target->fresh()?->getAttributes() ?? $target->getAttributes());

            return $installment;
        });
    }

    /**
     * @param  list<Installment>  $installments
     */
    private function find(array $installments, int $number): Installment
    {
        foreach ($installments as $candidate) {
            if ($candidate->number === $number) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException(sprintf('El apartado no tiene cuota %d.', $number));
    }
}
