<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Chat\MessageSenderType;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

final class MessageSent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public string $broadcastQueue = 'high';

    public function __construct(
        public readonly int $organizationId,
        public readonly int $conversationId,
        public readonly int $messageId,
        public readonly MessageSenderType $sender,
        public readonly string $senderName,
        public readonly string $body,
        public readonly string $sentAt,
    ) {}

    public static function of(Message $message, string $senderName): self
    {
        return new self(
            organizationId: (int) $message->organization_id,
            conversationId: (int) $message->conversation_id,
            messageId: (int) $message->getKey(),
            sender: $message->sender_type,
            senderName: $senderName,
            body: $message->body,
            sentAt: ($message->created_at ?? now())->toIso8601String(),
        );
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(self::conversationChannel($this->organizationId, $this->conversationId)),
            new PrivateChannel(self::chatChannel($this->organizationId)),
        ];
    }

    public static function conversationChannel(int $organizationId, int $conversationId): string
    {
        return sprintf('organization.%d.conversation.%d', $organizationId, $conversationId);
    }

    public static function chatChannel(int $organizationId): string
    {
        return sprintf('organization.%d.chat', $organizationId);
    }

    /**
     * @return array{id: int, conversationId: int, sender: string, senderName: string, body: string, sentAt: string}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->messageId,
            'conversationId' => $this->conversationId,
            'sender' => $this->sender->value,
            'senderName' => $this->senderName,
            'body' => $this->body,
            'sentAt' => $this->sentAt,
        ];
    }
}
