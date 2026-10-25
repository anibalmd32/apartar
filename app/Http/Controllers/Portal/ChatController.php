<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Actions\Chat\MarkConversationReadAction;
use App\Actions\Chat\SendMessageAction;
use App\Domain\Account\PortalContext;
use App\Domain\Chat\MessageSenderType;
use App\Domain\Chat\NoCustomerRecordException;
use App\Domain\Tenancy\TenantContext;
use App\Events\MessageSent;
use App\Events\UserTyping;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Organization;
use App\Queries\ConversationMessagesQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ChatController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ConversationMessagesQuery $messages,
    ) {}

    public function index(PortalContext $portal): Response
    {
        $threads = [];

        foreach ($portal->customers() as $customer) {
            $organization = Organization::query()->findOrFail($customer->organization_id);

            $threads[] = $this->context->runFor($organization, function () use ($organization, $customer): array {
                $conversation = Conversation::query()->where('customer_id', $customer->getKey())->first();

                return [
                    'businessName' => $organization->name,
                    'url' => route('portal.chat.show', ['organization' => $organization->slug]),
                    'unread' => $conversation === null ? 0 : $conversation->customer_unread_count,
                    'lastMessageAt' => $conversation?->last_message_at?->toIso8601String(),
                ];
            });
        }

        return Inertia::render('portal/ChatIndex', ['threads' => $threads]);
    }

    public function show(PortalContext $portal, Organization $organization, MarkConversationReadAction $read): Response
    {
        $customer = $this->customerIn($portal, $organization);

        return Inertia::render('portal/Chat', $this->context->runFor($organization, function () use ($organization, $customer, $read): array {
            $conversation = Conversation::query()->where('customer_id', $customer->getKey())->first();

            if ($conversation instanceof Conversation) {
                $read->handle($conversation, MessageSenderType::Customer);
            }

            return [
                'businessName' => $organization->name,
                'thread' => [
                    'channel' => $conversation === null ? null : MessageSent::conversationChannel((int) $organization->getKey(), (int) $conversation->getKey()),
                    'messages' => $conversation === null ? [] : $this->messages->latest($conversation, null),
                    'sendUrl' => route('portal.chat.store', ['organization' => $organization->slug]),
                    'messagesUrl' => route('portal.chat.messages', ['organization' => $organization->slug]),
                    'typingUrl' => route('portal.chat.typing', ['organization' => $organization->slug]),
                ],
            ];
        }));
    }

    public function store(SendMessageRequest $request, PortalContext $portal, Organization $organization, SendMessageAction $send): JsonResponse
    {
        $customer = $this->customerIn($portal, $organization);
        $message = $send->handle($organization, $customer, MessageSenderType::Customer, (int) $customer->getKey(), $request->body(), $request->idempotencyKey());

        return response()->json(['id' => $message->getKey()], 201);
    }

    public function messages(Request $request, PortalContext $portal, Organization $organization): JsonResponse
    {
        $customer = $this->customerIn($portal, $organization);
        $after = (int) $request->integer('after');

        return response()->json($this->context->runFor($organization, function () use ($customer, $after): array {
            $conversation = Conversation::query()->where('customer_id', $customer->getKey())->first();

            return $conversation === null ? [] : $this->messages->after($conversation, $after, null);
        }));
    }

    public function typing(PortalContext $portal, Organization $organization): JsonResponse
    {
        $customer = $this->customerIn($portal, $organization);

        $this->context->runFor($organization, function () use ($organization, $customer): void {
            $conversationId = Conversation::query()->where('customer_id', $customer->getKey())->value('id');

            if ($conversationId !== null) {
                broadcast(new UserTyping((int) $organization->getKey(), (int) $conversationId, MessageSenderType::Customer, $customer->full_name))->toOthers();
            }
        });

        return response()->json(null, 202);
    }

    private function customerIn(PortalContext $portal, Organization $organization): Customer
    {
        return $portal->customerIn((int) $organization->getKey()) ?? throw new NoCustomerRecordException;
    }
}
