<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Mail\ImportInboundMessageAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class ImportInboundMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $inboxId, public readonly string $messageId)
    {
        $this->onQueue('high');
    }

    public function handle(ImportInboundMessageAction $import): void
    {
        $import->handle($this->inboxId, $this->messageId);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 300];
    }
}
