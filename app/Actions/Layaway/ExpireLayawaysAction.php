<?php

declare(strict_types=1);

namespace App\Actions\Layaway;

use App\Domain\Layaway\ExpirationOutcome;
use App\Domain\Layaway\LayawayStateMachine;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Payment\LayawayReceipts;
use App\Models\Layaway;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class ExpireLayawaysAction
{
    private const CHUNK = 100;

    public function __construct(
        private readonly LayawayStateMachine $machine,
        private readonly ReleaseStockAction $stock,
        private readonly LayawayReceipts $receipts,
    ) {}

    public function handle(CarbonImmutable $now): ExpirationOutcome
    {
        $outcome = new ExpirationOutcome;

        Layaway::query()
            ->where('status', LayawayStatus::PendingDownPayment)
            ->where('expires_at', '<', $now)
            ->chunkById(self::CHUNK, function (Collection $layaways) use ($now, &$outcome): void {
                foreach ($layaways as $layaway) {
                    $outcome = $this->expire($layaway, $now, $outcome);
                }
            });

        return $outcome;
    }

    private function expire(Layaway $layaway, CarbonImmutable $now, ExpirationOutcome $outcome): ExpirationOutcome
    {
        return DB::transaction(function () use ($layaway, $now, $outcome): ExpirationOutcome {
            $this->stock->lockProductOf($layaway);

            $locked = Layaway::query()->whereKey($layaway->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Layaway || ! $this->stillExpirable($locked, $now)) {
                return $outcome;
            }

            if ($this->receipts->holdExpiration($locked)) {
                return $outcome->withHeld();
            }

            $this->machine->transition($locked, LayawayStatus::Expired);

            return $outcome->withExpired();
        });
    }

    private function stillExpirable(Layaway $layaway, CarbonImmutable $now): bool
    {
        return $layaway->status === LayawayStatus::PendingDownPayment
            && $layaway->expires_at !== null
            && $layaway->expires_at->lessThan($now);
    }
}
