<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Mail\RecordMailtrapEventsAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class ProcessMailtrapEvents implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /**
     * @param  list<array<string, mixed>>  $events
     */
    public function __construct(public readonly array $events)
    {
        $this->onQueue('default');
    }

    public function handle(RecordMailtrapEventsAction $record): void
    {
        $record->handle($this->events);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }
}
