<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;

/**
 * Un `usage_counter.below_zero` en la suite **es una deriva real**, y la suite
 * falla por él (`T-045`).
 *
 * Hasta `T-045`, los apartados de test se sembraban sin subir el contador
 * `active_layaways`, así que cada liberación registraba la anomalía y ese
 * aviso era ruido esperado. Desde que `CreatesLayaways` sube el contador igual
 * que `CreateLayawayAction`, ya no hay ruido que esperar: si aparece, algo
 * libera cupo que nunca se contó. Un canal con ruido conocido deja de avisar
 * de lo que importa, y este deja de tenerlo **y no puede volver a tenerlo sin
 * que se note**.
 *
 * **Desde `T-057`, también `layaway_balance.overpaid`**: lo pagado de un
 * apartado superó su total y `LayawayBalanceRecalculator` acotó el saldo a
 * cero (`REQ-PAY-008` SP-2). En producción se registra y se sigue; en la suite
 * no hay ningún camino legítimo que lo produzca, porque el reparto FIFO nunca
 * sobrepaga una cuota.
 *
 * El test que provoca una anomalía a propósito —liberar con el contador en
 * cero, `REQ-SUB-003` RB-8; el exceso de SP-2— la afirma con `Log::spy()`, que
 * sustituye al registrador y no emite `MessageLogged`: queda fuera de esta
 * vigilancia sin una lista de excepciones.
 */
trait FailsOnCounterDrift
{
    /**
     * Las anomalías que la suite no tolera, con lo que significan.
     *
     * @var array<string, string>
     */
    private const DRIFT_MESSAGES = [
        'usage_counter.below_zero' => 'algo liberó cupo del plan que nunca se contó. Desde T-045 no es ruido esperado '
            .'—CreatesLayaways y CreateLayawayAction suben el contador al crear—',
        'layaway_balance.overpaid' => 'lo pagado de un apartado superó su total (REQ-PAY-008 SP-2). Desde T-057 ningún '
            .'camino legítimo lo produce: el FIFO no sobrepaga una cuota',
    ];

    /** @var list<array<string, mixed>> */
    protected array $counterDrift = [];

    protected function watchCounterDrift(): void
    {
        $this->counterDrift = [];

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            if (array_key_exists($event->message, self::DRIFT_MESSAGES)) {
                $this->counterDrift[] = ['message' => $event->message] + $event->context;
            }
        });
    }

    protected function assertNoCounterDrift(): void
    {
        $seen = array_values(array_unique(array_map(fn (array $drift): string => (string) $drift['message'], $this->counterDrift)));
        $meanings = array_map(fn (string $message): string => "{$message}: ".self::DRIFT_MESSAGES[$message], $seen);

        $this->assertSame([], $this->counterDrift, 'Se registró una deriva que la suite no tolera — '.implode('; ', $meanings)
            .'. Es una deriva real. Si el test la provoca a propósito, afírmala con Log::spy(). '
            .'Contexto: '.json_encode($this->counterDrift));
    }
}
