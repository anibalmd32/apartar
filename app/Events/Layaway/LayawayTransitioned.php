<?php

declare(strict_types=1);

namespace App\Events\Layaway;

use App\Domain\Layaway\LayawayStatus;
use Carbon\CarbonImmutable;

/**
 * Un apartado cambió de estado (`REQ-LAY-005` RB-7): la interfaz a la que se
 * suscribe quien quiera **todas** las transiciones (`T-046`).
 *
 * Existe porque el despachador de Laravel resuelve los listeners por clase
 * concreta y por interfaz, **nunca por clase padre**. Con solo la base
 * abstracta, las notificaciones (`T-082`) tendrían que escuchar los seis
 * eventos uno a uno, y el séptimo que alguien añadiera no notificaría nada sin
 * que ningún test se pusiera rojo. Suscrito a esto, un evento nuevo queda
 * cubierto por construcción y no por acordarse.
 *
 * Lo implementa `LayawayTransition`, y `LayawayEventsTest` recorre
 * `app/Events/Layaway/` y exige que **todo** evento de ahí la implemente.
 *
 * Lleva métodos y no solo es una marca porque quien la recibe tiene que poder
 * leer el evento con su tipo: una interfaz de PHP 8.3 no declara propiedades,
 * y PHPStan no lee `@property-read` en una interfaz.
 */
interface LayawayTransitioned
{
    public function layawayId(): int;

    /** La organización viaja con el dato: el listener encolado corre sin tenant. */
    public function organizationId(): int;

    public function from(): LayawayStatus;

    public function to(): LayawayStatus;

    public function occurredAt(): CarbonImmutable;
}
