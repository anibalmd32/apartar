<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Mail\MailActivityKind;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class MailActivity implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public const CHANNEL = 'platform.mail';

    public const MAX_IDS = 100;

    public string $broadcastQueue = 'high';

    /**
     * @param  list<string>  $mails
     * @param  list<int>  $threads
     */
    public function __construct(
        public readonly MailActivityKind $kind,
        public readonly array $mails = [],
        public readonly array $threads = [],
    ) {}

    /**
     * @param  array<string|null>  $mails
     * @param  array<int|null>  $threads
     */
    public static function announce(MailActivityKind $kind, array $mails = [], array $threads = []): void
    {
        try {
            $event = new self(
                $kind,
                array_slice(array_values(array_unique(array_filter($mails, 'is_string'))), 0, self::MAX_IDS),
                array_slice(array_values(array_unique(array_filter($threads, 'is_int'))), 0, self::MAX_IDS),
            );

            DB::afterCommit(function () use ($event): void {
                try {
                    event($event);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::CHANNEL)];
    }

    /**
     * @return array{kind: string, mails: list<string>, threads: list<int>}
     */
    public function broadcastWith(): array
    {
        return [
            'kind' => $this->kind->value,
            'mails' => $this->mails,
            'threads' => $this->threads,
        ];
    }
}
