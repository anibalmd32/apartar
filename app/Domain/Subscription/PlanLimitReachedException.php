<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use App\Models\Plan;
use RuntimeException;

final class PlanLimitReachedException extends RuntimeException
{
    public const STATUS = 422;

    private function __construct(
        public readonly UsageMetric $metric,
        public readonly int $used,
        public readonly int $limit,
        public readonly ?Plan $minimumPlan,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function of(
        UsageMetric $metric,
        int $used,
        int $limit,
        int $amount,
        Plan $current,
        ?Plan $minimum,
    ): self {
        return new self($metric, $used, $limit, $minimum, self::messageFor($metric, $limit, $amount, $current, $minimum));
    }

    private static function messageFor(
        UsageMetric $metric,
        int $limit,
        int $amount,
        Plan $current,
        ?Plan $minimum,
    ): string {
        $reached = sprintf(
            'Tu plan %s permite %d %s%s.',
            $current->name,
            $limit,
            $metric->label($limit),
            $amount > 1 ? sprintf(', y estás pidiendo %d más', $amount) : '',
        );

        if (! $minimum instanceof Plan) {
            return $reached.' No hay ningún plan con un techo mayor.';
        }

        $ceiling = $minimum->limitFor($metric);

        return $reached.sprintf(
            ' %s permite %s.',
            $minimum->name,
            $ceiling === null ? 'sin límite' : $ceiling.' '.$metric->label($ceiling),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'metric' => $this->metric->value,
            'used' => $this->used,
            'limit' => $this->limit,
            'minimumPlan' => $this->minimumPlan?->code,
            'minimumPlanName' => $this->minimumPlan?->name,
            'suggestion' => $this->metric->freeUpHint(),
        ];
    }
}
