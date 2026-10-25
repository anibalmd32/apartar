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

final class RescheduleInstallmentAction
{
    use LogsSensitiveActivity;

    public function __construct(
        private readonly TenantContext $context,
        private readonly DetectOverdueAction $overdue,
        private readonly LayawayBalanceRecalculator $balance,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     * @throws InvalidRescheduleException
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
                before: ["installments.{$target->number}.due_date" => $before],
                after: ["installments.{$target->number}.due_date" => $newDay->toDateString(), 'reason' => $reason->text],
            );

            $this->overdue->refresh($layaway, CarbonImmutable::now());

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
