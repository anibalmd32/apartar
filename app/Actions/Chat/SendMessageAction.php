<?php

declare(strict_types=1);

namespace App\Actions\Chat;

use App\Domain\Chat\MessageSenderType;
use App\Domain\Tenancy\TenantContext;
use App\Events\MessageSent;
use App\Jobs\NotifyUnreadMessage;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SendMessageAction
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @throws InvalidArgumentException
     */
    public function handle(Organization $organization, Customer $customer, MessageSenderType $sender, int $senderId, string $body, string $idempotencyKey): Message
    {
        $text = trim($body);

        if ($text === '' || mb_strlen($text) > Message::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf('Un mensaje lleva entre 1 y %d caracteres.', Message::MAX_LENGTH));
        }

        if ((int) $customer->organization_id !== (int) $organization->getKey()) {
            throw new InvalidArgumentException('La ficha no es de esa organización.');
        }

        return $this->context->runFor($organization, function () use ($customer, $sender, $senderId, $text, $idempotencyKey): Message {
            $existing = Message::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing instanceof Message) {
                return $existing;
            }

            try {
                $message = DB::transaction(fn (): Message => $this->write($customer, $sender, $senderId, $text, $idempotencyKey));
            } catch (UniqueConstraintViolationException) {
                return Message::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
            }

            event(MessageSent::of($message, $this->senderName($customer, $sender, $senderId)));

            NotifyUnreadMessage::dispatchFor($message);

            return $message;
        });
    }

    private function write(Customer $customer, MessageSenderType $sender, int $senderId, string $text, string $idempotencyKey): Message
    {
        $now = CarbonImmutable::now();

        DB::table('conversations')->insertOrIgnore([
            'organization_id' => $customer->organization_id,
            'customer_id' => $customer->getKey(),
            'customer_unread_count' => 0,
            'admin_unread_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $conversation = Conversation::query()->where('customer_id', $customer->getKey())->lockForUpdate()->firstOrFail();

        $message = new Message;
        $message->forceFill([
            'conversation_id' => $conversation->getKey(),
            'sender_type' => $sender,
            'sender_id' => $senderId,
            'body' => $text,
            'idempotency_key' => $idempotencyKey,
        ])->save();

        $conversation->increment(
            $sender === MessageSenderType::Customer ? 'admin_unread_count' : 'customer_unread_count',
            1,
            ['last_message_at' => $now],
        );

        return $message;
    }

    private function senderName(Customer $customer, MessageSenderType $sender, int $senderId): string
    {
        return $sender === MessageSenderType::Customer
            ? $customer->full_name
            : (string) User::query()->whereKey($senderId)->value('name');
    }
}
