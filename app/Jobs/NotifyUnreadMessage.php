<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Chat\MessageSenderType;
use App\Domain\Notification\NotifiableResolver;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Portal\PushSubscriptionController;
use App\Jobs\Concerns\RunsInTenantContext;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Notifications\UnreadMessageNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class NotifyUnreadMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInTenantContext, SerializesModels;

    public const DELAY_SECONDS = 120;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $organizationId, public readonly int $conversationId) {}

    public static function dispatchFor(Message $message): void
    {
        if ($message->sender_type !== MessageSenderType::Team) {
            return;
        }

        $key = sprintf('chat:push:%d', $message->conversation_id);

        if (Cache::add($key, true, self::DELAY_SECONDS)) {
            self::dispatch((int) $message->organization_id, (int) $message->conversation_id)
                ->delay(now()->addSeconds(self::DELAY_SECONDS));
        }
    }

    public function tenantOrganizationId(): int
    {
        return $this->organizationId;
    }

    public function handle(TenantContext $context, NotifiableResolver $notifiables): void
    {
        if (! PushSubscriptionController::isConfigured()) {
            return;
        }

        $this->runInTenantContext(function () use ($context, $notifiables): void {
            $organization = $context->organization();
            $conversation = Conversation::query()->find($this->conversationId);

            if (! $conversation instanceof Conversation || $conversation->customer_unread_count === 0) {
                return;
            }

            $latest = $conversation->messages()
                ->where('sender_type', MessageSenderType::Team)
                ->whereNull('read_at')
                ->latest('id')
                ->first();
            $customer = Customer::query()->find($conversation->customer_id);

            if (! $latest instanceof Message || ! $customer instanceof Customer) {
                return;
            }

            $notifiables->for($customer)->notify(new UnreadMessageNotification(
                $organization->name,
                Str::limit($latest->body, UnreadMessageNotification::EXCERPT_LENGTH - 1, '…'),
                route('portal.chat.show', ['organization' => $organization->slug]),
            ));
        });
    }
}
