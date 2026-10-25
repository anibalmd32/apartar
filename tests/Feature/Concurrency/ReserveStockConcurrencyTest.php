<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Actions\Layaway\ReserveStockAction;
use App\Domain\Catalog\InsufficientStockException;
use App\Models\Organization;
use App\Models\Product;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

final class ReserveStockConcurrencyTest extends TestCase
{
    private const SLUG_PREFIX = 'concurrencia-stock-';

    private ?Connection $rival = null;

    private ?int $originalLockWaitTimeout = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasColumn('products', 'stock_shortfall')) {
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

    public function test_two_reservations_of_the_last_unit_are_serialised_by_the_row_lock(): void
    {
        [$organization, $product] = $this->seedLastUnit();

        $rival = $this->rival();
        $rival->beginTransaction();
        $rival->select('select id from products where id = ? for update', [$product->getKey()]);
        $rival->update('update products set stock = 0 where id = ?', [$product->getKey()]);

        $this->shortenLockWaitTimeout();

        $waitedOn = null;

        try {
            $this->reserve($organization, $product);
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'Lock wait timeout')) {
                $waitedOn = $exception->getSql();
            }
        }

        $this->assertNotNull($waitedOn, 'La reserva no esperó al bloqueo de la otra.');
        $this->assertStringContainsStringIgnoringCase(
            'for update',
            (string) $waitedOn,
            'La reserva esperó al escribir, no al leer: comprobó el stock sin FOR UPDATE, sobre un dato que otra transacción ya había cambiado.',
        );

        $rival->commit();

        try {
            $this->reserve($organization, $product);
            $this->fail('Se reservó dos veces la última unidad.');
        } catch (InsufficientStockException $exception) {
            $this->assertSame(0, $exception->available);
        }

        $this->assertSame(0, (int) DB::table('products')->where('id', $product->getKey())->value('stock'));
    }

    public function test_the_same_reservation_succeeds_when_nobody_else_holds_the_lock(): void
    {
        [$organization, $product] = $this->seedLastUnit();
        $this->shortenLockWaitTimeout();

        $this->reserve($organization, $product);

        $this->assertSame(0, (int) DB::table('products')->where('id', $product->getKey())->value('stock'));
    }

    public function test_reserving_outside_a_transaction_throws(): void
    {
        [$organization, $product] = $this->seedLastUnit();

        try {
            $this->withinOrganization($organization, fn () => app(ReserveStockAction::class)->handle($product, 1));
            $this->fail('Se reservó fuera de una transacción.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('transacción', $exception->getMessage());
        }

        $this->assertSame(1, (int) DB::table('products')->where('id', $product->getKey())->value('stock'));
    }

    /**
     * @return array{0: Organization, 1: Product}
     */
    private function seedLastUnit(): array
    {
        $organization = Organization::factory()->create(['slug' => self::SLUG_PREFIX.bin2hex(random_bytes(4))]);

        $neighbour = Organization::factory()->create(['slug' => self::SLUG_PREFIX.bin2hex(random_bytes(4))]);
        $this->withinOrganization($neighbour, fn () => Product::factory()->create(['stock' => 1]));

        $product = $this->withinOrganization($organization, fn (): Product => Product::factory()->create(['stock' => 1]));
        $this->withoutOrganization();

        return [$organization, $product];
    }

    private function reserve(Organization $organization, Product $product): void
    {
        $this->withinOrganization($organization, fn () => DB::transaction(
            fn () => app(ReserveStockAction::class)->handle($product, 1),
        ));
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

        DB::table('products')->whereIn('organization_id', $organizations)->delete();
        DB::table('activity_log')->whereIn('organization_id', $organizations)->delete();
        DB::table('organizations')->whereIn('id', $organizations)->delete();
    }
}
