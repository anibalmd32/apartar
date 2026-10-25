<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final readonly class RescheduleWindow
{
    public function __construct(public CarbonImmutable $earliest, public ?CarbonImmutable $latest) {}

    public function contains(CarbonInterface $day): bool
    {
        $date = $day->toDateString();

        return $date >= $this->earliest->toDateString()
            && ($this->latest === null || $date <= $this->latest->toDateString());
    }

    public function isEmpty(): bool
    {
        return $this->latest !== null && $this->latest->toDateString() < $this->earliest->toDateString();
    }

    public function describe(): string
    {
        $from = $this->earliest->locale('es')->isoFormat('D [de] MMMM [de] YYYY');

        return $this->latest === null
            ? sprintf('desde el %s, sin tope', $from)
            : sprintf('entre el %s y el %s', $from, $this->latest->locale('es')->isoFormat('D [de] MMMM [de] YYYY'));
    }
}
