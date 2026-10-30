<?php

declare(strict_types=1);

namespace App\Actions\Layaway;

use App\Domain\Layaway\AmortizationSchedule;
use App\Domain\Layaway\IllegalLayawayTransitionException;
use App\Domain\Layaway\LayawayBalanceRecalculator;
use App\Domain\Layaway\LayawayStateMachine;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\ScheduleAlreadyMaterializedException;
use App\Domain\Layaway\ScheduledInstallment;
use App\Domain\Layaway\ScheduleGenerator;
use App\Domain\Money\Money;
use App\Domain\Tenancy\TenantContext;
use App\Models\Installment;
use App\Models\Layaway;
use App\Support\ModelState;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ActivateLayawayAction
{
    private const SCHEDULE_INDEX = 'installments_organization_id_layaway_id_number_unique';

    public function __construct(
        private readonly TenantContext $context,
        private readonly LayawayStateMachine $machine,
        private readonly ScheduleGenerator $generator,
        private readonly LayawayBalanceRecalculator $balance,
    ) {}

    /**
     * @throws IllegalLayawayTransitionException
     * @throws ScheduleAlreadyMaterializedException
     */
    public function handle(Layaway $layaway, int $shortfallCents = 0): AmortizationSchedule
    {
        if ($shortfallCents < 0 || $shortfallCents > $layaway->terms()->totalDownPayment()->cents) {
            throw new LogicException(sprintf(
                'Lo que le falta a la inicial al activar va de 0 a la inicial completa, y es de %d céntimos.',
                $shortfallCents,
            ));
        }

        try {
            return DB::transaction(function () use ($layaway, $shortfallCents): AmortizationSchedule {
                /** @var Layaway $locked */
                $locked = Layaway::query()->whereKey($layaway->getKey())->lockForUpdate()->firstOrFail();

                if ($shortfallCents > 0) {
                    $locked->forceFill(['down_payment_shortfall_cents' => new Money($shortfallCents)])->save();
                }

                $this->machine->transition($locked, LayawayStatus::Active);

                $schedule = $this->generator->generate($locked->terms(), $this->activatedAt($locked), $this->timezone());

                foreach ($schedule->installments as $scheduled) {
                    $this->insert($locked, $scheduled);
                }

                $this->assertScheduleAddsUp($locked);

                $this->balance->recalculate($locked);

                $fresh = $locked->fresh() ?? throw new LogicException('El apartado desapareció a mitad de su activación.');
                ModelState::adopt($layaway, $fresh->getAttributes());

                return $schedule;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), self::SCHEDULE_INDEX)) {
                throw $exception;
            }

            throw new ScheduleAlreadyMaterializedException($layaway->getKey(), $exception);
        }
    }

    private function insert(Layaway $layaway, ScheduledInstallment $scheduled): void
    {
        $installment = new Installment;

        $installment->forceFill([
            'layaway_id' => $layaway->getKey(),
            'number' => $scheduled->number,
            'due_date' => $scheduled->dueDateString(),
            'amount_cents' => $scheduled->amount,
        ])->save();
    }

    private function assertScheduleAddsUp(Layaway $layaway): void
    {
        $stored = (int) Installment::query()->where('layaway_id', $layaway->getKey())->sum('amount_cents');
        $financed = $layaway->total_cents->cents - $layaway->terms()->totalDownPayment()->cents;

        if ($stored !== $financed) {
            throw new LogicException(sprintf(
                'Las cuotas del apartado %s suman %d céntimos en la base y lo financiado es %d (total %d menos la inicial). '
                .'sum(installments.amount_cents) === total − inicial es una invariante dura: no se activa nada.',
                $layaway->getKey(),
                $stored,
                $financed,
                $layaway->total_cents->cents,
            ));
        }
    }

    private function activatedAt(Layaway $layaway): CarbonImmutable
    {
        $activatedAt = $layaway->activated_at
            ?? throw new LogicException('La máquina de estados activó el apartado sin escribir «activated_at».');

        return CarbonImmutable::instance($activatedAt);
    }

    private function timezone(): DateTimeZone
    {
        return new DateTimeZone($this->context->organization()->timezone);
    }
}
