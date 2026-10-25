<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationStatus;
use App\Http\Resources\Platform\OrganizationRowResource;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Models\UsageCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsPlatformOperator;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

final class OrganizationDirectoryTest extends TestCase
{
    use ActsAsPlatformOperator;
    use CreatesLayaways;
    use RefreshDatabase;

    public function test_rows_carry_only_aggregates(): void
    {
        [$organization] = $this->businessWithOwner();
        $this->withinOrganization($organization, fn () => Customer::factory()->create([
            'full_name' => 'María Secreta', 'document_number' => 'V12345678', 'phone' => '04141234567', 'email' => 'maria@secreta.test',
        ]));
        $this->withoutOrganization();
        $this->asOperator();

        $props = $this->get('/plataforma/organizaciones')->assertOk()->viewData('page')['props'];
        $json = (string) json_encode($props['rows']);

        $this->assertSame(OrganizationRowResource::FIELDS, array_keys($props['rows'][0]));
        $this->assertSame(1, $props['rows'][0]['counts']['customers']);

        foreach (['María Secreta', 'V12345678', '04141234567', 'maria@secreta.test', 'account'] as $personal) {
            $this->assertStringNotContainsString($personal, $json, $personal);
        }
    }

    public function test_risk_signals(): void
    {
        $plan = Plan::factory()->create(['max_products' => 10]);
        [$atLimit] = $this->businessWithOwner($plan);
        [$overdue] = $this->businessWithOwner();
        $this->withinOrganization($atLimit, fn () => UsageCounter::query()->where('metric', UsageMetric::Products)->update(['value' => 9]) ?: UsageCounter::factory()->create(['metric' => UsageMetric::Products, 'value' => 9]));
        $this->withinOrganization($overdue, fn () => SubscriptionInvoice::factory()->create(['status' => InvoiceStatus::Overdue]));
        $this->withoutOrganization();
        $this->asOperator();

        /** @var list<array<string, mixed>> $list */
        $list = $this->get('/plataforma/organizaciones')->viewData('page')['props']['rows'];
        $rows = array_column($list, null, 'id');

        $this->assertTrue($rows[$atLimit->getKey()]['risks']['atLimit']);
        $this->assertFalse($rows[$overdue->getKey()]['risks']['atLimit']);
        $this->assertTrue($rows[$overdue->getKey()]['risks']['overdueInvoices']);
        $this->assertTrue($rows[$atLimit->getKey()]['risks']['inactive'], 'sin actividad, candidata a abandono.');

        $filtered = $this->get('/plataforma/organizaciones?vencidas=1')->viewData('page')['props']['rows'];
        $this->assertSame([$overdue->getKey()], array_column($filtered, 'id'));
    }

    public function test_order_and_closed_organizations(): void
    {
        [$quiet] = $this->businessWithOwner();
        $busy = $this->subscribe(Organization::factory()->create());
        $this->layawayIn($busy);
        $closed = Organization::factory()->closed()->create();
        $this->withoutOrganization();
        $this->asOperator();

        $ids = array_column($this->get('/plataforma/organizaciones')->viewData('page')['props']['rows'], 'id');

        $this->assertSame([$busy->getKey(), $quiet->getKey()], array_slice($ids, 0, 2));
        $this->assertNotContains($closed->getKey(), $ids);
        $this->assertContains($closed->getKey(), array_column($this->get('/plataforma/organizaciones?cerradas=1')->viewData('page')['props']['rows'], 'id'));
        $this->assertSame(OrganizationStatus::Closed, $closed->status);
        $this->assertSame(2, $this->get('/plataforma/organizaciones')->viewData('page')['props']['total']);
    }

    public function test_the_suspended_filter(): void
    {
        $active = $this->subscribe(Organization::factory()->create());
        $suspended = $this->subscribe(Organization::factory()->suspended()->create());
        $this->withinOrganization($suspended, fn () => \App\Models\Subscription::query()->update(['status' => \App\Domain\Subscription\SubscriptionStatus::Suspended]));
        $this->withoutOrganization();
        $this->asOperator();

        $ids = array_column($this->get('/plataforma/organizaciones?estado=suspended')->viewData('page')['props']['rows'], 'id');

        $this->assertSame([$suspended->getKey()], $ids);
        $this->assertNotContains($active->getKey(), $ids);
    }

    public function test_queries_do_not_grow_with_the_page(): void
    {
        $this->asOperator();
        $this->businessWithOwner();
        $few = $this->countQueries(fn () => $this->get('/plataforma/organizaciones?q=x'.uniqid()));

        foreach (range(1, 5) as $_) {
            $this->businessWithOwner();
        }

        $many = $this->countQueries(fn () => $this->get('/plataforma/organizaciones?pagina=1&q='));

        $this->assertLessThanOrEqual($few + 10, $many);
        $this->assertLessThan(40, $many);
    }

    private function countQueries(\Closure $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
