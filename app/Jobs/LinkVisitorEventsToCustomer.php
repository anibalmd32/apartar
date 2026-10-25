<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Http\Middleware\AssignVisitorId;
use App\Jobs\Concerns\RunsInTenantContext;
use App\Models\Customer;
use App\Models\TelemetryEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;

final class LinkVisitorEventsToCustomer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInTenantContext;

    public const WINDOW_DAYS = 90;

    public const CHUNK = 500;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $customerId,
        public readonly string $visitorId,
    ) {
        $this->onQueue(ProcessTelemetryBatch::QUEUE);
    }

    public static function dispatchFor(Request $request, Customer $customer): void
    {
        $visitorId = AssignVisitorId::of($request);

        if ($visitorId === null) {
            return;
        }

        rescue(fn () => self::dispatch((int) $customer->organization_id, (int) $customer->getKey(), $visitorId), report: true);
    }

    public function tenantOrganizationId(): int
    {
        return $this->organizationId;
    }

    public function handle(): void
    {
        $this->runInTenantContext(function (): void {
            if (! Customer::query()->whereKey($this->customerId)->exists()) {
                return;
            }

            TelemetryEvent::query()
                ->where('visitor_id', $this->visitorId)
                ->whereNull('customer_id')
                ->where('occurred_at', '>=', now()->subDays(self::WINDOW_DAYS))
                ->select('id')
                ->chunkById(self::CHUNK, function (Collection $events): void {
                    TelemetryEvent::query()->whereKey($events->modelKeys())->update(['customer_id' => $this->customerId]);
                });
        });
    }
}
