<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Conversation;
use App\Models\Message;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;

final class ConversationListQuery
{
    public const PER_PAGE = 30;

    /**
     * @return Paginator<int, Conversation>
     */
    public function forOrganization(?string $search, ?CarbonInterface $since, bool $unreadOnly = false): Paginator
    {
        $excerpt = Message::query()
            ->select('body')
            ->whereColumn('messages.conversation_id', 'conversations.id')
            ->when($since !== null, fn (Builder $query) => $query->where('messages.created_at', '>=', $since))
            ->orderByDesc('messages.id')
            ->limit(1);

        $query = Conversation::query()
            ->join('customers', 'customers.id', '=', 'conversations.customer_id')
            ->whereNull('customers.deleted_at')
            ->select([
                'conversations.*',
                'customers.full_name as customer_name',
                'customers.document_number as customer_document',
                'customers.phone as customer_phone',
            ])
            ->selectSub($excerpt, 'last_message_body')
            ->orderByRaw('conversations.admin_unread_count > 0 DESC')
            ->orderByDesc('conversations.last_message_at')
            ->orderByDesc('conversations.id');

        if ($unreadOnly) {
            $query->where('conversations.admin_unread_count', '>', 0);
        }

        $term = $search === null ? '' : trim($search);

        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';

            $query->where(fn (Builder $where) => $where
                ->where('customers.full_name', 'like', $like)
                ->orWhere('customers.document_number', 'like', $like)
                ->orWhere('customers.phone', 'like', $like)
                ->orWhereExists(fn ($messages) => $messages
                    ->selectRaw('1')
                    ->from('messages')
                    ->whereColumn('messages.conversation_id', 'conversations.id')
                    ->whereColumn('messages.organization_id', 'conversations.organization_id')
                    ->where('messages.body', 'like', $like)));
        }

        /** @var Paginator<int, Conversation> $page */
        $page = $query->simplePaginate(self::PER_PAGE)->withQueryString();

        return $page;
    }
}
