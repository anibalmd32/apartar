<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\RateSource;
use App\Domain\Tenancy\TenantContext;
use App\Rules\UsableRateSource;

final readonly class RateSourceChoices
{
    public function __construct(
        private ExchangeRateService $rates,
        private TenantContext $context,
    ) {}

    /**
     * @return array{options: list<array{value: string, label: string}>, businessSource: string, hasManualRate: bool}
     */
    public function for(string $inheritLabel): array
    {
        $default = $this->rates->sourceFor($this->context->organization());

        return [
            'options' => [
                ['value' => '', 'label' => sprintf($inheritLabel, $default->label())],
                ...array_map(fn (RateSource $source): array => ['value' => $source->value, 'label' => $source->label()], RateSource::cases()),
            ],
            'businessSource' => $default->value,
            'hasManualRate' => UsableRateSource::hasManualRate(),
        ];
    }
}
