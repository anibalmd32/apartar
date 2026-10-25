<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Chat\MessageSenderType;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

final class ConversationMessagesQuery
{
    public const PAGE_SIZE = 30;

    /**
     * @return list<array{id: int, conversationId: int, sender: string, senderName: string, body: string, sentAt: string, readAt: string|null}>
     */
    public function latest(Conversation $conversation, ?CarbonInterface $since): array
    {
        return $this->present($conversation, $this->base($conversation, $since)->orderByDesc('id')->limit(self::PAGE_SIZE)->get()->reverse()->values()->all());
    }

    /**
     * @return list<array{id: int, conversationId: int, sender: string, senderName: string, body: string, sentAt: string, readAt: string|null}>
     */
    public function before(Conversation $conversation, int $beforeId, ?CarbonInterface $since): array
    {
        return $this->present($conversation, $this->base($conversation, $since)->where('id', '<', $beforeId)->orderByDesc('id')->limit(self::PAGE_SIZE)->get()->reverse()->values()->all());
    }

    /**
     * @return list<array{id: int, conversationId: int, sender: string, senderName: string, body: string, sentAt: string, readAt: string|null}>
     */
    public function after(Conversation $conversation, int $afterId, ?CarbonInterface $since): array
    {
        return $this->present($conversation, $this->base($conversation, $since)->where('id', '>', $afterId)->orderBy('id')->limit(100)->get()->all());
    }

    public function hasHiddenHistory(Conversation $conversation, ?CarbonInterface $since): bool
    {
        return $since !== null && $conversation->messages()->where('created_at', '<', $since)->exists();
    }

    /**
     * @return Builder<Message>
     */
    private function base(Conversation $conversation, ?CarbonInterface $since): Builder
    {
        return Message::query()
            ->where('conversation_id', $conversation->getKey())
            ->when($since !== null, fn (Builder $query) => $query->where('created_at', '>=', $since));
    }

    /**
     * @param  list<Message>  $messages
     * @return list<array{id: int, conversationId: int, sender: string, senderName: string, body: string, sentAt: string, readAt: string|null}>
     */
    private function present(Conversation $conversation, array $messages): array
    {
        $teamIds = array_values(array_unique(array_map(
            fn (Message $message): int => $message->sender_id,
            array_filter($messages, fn (Message $message): bool => $message->sender_type === MessageSenderType::Team),
        )));
        $teamNames = $teamIds === [] ? collect() : User::query()->whereIn('id', $teamIds)->pluck('name', 'id');
        $customerName = (string) Customer::query()->whereKey($conversation->customer_id)->value('full_name');

        return array_map(fn (Message $message): array => [
            'id' => (int) $message->getKey(),
            'conversationId' => (int) $message->conversation_id,
            'sender' => $message->sender_type->value,
            'senderName' => $message->sender_type === MessageSenderType::Customer ? $customerName : (string) $teamNames->get($message->sender_id),
            'body' => $message->body,
            'sentAt' => $message->created_at->toIso8601String(),
            'readAt' => $message->read_at?->toIso8601String(),
        ], $messages);
    }
}
