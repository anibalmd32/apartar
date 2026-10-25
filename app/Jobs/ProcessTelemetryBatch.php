<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Telemetry\EventSanitizer;
use App\Domain\Telemetry\IngestBatchData;
use App\Domain\Telemetry\SanitizedEvent;
use App\Domain\Telemetry\VisitorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use App\Models\Customer;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProcessTelemetryBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const QUEUE = 'telemetry';

    public int $tries = 1;

    /** @var array<int, Organization|null> */
    private array $organizations = [];

    public function __construct(public readonly IngestBatchData $batch)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(EventSanitizer $sanitizer, TenantContext $tenant, TenantResolver $resolver): void
    {
        $now = CarbonImmutable::now('UTC');
        $identity = VisitorIdentity::of($this->batch->visitorId);
        $rows = [];
        $discarded = 0;

        foreach ($this->batch->events as $raw) {
            try {
                $event = $sanitizer->sanitize($raw, $now);

                if ($event === null) {
                    $discarded++;

                    continue;
                }

                $rows[] = $this->row($event, $identity, $this->customerOf($event, $identity, $tenant, $resolver), $now);
            } catch (Throwable $exception) {
                $discarded++;
                report($exception);
            }
        }

        if ($rows !== []) {
            DB::table('telemetry_events')->insert($rows);
        }

        if ($discarded > 0) {
            Log::debug('telemetry.discarded', ['count' => $discarded, 'accepted' => count($rows)]);
        }
    }

    private function customerOf(SanitizedEvent $event, VisitorIdentity $identity, TenantContext $tenant, TenantResolver $resolver): ?int
    {
        $customerId = $identity->customerIn($event->organizationId);
        $organization = $event->organizationId === null ? null : $this->organization($event->organizationId, $resolver);

        if ($customerId === null || $organization === null) {
            return null;
        }

        return $tenant->runFor($organization, fn (): ?int => Customer::query()->whereKey($customerId)->exists() ? $customerId : null);
    }

    private function organization(int $id, TenantResolver $resolver): ?Organization
    {
        if (! array_key_exists($id, $this->organizations)) {
            try {
                $this->organizations[$id] = $resolver->forOrganizationId($id);
            } catch (ModelNotFoundException) {
                $this->organizations[$id] = null;
            }
        }

        return $this->organizations[$id];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SanitizedEvent $event, VisitorIdentity $identity, ?int $customerId, CarbonImmutable $now): array
    {
        return [
            'organization_id' => $event->organizationId,
            'catalog_id' => $event->catalogId,
            'event_name' => $event->name->value,
            'visitor_id' => $this->batch->visitorId,
            'account_id' => $identity->accountId,
            'customer_id' => $customerId,
            'subject_type' => $event->subjectType,
            'subject_id' => $event->subjectId,
            'payload' => json_encode((object) $event->payload, JSON_THROW_ON_ERROR),
            'session_id' => $this->batch->sessionId,
            'ip_hash' => $this->batch->ipHash,
            'user_agent' => $this->batch->userAgent,
            'occurred_at' => $event->occurredAt->format('Y-m-d H:i:s'),
            'created_at' => $now->format('Y-m-d H:i:s'),
        ];
    }
}
