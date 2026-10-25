<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

interface ExchangeRateProvider
{
    /**
     * @throws ExchangeRateUnavailableException
     */
    public function fetch(RateSource $source): RateSnapshot;

    public function supports(RateSource $source): bool;
}
