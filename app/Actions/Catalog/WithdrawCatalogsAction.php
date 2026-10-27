<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\UsageCalculator;
use App\Domain\Subscription\UsageMetric;
use App\Models\Catalog;
use Illuminate\Support\Facades\DB;
use LogicException;

final class WithdrawCatalogsAction
{
    public function __construct(
        private readonly LimitEnforcer $limits,
        private readonly UsageCalculator $usage,
    ) {}

    /**
     * @return list<string>
     *
     * @throws LogicException
     */
    public function handle(): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Retirar los catálogos va dentro de la transacción que suspende o cierra.');
        }

        $this->limits->lockCounter(UsageMetric::Catalogs);

        $published = Catalog::query()
            ->whereNotNull('published_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'slug']);

        if ($published->isNotEmpty()) {
            Catalog::query()->whereKey($published->modelKeys())->update(['published_at' => null, 'withdrawn_at' => now()]);
        }

        $this->usage->recountLocked(UsageMetric::Catalogs);

        return $published->pluck('slug')->map(fn (mixed $slug): string => (string) $slug)->values()->all();
    }
}
