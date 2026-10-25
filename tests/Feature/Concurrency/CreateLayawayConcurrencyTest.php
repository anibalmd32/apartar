<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Actions\Customer\CustomerContactData;
use App\Actions\Layaway\CreateLayawayAction;
use App\Actions\Layaway\CreateLayawayData;
use App\Domain\Catalog\InsufficientStockException;
use App\Domain\Customer\TermsConsent;
use App\Domain\Subscription\UsageMetric;
use App\Models\Catalog;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Support\LayawayCode;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CreateLayawayConcurrencyTest extends TestCase
{
    private const SLUG_PREFIX = 'concurrencia-apartar-';

    private ?Connection $rival = null;

    private ?int $originalLockWaitTimeout = null;

    /** @var list<int> */
    private array $plans = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasColumn('layaways', 'idempotency_key')) {
            $this->artisan('migrate', ['--force' => true]);
        }

        $this->purgeFixtures();
    }

    protected function tearDown(): void
    {
        $this->releaseRival();
        $this->restoreLockWaitTimeout();

        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        $this->withoutOrganization();
        $this->purgeFixtures();

        parent::tearDown();
    }

    public function test_the_product_lock_serialises_two_layaways_of_the_last_unit(): void
    {
        [, $catalog, $product] = $this->seedCatalog(stock: 1);

        $rival = $this->rival();
        $rival->beginTransaction();
        $rival->select('select id from products where id = ? for update', [$product->getKey()]);
        $rival->update('update products set stock = 0 where id = ?', [$product->getKey()]);

        $this->shortenLockWaitTimeout();

        $waitedOn = null;

        try {
            $this->create($catalog, $product);
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'Lock wait timeout')) {
                $waitedOn = strtolower($exception->getSql());
            }
        }

        $this->assertNotNull($waitedOn, 'La creación no esperó al bloqueo del producto.');
        $this->assertStringContainsString('from `products`', (string) $waitedOn, 'Lo primero que esperó no fue el producto.');
        $this->assertStringContainsString('for update', (string) $waitedOn);
        $this->assertSame(0, DB::table('customers')->where('organization_id', $catalog->organization_id)->count(), 'Tocó la ficha antes que el producto.');

        $rival->commit();

        try {
            $this->create($catalog, $product);
            $this->fail('Se apartó dos veces la última unidad.');
        } catch (InsufficientStockException $exception) {
            $this->assertSame(0, $exception->available);
        }

        $this->assertSame(0, DB::table('layaways')->where('organization_id', $catalog->organization_id)->count());
    }

    public function test_two_submissions_with_the_same_key_and_another_product_leave_one_layaway(): void
    {
        [$organization, $catalog, $product, $other] = $this->seedCatalog(stock: 3);
        $key = (string) Str::uuid();

        DB::beginTransaction();
        $this->assertSame(0, DB::table('layaways')->where('idempotency_key', $key)->count());

        $winner = $this->rivalCreatesLayaway($organization, $other, $key);

        $layaway = $this->create($catalog, $product, $key);

        DB::commit();

        $this->assertSame($winner, $layaway->getKey(), 'No se devolvió el apartado que ganó.');
        $this->assertFalse($layaway->wasRecentlyCreated);
        $this->assertSame(1, DB::table('layaways')->where('idempotency_key', $key)->count());

        $this->assertSame(3, (int) DB::table('products')->where('id', $product->getKey())->value('stock'));
        $this->assertSame(0, DB::table('customers')->where('organization_id', $organization->getKey())->where('document_number', 'V12345678')->count());
        $this->assertSame(0, (int) DB::table('usage_counters')->where('organization_id', $organization->getKey())->where('metric', UsageMetric::ActiveLayaways->value)->value('value'));
    }

    public function test_a_double_click_on_the_last_unit_returns_the_same_layaway(): void
    {
        [$organization, $catalog, $product] = $this->seedCatalog(stock: 1);
        $key = (string) Str::uuid();

        $first = $this->rivalCreatesLayaway($organization, $product, $key, commit: false, takesTheUnit: true);
        $this->commitRivalRightAfterOurQuickLookup($key);

        $second = $this->create($catalog, $product, $key);

        $this->assertSame($first, $second->getKey(), 'El segundo clic no devolvió el apartado del primero.');
        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertSame(1, DB::table('layaways')->where('organization_id', $organization->getKey())->count());
        $this->assertSame(0, (int) DB::table('products')->where('id', $product->getKey())->value('stock'));
    }

    public function test_the_same_double_click_with_a_fresh_key_is_refused_for_stock(): void
    {
        [$organization, $catalog, $product] = $this->seedCatalog(stock: 1);

        $this->rivalCreatesLayaway($organization, $product, (string) Str::uuid(), commit: false, takesTheUnit: true);
        $fresh = (string) Str::uuid();
        $this->commitRivalRightAfterOurQuickLookup($fresh);

        $this->expectException(InsufficientStockException::class);

        $this->create($catalog, $product, $fresh);
    }

    private function commitRivalRightAfterOurQuickLookup(string $key): void
    {
        $committed = false;

        DB::listen(function ($query) use ($key, &$committed): void {
            if ($committed || $query->connectionName === 'mysql_rival') {
                return;
            }

            if (str_contains($query->sql, 'idempotency_key') && in_array($key, $query->bindings, true)) {
                $committed = true;
                $this->rival()->commit();
            }
        });
    }

    /**
     * @return array{0: Organization, 1: Catalog, 2: Product, 3: Product}
     */
    private function seedCatalog(int $stock): array
    {
        $organization = Organization::factory()->create(['slug' => self::SLUG_PREFIX.bin2hex(random_bytes(4))]);
        $plan = Plan::factory()->create(['max_active_layaways' => 10]);
        $this->plans[] = (int) $plan->getKey();

        [$catalog, $product, $other] = $this->withinOrganization($organization, function () use ($plan, $stock): array {
            Subscription::factory()->forPlan($plan)->create();

            $catalog = Catalog::factory()->published()->create(['slug' => self::SLUG_PREFIX.bin2hex(random_bytes(4))]);
            $product = Product::factory()->published()->create(['stock' => $stock]);
            $other = Product::factory()->published()->create(['stock' => $stock]);
            $catalog->products()->attach($product, ['position' => 1]);
            $catalog->products()->attach($other, ['position' => 2]);

            return [$catalog, $product, $other];
        });

        $this->withoutOrganization();

        return [$organization, $catalog, $product, $other];
    }

    private function create(Catalog $catalog, Product $product, ?string $key = null): Layaway
    {
        return app(CreateLayawayAction::class)->handle(new CreateLayawayData(
            catalog: $catalog,
            product: $product,
            quantity: 1,
            contact: CustomerContactData::fromInput('V12345678', 'María González', '0414-1234567', null),
            consent: TermsConsent::acceptedNowFrom('190.202.10.5'),
            idempotencyKey: $key ?? (string) Str::uuid(),
        ));
    }

    private function rivalCreatesLayaway(Organization $organization, Product $product, string $key, bool $commit = true, bool $takesTheUnit = false): int
    {
        $rival = $this->rival();
        $rival->beginTransaction();

        if ($takesTheUnit) {
            $rival->select('select id from products where id = ? for update', [$product->getKey()]);
            $rival->update('update products set stock = stock - 1 where id = ?', [$product->getKey()]);
        }

        $customerId = (int) $rival->table('customers')->insertGetId([
            'organization_id' => $organization->getKey(),
            'document_number' => 'V87654321',
            'document_kind' => 'cedula',
            'full_name' => 'El otro envío',
            'phone' => '+584149998877',
            'terms_accepted_at' => now(),
            'terms_ip_address' => '186.14.0.9',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = (int) $rival->table('layaways')->insertGetId([
            'organization_id' => $organization->getKey(),
            'code' => LayawayCode::generate(),
            'idempotency_key' => $key,
            'customer_id' => $customerId,
            'product_id' => $product->getKey(),
            'quantity' => 1,
            'unit_price_cents' => 25_000,
            'down_payment_cents' => 5_000,
            'installments_count' => 4,
            'frequency_days' => 15,
            'product_name_snapshot' => $product->name,
            'product_sku_snapshot' => $product->sku,
            'total_cents' => 25_000,
            'paid_cents' => 0,
            'remaining_cents' => 25_000,
            'status' => 'pending_down_payment',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($commit) {
            $rival->commit();
        }

        return $id;
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
        $organizations = DB::table('organizations')
            ->where('slug', 'like', self::SLUG_PREFIX.'%')
            ->pluck('id');

        DB::table('layaways')->whereIn('organization_id', $organizations)->delete();
        DB::table('customers')->whereIn('organization_id', $organizations)->delete();
        DB::table('catalog_product')->whereIn('catalog_id', DB::table('catalogs')->whereIn('organization_id', $organizations)->pluck('id'))->delete();
        DB::table('catalogs')->whereIn('organization_id', $organizations)->delete();
        DB::table('products')->whereIn('organization_id', $organizations)->delete();
        DB::table('usage_counters')->whereIn('organization_id', $organizations)->delete();
        DB::table('subscriptions')->whereIn('organization_id', $organizations)->delete();
        DB::table('activity_log')->whereIn('organization_id', $organizations)->delete();
        DB::table('organizations')->whereIn('id', $organizations)->delete();

        if ($this->plans !== []) {
            DB::table('plans')->whereIn('id', $this->plans)->delete();
            $this->plans = [];
        }
    }
}
