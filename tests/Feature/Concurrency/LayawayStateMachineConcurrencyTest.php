<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Domain\Layaway\IllegalLayawayTransitionException;
use App\Domain\Layaway\LayawayStateMachine;
use App\Domain\Layaway\LayawayStatus;
use App\Models\Layaway;
use App\Models\Organization;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

final class LayawayStateMachineConcurrencyTest extends TestCase
{
    use CreatesLayaways;

    private const SLUG_PREFIX = 'concurrencia-apartado-';

    private ?Connection $rival = null;

    private ?int $originalLockWaitTimeout = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('layaways')) {
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

    public function test_two_simultaneous_transitions_are_serialised_by_the_row_lock(): void
    {
        [$organization, $layaway] = $this->seedPendingLayaway();

        $rival = $this->rival();
        $rival->beginTransaction();
        $rival->select('select id from layaways where id = ? for update', [$layaway->getKey()]);

        $this->shortenLockWaitTimeout();

        $waited = false;

        try {
            $this->activate($organization, $layaway);
        } catch (QueryException $exception) {
            $waited = str_contains($exception->getMessage(), 'Lock wait timeout');
        }

        $this->assertTrue($waited, 'La transición no esperó al bloqueo de la otra: valida sin FOR UPDATE.');
        $this->assertSame('pending_down_payment', $this->statusOf($layaway), 'La transición bloqueada dejó algo escrito.');

        $rival->update("update layaways set status = 'expired' where id = ?", [$layaway->getKey()]);
        $rival->commit();

        try {
            $this->activate($organization, $layaway);
            $this->fail('Se activó un apartado que otra transacción acababa de expirar.');
        } catch (IllegalLayawayTransitionException $exception) {
            $this->assertSame(LayawayStatus::Expired, $exception->from);
        }

        $this->assertSame('expired', $this->statusOf($layaway));
        $this->assertNull(DB::table('layaways')->where('id', $layaway->getKey())->value('activated_at'));
    }

    public function test_the_same_transition_succeeds_when_nobody_else_holds_the_lock(): void
    {
        [$organization, $layaway] = $this->seedPendingLayaway();
        $this->shortenLockWaitTimeout();

        $this->activate($organization, $layaway);

        $this->assertSame('active', $this->statusOf($layaway));
    }

    /**
     * @return array{0: Organization, 1: Layaway}
     */
    private function seedPendingLayaway(): array
    {
        $organization = Organization::factory()->create(['slug' => self::SLUG_PREFIX.bin2hex(random_bytes(4))]);

        $neighbour = Organization::factory()->create(['slug' => self::SLUG_PREFIX.bin2hex(random_bytes(4))]);
        $this->layawayIn($neighbour);

        $layaway = $this->layawayIn($organization);
        $this->withoutOrganization();

        return [$organization, $layaway];
    }

    private function activate(Organization $organization, Layaway $layaway): void
    {
        $this->withinOrganization($organization, fn () => app(LayawayStateMachine::class)->transition($layaway, LayawayStatus::Active));
    }

    private function statusOf(Layaway $layaway): ?string
    {
        $status = DB::table('layaways')->where('id', $layaway->getKey())->value('status');

        return is_string($status) ? $status : null;
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
        DB::table('products')->whereIn('organization_id', $organizations)->delete();
        DB::table('usage_counters')->whereIn('organization_id', $organizations)->delete();
        DB::table('activity_log')->whereIn('organization_id', $organizations)->delete();
        DB::table('organizations')->whereIn('id', $organizations)->delete();
    }
}
