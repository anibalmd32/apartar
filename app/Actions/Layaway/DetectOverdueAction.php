<?php

declare(strict_types=1);

namespace App\Actions\Layaway;

use App\Domain\Layaway\InstallmentStatus;
use App\Domain\Layaway\LayawayStateMachine;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\OverdueSummary;
use App\Domain\Tenancy\TenantContext;
use App\Models\Installment;
use App\Models\Layaway;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class DetectOverdueAction
{
    private const CHUNK = 100;

    public function __construct(
        private readonly TenantContext $context,
        private readonly Settings $settings,
        private readonly LayawayStateMachine $machine,
    ) {}

    public function handle(CarbonImmutable $now): OverdueSummary
    {
        $threshold = $this->threshold($now);
        $summary = new OverdueSummary;

        Layaway::query()
            ->whereIn('status', [LayawayStatus::Active, LayawayStatus::Overdue])
            ->where(function (Builder $query) use ($threshold): void {
                $query->where('status', LayawayStatus::Overdue)
                    ->orWhereHas('installments', fn (Builder $installments) => $installments->unpaidDueBefore($threshold))
                    ->orWhereHas('installments', fn (Builder $installments) => $installments->overdue());
            })
            ->chunkById(self::CHUNK, function (Collection $layaways) use ($threshold, &$summary): void {
                foreach ($layaways as $layaway) {
                    $summary = $this->recalculate($layaway, $threshold, $summary);
                }
            });

        return $summary;
    }

    private function threshold(CarbonImmutable $now): string
    {
        $today = $now->setTimezone(new DateTimeZone($this->context->organization()->timezone))->startOfDay();

        return $today->subDays($this->graceDays())->toDateString();
    }

    public function isPastGrace(Installment $installment, CarbonImmutable $now): bool
    {
        return $installment->due_date->toDateString() < $this->threshold($now);
    }

    public function refresh(Layaway $layaway, CarbonImmutable $now): OverdueSummary
    {
        return $this->recalculate($layaway, $this->threshold($now), new OverdueSummary);
    }

    private function recalculate(Layaway $layaway, string $threshold, OverdueSummary $summary): OverdueSummary
    {
        return DB::transaction(function () use ($layaway, $threshold, $summary): OverdueSummary {
            $locked = Layaway::query()->whereKey($layaway->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Layaway || ! in_array($locked->status, [LayawayStatus::Active, LayawayStatus::Overdue], true)) {
                return $summary;
            }

            $hasOverdue = false;

            foreach ($locked->installments()->lockForUpdate()->get() as $installment) {
                $target = InstallmentStatus::resolve(
                    $installment->amount_cents,
                    $installment->paid_cents,
                    pastGrace: $installment->due_date->toDateString() < $threshold,
                );

                $hasOverdue = $hasOverdue || $target === InstallmentStatus::Overdue;

                if ($target !== $installment->status) {
                    $summary = $this->move($installment, $target, $summary);
                }
            }

            if ($hasOverdue && $locked->status === LayawayStatus::Active) {
                $this->machine->transition($locked, LayawayStatus::Overdue);

                return $summary->withLayawayBecameOverdue();
            }

            if (! $hasOverdue && $locked->status === LayawayStatus::Overdue) {
                $this->machine->transition($locked, LayawayStatus::Active);

                return $summary->withLayawayCaughtUp();
            }

            return $summary;
        });
    }

    private function move(Installment $installment, InstallmentStatus $target, OverdueSummary $summary): OverdueSummary
    {
        $wasOverdue = $installment->status === InstallmentStatus::Overdue;

        $installment->forceFill(['status' => $target])->save();

        return match (true) {
            $target === InstallmentStatus::Overdue => $summary->withInstallmentMarkedOverdue(),
            $wasOverdue => $summary->withInstallmentReturned(),
            default => $summary,
        };
    }

    private function graceDays(): int
    {
        return $this->settings->graceDays();
    }
}
