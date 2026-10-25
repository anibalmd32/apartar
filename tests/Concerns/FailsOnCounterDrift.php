<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;

trait FailsOnCounterDrift
{
    /**
     * @var array<string, string>
     */
    private const DRIFT_MESSAGES = [
        'usage_counter.below_zero' => 'algo liberó cupo del plan que nunca se contó. No es ruido esperado '
            .'—CreatesLayaways y CreateLayawayAction suben el contador al crear—',
        'layaway_balance.overpaid' => 'lo pagado de un apartado superó su total. Ningún '
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
