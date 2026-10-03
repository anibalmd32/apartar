<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Entre qué días puede caer la nueva fecha de una cuota (`REQ-LAY-011` RB-3,
 * RB-8).
 *
 * Desde el día siguiente a hoy —la fecha tiene que ser futura (SP-2)— y nunca
 * antes que la cuota anterior; hasta la siguiente, o sin tope si es la última
 * (SP-4). Los dos bordes **cuentan**: «no anterior» y «no posterior» admiten
 * el mismo día que la vecina, que deja el cronograma monótono aunque no
 * estrictamente creciente.
 *
 * Son días del calendario del negocio, sin hora: se comparan como `Y-m-d`.
 */
final readonly class RescheduleWindow
{
    public function __construct(public CarbonImmutable $earliest, public ?CarbonImmutable $latest) {}

    public function contains(CarbonInterface $day): bool
    {
        $date = $day->toDateString();

        return $date >= $this->earliest->toDateString()
            && ($this->latest === null || $date <= $this->latest->toDateString());
    }

    /**
     * Sin ningún día posible: la cuota siguiente vence hoy o antes.
     */
    public function isEmpty(): bool
    {
        return $this->latest !== null && $this->latest->toDateString() < $this->earliest->toDateString();
    }

    /**
     * El rango, como se le dice a quien reprograma (SP-1).
     */
    public function describe(): string
    {
        $from = $this->earliest->locale('es')->isoFormat('D [de] MMMM [de] YYYY');

        return $this->latest === null
            ? sprintf('desde el %s, sin tope', $from)
            : sprintf('entre el %s y el %s', $from, $this->latest->locale('es')->isoFormat('D [de] MMMM [de] YYYY'));
    }
}
