<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Chat\MarkConversationReadAction;
use App\Actions\Chat\SendMessageAction;
use App\Domain\Chat\MessageSenderType;
use App\Domain\Money\MoneyFormatter;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanAllowance;
use App\Domain\Tenancy\OrganizationCapability;
use App\Domain\Tenancy\TenantContext;
use App\Events\MessageSent;
use App\Events\UserTyping;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\User;
use App\Queries\ConversationListQuery;
use App\Queries\ConversationMessagesQuery;
use App\Queries\CustomerBalanceQuery;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class ChatController extends Controller
{
    public function __construct(
        private readonly ConversationListQuery $list,
        private readonly ConversationMessagesQuery $messages,
        private readonly LimitEnforcer $limits,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): Response
    {
        return $this->page($request, null);
    }

    public function show(Request $request, Conversation $conversation, MarkConversationReadAction $read): Response
    {
        $read->handle($conversation, MessageSenderType::Team);

        return $this->page($request, $conversation->refresh());
    }

    public function store(SendMessageRequest $request, Conversation $conversation, SendMessageAction $send, TenantContext $context): JsonResponse
    {
        $this->authorizeChat($request);

        $message = $send->handle(
            $context->organization(),
            Customer::query()->findOrFail($conversation->customer_id),
            MessageSenderType::Team,
            (int) $request->user()?->getKey(),
            $request->body(),
            $request->idempotencyKey(),
        );

        return response()->json(['id' => $message->getKey()], 201);
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeChat($request);

        return response()->json($this->messages->after($conversation, (int) $request->integer('after'), $this->since()));
    }

    public function typing(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeChat($request);

        broadcast(new UserTyping((int) $conversation->organization_id, (int) $conversation->getKey(), MessageSenderType::Team, (string) $request->user()?->name))->toOthers();

        return response()->json(null, 202);
    }

    private function page(Request $request, ?Conversation $active): Response
    {
        $this->authorizeChat($request);
        $since = $this->since();
        $search = $request->query('q');
        $unreadOnly = $request->boolean('no_leidos');
        $page = $this->list->forOrganization(is_string($search) ? $search : null, $since, $unreadOnly);

        return Inertia::render('backoffice/chat/Index', [
            'threads' => array_map(fn (Conversation $row): array => [
                'id' => (int) $row->getKey(),
                'customerName' => (string) $row->getAttribute('customer_name'),
                'customerDocument' => (string) $row->getAttribute('customer_document'),
                'excerpt' => Str::limit((string) $row->getAttribute('last_message_body'), 80),
                'lastMessageAt' => $row->last_message_at?->toIso8601String(),
                'unread' => $row->admin_unread_count,
                'url' => route('chat.show', ['conversation' => $row->getKey()]),
            ], $page->items()),
            'nextPageUrl' => $page->nextPageUrl(),
            'inboxChannel' => MessageSent::chatChannel($this->context->organizationId()),
            'search' => is_string($search) ? $search : '',
            'unreadOnly' => $unreadOnly,
            'active' => $active === null ? null : $this->thread($active, $since),
            'historyDays' => $since === null ? null : $this->limits->plan()->allowance(PlanAllowance::ChatHistoryDays),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function thread(Conversation $conversation, ?CarbonImmutable $since): array
    {
        $customer = Customer::query()->findOrFail($conversation->customer_id);
        $balance = app(CustomerBalanceQuery::class)->forCustomer($customer);

        return [
            'id' => (int) $conversation->getKey(),
            'channel' => MessageSent::conversationChannel((int) $conversation->organization_id, (int) $conversation->getKey()),
            'messages' => $this->messages->latest($conversation, $since),
            'hiddenHistory' => $this->messages->hasHiddenHistory($conversation, $since),
            'sendUrl' => route('chat.store', ['conversation' => $conversation->getKey()]),
            'messagesUrl' => route('chat.messages', ['conversation' => $conversation->getKey()]),
            'typingUrl' => route('chat.typing', ['conversation' => $conversation->getKey()]),
            'context' => [
                'customerName' => $customer->full_name,
                'phone' => PhoneNumber::tryFromInput($customer->phone)?->formatted() ?? $customer->phone,
                'activeLayaways' => count($balance->active),
                'remaining' => MoneyFormatter::format($balance->remaining()),
                'overdueInstallments' => $balance->overdueInstallments(),
                'overdueAmount' => $balance->overdueInstallments() > 0 ? MoneyFormatter::format($balance->overdueAmount()) : null,
                'nextDueDate' => $balance->nextDueDate()?->toDateString(),
                'layaways' => array_map(fn (Layaway $layaway): array => [
                    'code' => $layaway->code,
                    'productName' => $layaway->product_name_snapshot,
                    'status' => $layaway->status->value,
                    'statusLabel' => $layaway->status->label(),
                    'url' => route('layaways.show', $layaway),
                ], $balance->active),
                'profileUrl' => route('customers.show', ['customer' => $customer->getKey()]),
            ],
        ];
    }

    private function since(): ?CarbonImmutable
    {
        $days = $this->limits->plan()->allowance(PlanAllowance::ChatHistoryDays);

        return $days === null ? null : CarbonImmutable::now()->subDays($days);
    }

    private function authorizeChat(Request $request): void
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->disabled_at === null && $user->role->can(OrganizationCapability::Chat), 403);
    }
}
