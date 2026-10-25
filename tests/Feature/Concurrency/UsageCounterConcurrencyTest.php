<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Actions\Organization\DisableTeamMemberAction;
use App\Domain\Subscription\UsageCalculator;
use App\Domain\Subscription\UsageMetric;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class UsageCounterConcurrencyTest extends TestCase
{
    private const SLUG_PREFIX = 'concurrencia-consumo-';

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
        $this->releaseRival();
        $this->restoreLockWaitTimeout();
        $this->purgeFixtures();

        parent::tearDown();
    }

    public function test_two_simultaneous_disables_free_one_slot(): void
    {
        [$organization, $owner, $member, $counterId] = $this->seedTeamOfTwo();

        $this->actingAsOrganization($organization);

        $rival = $this->rival();
        $rival->beginTransaction();
        $rival->select('select id from usage_counters where id = ? for update', [$counterId]);
        $rival->select('select id from users where id = ? for update', [$member->getKey()]);
        $rival->update('update users set disabled_at = now() where id = ?', [$member->getKey()]);
        $rival->update('update usage_counters set value = value - 1 where id = ?', [$counterId]);

        $this->shortenLockWaitTimeout();

        $waited = false;

        try {
            app(DisableTeamMemberAction::class)->handle($member, $owner);
        } catch (QueryException $exception) {
            $waited = str_contains($exception->getMessage(), 'Lock wait timeout');
        }

        $this->assertTrue($waited, 'La baja no esperó a la transacción que tenía el contador y la fila del miembro.');

        $rival->commit();

        app(DisableTeamMemberAction::class)->handle($member, $owner);

        $this->assertSame(1, $this->counterValue($counterId), 'Dos bajas de la misma persona liberaron dos unidades.');
    }

    public function test_the_recalculation_waits_for_a_mutation_instead_of_overwriting_it(): void
    {
        [$organization, , , , $productsCounterId] = $this->seedTeamOfTwo();

        $rival = $this->rival();
        $rival->beginTransaction();
        $rival->select('select id from usage_counters where id = ? for update', [$productsCounterId]);
        $rival->insert(
            'insert into products (organization_id, sku, name, slug, price_cents, down_payment_cents, installments_count, frequency_days, stock, status, created_at, updated_at) '
            ."values (?, 'SKU-RIVAL', 'Producto rival', 'producto-rival', 10000, 1000, 3, 15, 1, 'draft', now(), now())",
            [$organization->getKey()],
        );
        $rival->update('update usage_counters set value = value + 1 where id = ?', [$productsCounterId]);

        $this->shortenLockWaitTimeout();

        $ran = [];
        DB::listen(function (QueryExecuted $query) use (&$ran): void {
            $ran[] = strtolower($query->sql);
        });

        $waited = false;

        try {
            app(UsageCalculator::class)->recalculate($organization, UsageMetric::Products);
        } catch (QueryException $exception) {
            $waited = str_contains($exception->getMessage(), 'Lock wait timeout')
                && str_contains(strtolower($exception->getSql()), 'usage_counters')
                && str_contains(strtolower($exception->getSql()), 'for update');
        }

        $this->assertTrue($waited, 'El recálculo no esperó, con FOR UPDATE, al contador que la mutación tenía cogido.');
        $this->assertSame([], array_values(array_filter($ran, fn (string $sql): bool => str_contains($sql, 'from `products`'))), 'El recálculo midió antes de coger el contador.');
        $this->assertSame(1, $this->counterValue($productsCounterId), 'El recálculo escribió sin tener el contador.');

        $rival->commit();

        $drifts = app(UsageCalculator::class)->recalculateMany([(int) $organization->getKey()], UsageMetric::Products);

        $this->assertSame([], $drifts);
        $this->assertSame(2, $this->counterValue($productsCounterId));
    }

    public function test_a_disable_with_nobody_else_frees_its_slot(): void
    {
        [$organization, $owner, $member, $counterId] = $this->seedTeamOfTwo();

        $this->actingAsOrganization($organization);
        $this->shortenLockWaitTimeout();

        app(DisableTeamMemberAction::class)->handle($member, $owner);

        $this->assertSame(1, $this->counterValue($counterId));
    }

    /**
     * @return array{0: Organization, 1: User, 2: User, 3: int, 4: int}
     */
    private function seedTeamOfTwo(): array
    {
        $plan = Plan::factory()->hidden()->create(['code' => self::SLUG_PREFIX.bin2hex(random_bytes(4))]);

        $neighbour = Organization::factory()->create(['slug' => self::SLUG_PREFIX.bin2hex(random_bytes(4))]);
        $this->withinOrganization($neighbour, function () use ($plan): void {
            Subscription::factory()->forPlan($plan)->create();
            User::factory()->count(2)->create();
            UsageCounter::factory()->forMetric(UsageMetric::Users, 2)->create();
        });

        $organization = Organization::factory()->create(['slug' => self::SLUG_PREFIX.bin2hex(random_bytes(4))]);

        /** @var array{0: User, 1: User, 2: int, 3: int} $seeded */
        $seeded = $this->withinOrganization($organization, function () use ($plan): array {
            Subscription::factory()->forPlan($plan)->create();

            $owner = User::factory()->owner()->create();
            $member = User::factory()->create();
            Product::factory()->create();

            return [
                $owner,
                $member,
                (int) UsageCounter::factory()->forMetric(UsageMetric::Users, 2)->create()->getKey(),
                (int) UsageCounter::factory()->forMetric(UsageMetric::Products, 1)->create()->getKey(),
            ];
        });

        $this->withoutOrganization();

        return [$organization, ...$seeded];
    }

    private function counterValue(int $counterId): int
    {
        return (int) DB::table('usage_counters')->where('id', $counterId)->value('value');
    }

    private function rival(): Connection
    {
        if ($this->rival instanceof Connection) {
            return $this->rival;
        }

        config(['database.connections.mysql_rival' => config('database.connections.mysql')]);

        return $this->rival = DB::connection('mysql_rival');
    }

    private function releaseRival(): void
    {
        if (! $this->rival instanceof Connection) {
            return;
        }

        if ($this->rival->transactionLevel() > 0) {
            $this->rival->rollBack();
        }

        $this->rival->disconnect();
        $this->rival = null;

        DB::purge('mysql_rival');
    }

    private function shortenLockWaitTimeout(): void
    {
        $this->originalLockWaitTimeout ??= (int) DB::selectOne('select @@innodb_lock_wait_timeout as value')->value;

        DB::statement('set session innodb_lock_wait_timeout = 1');
    }

    private function restoreLockWaitTimeout(): void
    {
        if ($this->originalLockWaitTimeout === null) {
            return;
        }

        DB::statement('set session innodb_lock_wait_timeout = '.$this->originalLockWaitTimeout);

        $this->originalLockWaitTimeout = null;
    }

    private function purgeFixtures(): void
    {
        $organizations = DB::table('organizations')->where('slug', 'like', self::SLUG_PREFIX.'%')->pluck('id');

        DB::table('activity_log')->whereIn('organization_id', $organizations)->delete();
        DB::table('products')->whereIn('organization_id', $organizations)->delete();
        DB::table('usage_counters')->whereIn('organization_id', $organizations)->delete();
        DB::table('subscriptions')->whereIn('organization_id', $organizations)->delete();
        DB::table('users')->whereIn('organization_id', $organizations)->delete();
        DB::table('organizations')->whereIn('id', $organizations)->delete();
        DB::table('plans')->where('code', 'like', self::SLUG_PREFIX.'%')->delete();
    }
}
