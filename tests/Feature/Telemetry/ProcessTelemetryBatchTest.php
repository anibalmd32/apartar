<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Domain\Telemetry\IngestBatchData;
use App\Domain\Telemetry\VisitorIdentity;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\ProcessTelemetryBatch;
use App\Models\Catalog;
use App\Models\Customer;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ProcessTelemetryBatchTest extends TestCase
{
    use ReusesMigratedDatabase;

    private const VISITOR = '0190f0f0-0000-7000-8000-000000000001';

    /** @var list<Catalog> */
    private array $catalogs = [];

    /** @var list<Organization> */
    private array $organizations = [];

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-21 12:00:00');

        foreach (range(1, 3) as $index) {
            $organization = Organization::factory()->create();
            $this->organizations[] = $organization;
            $this->catalogs[] = $this->withinOrganization($organization, fn (): Catalog => Catalog::factory()->published()->create());
        }

        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_valid_events_go_in_a_single_bulk_insert(): void
    {
        $inserts = 0;
        DB::listen(function (QueryExecuted $query) use (&$inserts): void {
            $inserts += str_starts_with(strtolower($query->sql), 'insert into `telemetry_events`') ? 1 : 0;
        });

        $this->process(array_fill(0, 20, $this->viewed(0, '2026-10-21T02:15:00Z')));

        $this->assertSame(1, $inserts);
        $this->assertSame(20, DB::table('telemetry_events')->count());
        $this->assertSame(['2026-10-21 02:15:00'], DB::table('telemetry_events')->distinct()->pluck('occurred_at')->all());
    }

    public function test_a_corrupt_event_does_not_abort_the_batch(): void
    {
        $events = array_fill(0, 49, $this->viewed(0));
        $events[] = ['name' => 'catalog_viewed', 'catalogSlug' => ['no' => 'es un slug'], 'occurredAt' => ['tampoco']];

        $this->process($events);

        $this->assertSame(49, DB::table('telemetry_events')->count());
    }

    public function test_a_multi_organization_batch_attributes_each_event_to_its_own(): void
    {
        $customer = $this->withinOrganization($this->organizations[1], fn (): Customer => Customer::factory()->create());
        $this->withoutOrganization();
        VisitorIdentity::remember(self::VISITOR, null, (int) $this->organizations[1]->getKey(), (int) $customer->getKey());

        $this->process([$this->viewed(0), $this->viewed(1), $this->viewed(2), ['name' => 'feed_viewed']]);

        $rows = DB::table('telemetry_events')->orderBy('id')->get();

        $this->assertSame(
            [...array_map(fn (Organization $organization): int => (int) $organization->getKey(), $this->organizations), null],
            $rows->map(fn (object $row): ?int => $row->organization_id === null ? null : (int) $row->organization_id)->all(),
        );
        $this->assertSame([null, (int) $customer->getKey(), null, null], $rows->map(fn (object $row): ?int => $row->customer_id === null ? null : (int) $row->customer_id)->all());
        $this->assertFalse(app(TenantContext::class)->hasOrganization(), 'El job no deja ningún tenant fijado.');
    }

    public function test_the_tenant_is_cleared_between_events(): void
    {
        $customer = $this->withinOrganization($this->organizations[0], fn (): Customer => Customer::factory()->create());
        $this->withoutOrganization();
        VisitorIdentity::remember(self::VISITOR, null, (int) $this->organizations[1]->getKey(), (int) $customer->getKey());

        $this->process([$this->viewed(1), $this->viewed(0)]);

        $this->assertSame(0, DB::table('telemetry_events')->whereNotNull('customer_id')->count());
    }

    public function test_the_account_comes_from_the_server_side_identity(): void
    {
        VisitorIdentity::remember(self::VISITOR, 42);

        $this->process([$this->viewed(0), ['name' => 'feed_viewed', 'accountId' => 7]]);

        $this->assertSame([42, 42], DB::table('telemetry_events')->pluck('account_id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_an_event_of_a_soft_deleted_organization_is_still_inserted(): void
    {
        $this->organizations[2]->delete();

        $this->process([$this->viewed(2)]);

        $this->assertSame((int) $this->organizations[2]->getKey(), (int) DB::table('telemetry_events')->value('organization_id'));
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function process(array $events): void
    {
        $batch = new IngestBatchData(self::VISITOR, 'sesion-abc-123', $events, str_repeat('a', 64), 'Prueba/1.0');

        app()->call([new ProcessTelemetryBatch($batch), 'handle']);
    }

    /**
     * @return array<string, mixed>
     */
    private function viewed(int $catalog, string $at = '2026-10-21T11:00:00Z'): array
    {
        return ['name' => 'catalog_viewed', 'catalogSlug' => $this->catalogs[$catalog]->slug, 'occurredAt' => $at, 'payload' => ['source' => 'link']];
    }
}
