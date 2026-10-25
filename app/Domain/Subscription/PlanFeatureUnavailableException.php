<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use App\Models\Plan;
use RuntimeException;

final class PlanFeatureUnavailableException extends RuntimeException
{
    public const STATUS = 403;

    private function __construct(
        public readonly PlanFeature $feature,
        public readonly ?Plan $minimumPlan,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function of(PlanFeature $feature, Plan $current, ?Plan $minimum): self
    {
        $label = $feature->label();
        $message = sprintf('Tu plan %s no incluye: %s.', $current->name, mb_strtolower(mb_substr($label, 0, 1)).mb_substr($label, 1));

        $message .= $minimum instanceof Plan
            ? sprintf(' %s sí la incluye.', $minimum->name)
            : ' Ningún plan la incluye todavía.';

        return new self($feature, $minimum, $message);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'feature' => $this->feature->value,
            'minimumPlan' => $this->minimumPlan?->code,
            'minimumPlanName' => $this->minimumPlan?->name,
        ];
    }
}
