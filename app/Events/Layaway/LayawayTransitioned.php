<?php

declare(strict_types=1);

namespace App\Events\Layaway;

use App\Domain\Layaway\LayawayStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un apartado cambió de estado (`REQ-LAY-005` RB-7).
 *
 * Lo emite **solo** `LayawayStateMachine::transition()`, uno por transición,
 * y alimenta notificaciones (`T-082`) y broadcast. Cada transición tiene su
 * clase para que un listener se suscriba a lo que le importa —«completado»—
 * sin filtrar por estados. La base fija la forma común de los seis y su
 * despacho después del commit; **no** sirve para escucharlos todos a la vez,
 * porque el despachador de Laravel resuelve listeners por clase concreta e
 * interfaz, no por clase padre.
 *
 * ## Después del commit, siempre
 *
 * `ShouldDispatchAfterCommit`: el evento no se despacha hasta que la
 * transacción de la transición confirma, y si se revierte **no se despacha
 * nunca**. Así no se notifica un `completed` que no llegó a existir, y un
 * listener que falla no puede deshacer el estado (SP-6): cuando corre, el
 * estado ya está escrito. Los listeners de notificación además irán
 * encolados (`REQ-NOT-003`), así que su fallo ni siquiera llega a quien
 * transicionó.
 *
 * ## Lleva identificadores, no el modelo
 *
 * La organización viaja con el evento, igual que en los medios de `T-029`: un
 * listener encolado se ejecuta en un worker sin tenant, y lo que decide a qué
 * negocio pertenece el apartado tiene que ir con el dato y no depender de
 * quién lo lea. Con un modelo serializado, el worker tendría que rehidratarlo
 * a través del global scope sin organización fijada.
 */
abstract class LayawayTransitioned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $layawayId,
        public readonly int $organizationId,
        public readonly LayawayStatus $from,
        public readonly LayawayStatus $to,
        public readonly CarbonImmutable $occurredAt,
    ) {}
}
