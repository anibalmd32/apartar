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
 * El test que provoca la anomalía a propósito —liberar con el contador en
 * cero, `REQ-SUB-003` RB-8— la afirma con `Log::spy()`, que sustituye al
 * registrador y no emite `MessageLogged`: queda fuera de esta vigilancia sin
 * una lista de excepciones.
 */
trait FailsOnCounterDrift
{
    /** @var list<array<string, mixed>> */
    protected array $counterDrift = [];

    protected function watchCounterDrift(): void
    {
        $this->counterDrift = [];

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            if ($event->message === 'usage_counter.below_zero') {
                $this->counterDrift[] = $event->context;
            }
        });
    }

    protected function assertNoCounterDrift(): void
    {
        $this->assertSame([], $this->counterDrift, 'Se registró usage_counter.below_zero: algo liberó cupo del plan '
            .'que nunca se contó. Desde T-045 no es ruido esperado —CreatesLayaways y CreateLayawayAction suben el '
            .'contador al crear—, así que es una deriva real. Si el test la provoca a propósito, afírmala con Log::spy(). '
            .'Contexto: '.json_encode($this->counterDrift));
    }
}
