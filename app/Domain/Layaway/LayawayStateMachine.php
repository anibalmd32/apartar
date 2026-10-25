<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use App\Actions\Layaway\ReleaseStockAction;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\UsageMetric;
use App\Events\Layaway\LayawayActivated;
use App\Events\Layaway\LayawayBecameOverdue;
use App\Events\Layaway\LayawayCancelled;
use App\Events\Layaway\LayawayCaughtUp;
use App\Events\Layaway\LayawayCompleted;
use App\Events\Layaway\LayawayExpired;
use App\Events\Layaway\LayawayTransition;
use App\Models\Layaway;
use App\Support\ModelState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

final class LayawayStateMachine
{
    public function __construct(
        private readonly LimitEnforcer $limits,
        private readonly ReleaseStockAction $stock,
    ) {}

    /**
     * @throws IllegalLayawayTransitionException
     */
    public function transition(Layaway $layaway, LayawayStatus $to): void
    {
        DB::transaction(function () use ($layaway, $to): void {
            if ($to->releasesStock()) {
                $this->stock->lockProductOf($layaway);
            }

            /** @var Layaway $locked */
            $locked = Layaway::query()->whereKey($layaway->getKey())->lockForUpdate()->firstOrFail();

            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw new IllegalLayawayTransitionException($from, $to, $locked->getKey());
            }

            $now = CarbonImmutable::now();

            Layaway::query()->whereKey($locked->getKey())->update(['status' => $to->value] + $this->marksFor($from, $to, $now));

            if ($to->releasesStock()) {
                $this->stock->handle($locked);
            }

            $delta = (int) $to->countsTowardPlanLimit() - (int) $from->countsTowardPlanLimit();

            if ($delta !== 0) {
                $this->limits->adjust(UsageMetric::ActiveLayaways, $delta);
            }

            $fresh = $locked->fresh() ?? throw new LogicException('El apartado desapareció a mitad de su transición.');
            ModelState::adopt($layaway, $fresh->getAttributes());

            event($this->eventFor($locked, $from, $to, $now));
        });
    }

    /**
     * @return array<'activated_at'|'completed_at'|'cancelled_at', CarbonImmutable>
     */
    private function marksFor(LayawayStatus $from, LayawayStatus $to, CarbonImmutable $now): array
    {
        return match ($to) {
            LayawayStatus::Active => $from === LayawayStatus::PendingDownPayment ? ['activated_at' => $now] : [],
            LayawayStatus::Completed => ['completed_at' => $now],
            LayawayStatus::Cancelled => ['cancelled_at' => $now],
            LayawayStatus::Overdue, LayawayStatus::Expired => [],
            LayawayStatus::PendingDownPayment => throw new LogicException('Ninguna transición lleva a «pending_down_payment».'),
        };
    }

    private function eventFor(Layaway $layaway, LayawayStatus $from, LayawayStatus $to, CarbonImmutable $now): LayawayTransition
    {
        $arguments = [(int) $layaway->getKey(), (int) $layaway->getAttribute('organization_id'), $from, $to, $now];

        return match ($to) {
            LayawayStatus::Active => $from === LayawayStatus::Overdue
                ? new LayawayCaughtUp(...$arguments)
                : new LayawayActivated(...$arguments),
            LayawayStatus::Overdue => new LayawayBecameOverdue(...$arguments),
            LayawayStatus::Completed => new LayawayCompleted(...$arguments),
            LayawayStatus::Cancelled => new LayawayCancelled(...$arguments),
            LayawayStatus::Expired => new LayawayExpired(...$arguments),
            LayawayStatus::PendingDownPayment => throw new LogicException('Ninguna transición lleva a «pending_down_payment».'),
        };
    }
}
