<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Chat\MessageSenderType;
use App\Domain\Tenancy\TenantContext;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class RecountConversations extends Command
{
    public const LAST_RUN_KEY = 'chat:recount:last';

    protected $signature = 'chat:recount {--organization= : Acota a una organización, por su id}';

    protected $description = 'Recalcula los contadores de no leídos del chat desde los mensajes';

    public function handle(TenantContext $context): int
    {
        $only = $this->option('organization');
        $organizations = Organization::query()
            ->when(is_numeric($only), fn ($query) => $query->whereKey((int) $only))
            ->orderBy('id')
            ->get();

        $drifts = [];

        foreach ($organizations as $organization) {
            array_push($drifts, ...$context->runFor($organization, fn (): array => $this->recount($organization)));
        }

        Cache::forever(self::LAST_RUN_KEY, ['drifts' => count($drifts), 'at' => CarbonImmutable::now()->toIso8601String()]);

        $this->info(sprintf('%d organizaciones revisadas; %d hilos descuadrados, ya corregidos.', $organizations->count(), count($drifts)));

        if ($drifts !== []) {
            $this->table(['Organización', 'Hilo', 'Equipo decía', 'Equipo mide', 'Cliente decía', 'Cliente mide'], $drifts);
        }

        return self::SUCCESS;
    }

    /**
     * @return list<list<int>>
     */
    private function recount(Organization $organization): array
    {
        $unread = fn (MessageSenderType $sender) => Message::query()
            ->selectRaw('COUNT(*)')
            ->whereColumn('messages.conversation_id', 'conversations.id')
            ->where('messages.sender_type', $sender)
            ->whereNull('messages.read_at');

        $rows = Conversation::query()
            ->select(['conversations.id', 'conversations.admin_unread_count', 'conversations.customer_unread_count'])
            ->selectSub($unread(MessageSenderType::Customer), 'admin_measured')
            ->selectSub($unread(MessageSenderType::Team), 'customer_measured')
            ->get();

        $drifts = [];

        foreach ($rows as $row) {
            $admin = (int) $row->getAttribute('admin_measured');
            $customer = (int) $row->getAttribute('customer_measured');

            if ($admin === $row->admin_unread_count && $customer === $row->customer_unread_count) {
                continue;
            }

            $drifts[] = [(int) $organization->getKey(), (int) $row->getKey(), $row->admin_unread_count, $admin, $row->customer_unread_count, $customer];

            Conversation::query()->whereKey($row->getKey())->update([
                'admin_unread_count' => $admin,
                'customer_unread_count' => $customer,
            ]);
        }

        return $drifts;
    }
}
