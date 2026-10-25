<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Actions\Layaway\ReconcileLayawaysAction;
use App\Domain\Layaway\ReconciliationReport;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

final class ReconcileLayawaysConcurrencyTest extends TestCase
{
    use CreatesLayaways;

    private const SLUG_PREFIX = 'concurrencia-reconciliacion-';

    private ?Connection $rival = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purgeFixtures();
    }

    protected function tearDown(): void
    {
        $this->releaseRival();

        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        $this->withoutOrganization();
        $this->purgeFixtures();

        parent::tearDown();
    }

    public function test_a_locked_layaway_is_skipped_and_fixed_on_the_next_pass(): void
    {
        $organization = Organization::factory()->create(['slug' => self::SLUG_PREFIX.bin2hex(random_bytes(4))]);
        $layaway = $this->layawayIn($organization);
        $this->withoutOrganization();
        DB::table('layaways')->where('id', $layaway->getKey())->update(['remaining_cents' => 1]);

        $rival = $this->rival();
        $rival->beginTransaction();
        $rival->select('select id from layaways where id = ? for update', [$layaway->getKey()]);

        $started = microtime(true);
        $skipped = $this->reconcile($organization);

        $this->assertSame(['skipped'], array_column($skipped->entries(), 'outcome'));
        $this->assertLessThan(2.0, microtime(true) - $started, 'Esperó al bloqueo en vez de saltarlo.');
        $this->assertSame(1, (int) DB::table('layaways')->where('id', $layaway->getKey())->value('remaining_cents'));

        $rival->commit();

        $this->assertSame(['fixed'], array_column($this->reconcile($organization)->entries(), 'outcome'));
        $this->assertSame(20_000, (int) DB::table('layaways')->where('id', $layaway->getKey())->value('remaining_cents'));
    }

    public function test_a_locked_product_is_skipped_and_fixed_on_the_next_pass(): void
    {
        $organization = Organization::factory()->create(['slug' => self::SLUG_PREFIX.bin2hex(random_bytes(4))]);
        $layaway = $this->layawayIn($organization);
        $this->withoutOrganization();
        DB::table('products')->where('id', $layaway->product_id)->update(['stock' => 0, 'stock_shortfall' => 3]);

        $rival = $this->rival();
        $rival->beginTransaction();
        $rival->select('select id from products where id = ? for update', [$layaway->product_id]);

        $started = microtime(true);
        $this->assertSame(['skipped'], array_column($this->reconcile($organization)->entries(), 'outcome'));
        $this->assertLessThan(2.0, microtime(true) - $started, 'Esperó al bloqueo en vez de saltarlo.');

        $rival->commit();

        $this->assertSame(['fixed'], array_column($this->reconcile($organization)->entries(), 'outcome'));
        $this->assertSame(1, (int) DB::table('products')->where('id', $layaway->product_id)->value('stock_shortfall'));
    }

    private function reconcile(Organization $organization): ReconciliationReport
    {
        return $this->withinOrganization($organization, fn (): ReconciliationReport => app(ReconcileLayawaysAction::class)->handle(true, CarbonImmutable::now()));
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

    private function purgeFixtures(): void
    {
        $organizations = DB::table('organizations')->where('slug', 'like', self::SLUG_PREFIX.'%')->pluck('id');

        DB::table('activity_log')->whereIn('organization_id', $organizations)->delete();
        DB::table('installments')->whereIn('layaway_id', DB::table('layaways')->whereIn('organization_id', $organizations)->select('id'))->delete();
        DB::table('layaways')->whereIn('organization_id', $organizations)->delete();
        DB::table('customers')->whereIn('organization_id', $organizations)->delete();
        DB::table('products')->whereIn('organization_id', $organizations)->delete();
        DB::table('usage_counters')->whereIn('organization_id', $organizations)->delete();
        DB::table('organization_settings')->whereIn('organization_id', $organizations)->delete();
        DB::table('organizations')->whereIn('id', $organizations)->delete();
    }
}
