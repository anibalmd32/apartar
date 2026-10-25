<?php

declare(strict_types=1);

namespace App\Actions\Notification;

use App\Domain\Notification\NotifiableResolver;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\SendWebPush;
use App\Models\Customer;
use App\Models\ManualPush;
use App\Models\User;
use App\Queries\CollectionFilterInput;
use App\Support\TableState;
use Illuminate\Support\Facades\Log;

/**
 * @phpstan-type Report array{recipients: int, reachable: int, unreachable: int, queued: int}
 */
final class SendManualPushAction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly NotifiableResolver $resolver,
    ) {}

    /**
     * @return array{recipients: int, reachable: int, unreachable: int, queued: int}
     */
    public function preview(ManualPushData $data): array
    {
        $customers = $this->recipients($data);
        $reachable = array_filter($customers, fn (Customer $customer): bool => $this->resolver->for($customer)->pushSubscriptions()->exists());

        return [
            'recipients' => count($customers),
            'reachable' => count($reachable),
            'unreachable' => count($customers) - count($reachable),
            'queued' => 0,
        ];
    }

    /**
     * @return array{recipients: int, reachable: int, unreachable: int, queued: int}
     */
    public function handle(ManualPushData $data, User $actor): array
    {
        $customers = $this->recipients($data);
        $queued = 0;

        foreach ($customers as $customer) {
            $recipient = $this->resolver->for($customer);

            if (! $recipient->pushSubscriptions()->exists()) {
                continue;
            }

            SendWebPush::dispatch((int) $this->context->organization()->getKey(), $recipient->getMorphClass(), (int) $recipient->getKey(), $data->title, $data->body, $data->url);
            $queued++;
        }

        ManualPush::query()->create([
            'sent_by' => $actor->getKey(),
            'title' => $data->title,
            'body' => $data->body,
            'mode' => $data->customerIds !== null ? 'selection' : 'segment',
            'segment' => is_string($data->segment['preset'] ?? null) ? $data->segment['preset'] : null,
            'recipients' => count($customers),
            'queued' => $queued,
        ]);

        Log::info('push.manual', [
            'organization_id' => $this->context->organization()->getKey(),
            'actor_id' => $actor->getKey(),
            'mode' => $data->customerIds !== null ? 'selection' : 'segment',
            'recipients' => count($customers),
            'queued' => $queued,
        ]);

        return ['recipients' => count($customers), 'reachable' => $queued, 'unreachable' => count($customers) - $queued, 'queued' => $queued];
    }

    /**
     * @return list<Customer>
     */
    private function recipients(ManualPushData $data): array
    {
        if ($data->customerIds !== null) {
            return Customer::query()->whereKey($data->customerIds)->get()->all();
        }

        $filters = CollectionFilterInput::resolve($data->segment ?? [], new TableState(1, 1, null, false));
        $ids = $filters->query()->reorder()->pluck('customer_id')->unique()->values()->all();

        return Customer::query()->whereKey($ids)->get()->all();
    }
}
