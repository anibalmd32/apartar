<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Notification\NotificationType;
use App\Jobs\Concerns\RunsInTenantContext;
use App\Models\Account;
use App\Models\Customer;
use App\Notifications\CustomerMessageNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

final class SendWebPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInTenantContext;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(
        public readonly int $organizationId,
        public readonly string $recipientType,
        public readonly int $recipientId,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url,
    ) {}

    public function tenantOrganizationId(): int
    {
        return $this->organizationId;
    }

    public function handle(): void
    {
        $this->runInTenantContext(function (): void {
            $recipient = match ($this->recipientType) {
                Account::query()->getModel()->getMorphClass() => Account::query()->find($this->recipientId),
                Customer::query()->getModel()->getMorphClass() => Customer::query()->find($this->recipientId),
                default => null,
            };

            if ($recipient === null) {
                return;
            }

            $business = (string) DB::table('organizations')->where('id', $this->organizationId)->value('name');

            $recipient->notify(new CustomerMessageNotification(
                NotificationType::ManualPush,
                $business,
                sprintf('%s · %s', $business, $this->title),
                [$this->body],
                $this->url ?? route('portal.dashboard'),
                $this->title,
                push: true,
            ));
        });
    }
}
