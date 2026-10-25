<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Chat\MessageSenderType;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

final class UserTyping implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $conversationId,
        public readonly MessageSenderType $sender,
        public readonly string $name,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(MessageSent::conversationChannel($this->organizationId, $this->conversationId))];
    }

    /**
     * @return array{conversationId: int, sender: string, name: string}
     */
    public function broadcastWith(): array
    {
        return ['conversationId' => $this->conversationId, 'sender' => $this->sender->value, 'name' => $this->name];
    }
}
