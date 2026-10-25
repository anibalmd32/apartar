<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Actions\Catalog\PublishCatalogAction;
use App\Actions\Catalog\PublishCatalogData;
use App\Domain\Subscription\PlanLimitReachedException;
use App\Domain\Subscription\UsageMetric;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PublishCatalogConcurrencyTest extends TestCase
{
    private const SLUG_PREFIX = 'concurrencia-publicar-';

    private ?Connection $rival = null;

    private ?int $originalLockWaitTimeout = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('usage_counters')) {
            $this->artisan('migrate', ['--force' => true]);
        }

        $this->purgeFixtures();
    }

    protected function tearDown(): void
    {
        if ($this->rival instanceof Connection) {
            if ($this->rival->transactionLevel() > 0) {
                $this->rival->rollBack();
            }

            $this->rival->disconnect();
            $this->rival = null;
            DB::purge('mysql_rival');
        }

        if ($this->originalLockWaitTimeout !== null) {
            DB::statement('set session innodb_lock_wait_timeout = '.$this->originalLockWaitTimeout);
            $this->originalLockWaitTimeout = null;
        }

        $this->purgeFixtures();

        parent::tearDown();
    }

    public function test_two_simultaneous_publications_with_one_slot_leave_one_out(): void
    {
        [$organization, $owner, $ours, $theirs, $counterId] = $this->seedTwoDraftsAndOneSlot();

        $this->actingAsOrganization($organization);

        config(['database.connections.mysql_rival' => config('database.connections.mysql')]);
        $this->rival = DB::connection('mysql_rival');
        $this->rival->beginTransaction();
        $this->rival->select('select id from usage_counters where id = ? for update', [$counterId]);
        $this->rival->update('update catalogs set published_at = now() where id = ?', [$theirs->getKey()]);
        $this->rival->update('update usage_counters set value = 1 where id = ?', [$counterId]);

        $this->originalLockWaitTimeout = (int) DB::selectOne('select @@innodb_lock_wait_timeout as value')->value;
        DB::statement('set session innodb_lock_wait_timeout = 1');

        $waited = false;

        try {
            $this->publish($ours, $owner);
        } catch (QueryException $exception) {
            $waited = str_contains($exception->getMessage(), 'Lock wait timeout');
        }

        $this->assertTrue($waited, 'La publicación no esperó al contador que la otra tenía cogido.');
        $this->assertNull($ours->refresh()->published_at, 'La publicación bloqueada dejó a medias su escritura.');

        $this->rival->commit();

        try {
            $this->publish($ours->refresh(), $owner);
            $this->fail('La segunda publicación debió fallar: habría superado el cupo del plan.');
        } catch (PlanLimitReachedException $exception) {
            $this->assertSame(UsageMetric::Catalogs, $exception->metric);
            $this->assertSame(1, $exception->used);
            $this->assertSame(1, $exception->limit);
        }

        $this->assertSame(1, (int) DB::table('catalogs')->where('organization_id', $organization->getKey())->whereNotNull('published_at')->count());
    }

    public function test_the_same_publication_succeeds_when_nobody_else_holds_the_counter(): void
    {
        [$organization, $owner, $ours] = $this->seedTwoDraftsAndOneSlot();

        $this->actingAsOrganization($organization);

        $this->publish($ours, $owner);

        $this->assertNotNull($ours->refresh()->published_at);
    }

    /**
     * @return array{0: Organization, 1: User, 2: Catalog, 3: Catalog, 4: int}
     */
    private function seedTwoDraftsAndOneSlot(): array
    {
        $plan = Plan::factory()->hidden()->create(['code' => self::SLUG_PREFIX.bin2hex(random_bytes(4)), 'max_catalogs' => 1]);
        $organization = Organization::factory()->create(['slug' => self::SLUG_PREFIX.bin2hex(random_bytes(4))]);

        /** @var array{0: User, 1: Catalog, 2: Catalog, 3: int} $seeded */
        $seeded = $this->withinOrganization($organization, function () use ($plan): array {
            Subscription::factory()->forPlan($plan)->create();
            $owner = User::factory()->owner()->create();
            PaymentAccount::factory()->create();

            $catalogs = [];

            foreach (['nuestro', 'suyo'] as $name) {
                $catalog = Catalog::factory()->create(['slug' => self::SLUG_PREFIX.$name.'-'.bin2hex(random_bytes(3))]);
                $catalog->products()->attach(Product::factory()->published()->create(), ['position' => 1]);
                $catalogs[] = $catalog;
            }

            return [$owner, $catalogs[0], $catalogs[1], (int) UsageCounter::factory()->forMetric(UsageMetric::Catalogs)->create()->getKey()];
        });

        $this->withoutOrganization();

        return [$organization, ...$seeded];
    }

    private function publish(Catalog $catalog, User $owner): void
    {
        app(PublishCatalogAction::class)->handle($catalog, new PublishCatalogData(isListed: true, expiresAt: null), $owner);
    }

    private function purgeFixtures(): void
    {
        $organizations = DB::table('organizations')->where('slug', 'like', self::SLUG_PREFIX.'%')->pluck('id');

        DB::table('activity_log')->whereIn('organization_id', $organizations)->delete();
        DB::table('catalog_product')->whereIn('catalog_id', DB::table('catalogs')->whereIn('organization_id', $organizations)->pluck('id'))->delete();
        DB::table('catalogs')->whereIn('organization_id', $organizations)->delete();
        DB::table('products')->whereIn('organization_id', $organizations)->delete();
        DB::table('payment_accounts')->whereIn('organization_id', $organizations)->delete();
        DB::table('usage_counters')->whereIn('organization_id', $organizations)->delete();
        DB::table('subscriptions')->whereIn('organization_id', $organizations)->delete();
        DB::table('users')->whereIn('organization_id', $organizations)->delete();
        DB::table('organizations')->whereIn('id', $organizations)->delete();
        DB::table('plans')->where('code', 'like', self::SLUG_PREFIX.'%')->delete();
    }
}
