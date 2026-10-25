<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Domain\Tenancy\LastOwnerException;
use App\Domain\Tenancy\LastOwnerGuard;
use App\Domain\Tenancy\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class LastOwnerConcurrencyTest extends TestCase
{
    private const SLUG_PREFIX = 'concurrencia-owner-';

    private ?Connection $rival = null;

    private ?int $originalLockWaitTimeout = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('users')) {
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

    public function test_two_simultaneous_demotions_leave_one_owner(): void
    {
        [$organization, $ours, $theirs] = $this->seedOrganizationWithTwoOwners();

        $this->actingAsOrganization($organization);

        $this->rivalLocksItsOwnRow($theirs);

        $this->shortenLockWaitTimeout();

        $waited = false;

        try {
            $this->demote($ours);
        } catch (QueryException $exception) {
            $waited = $this->isLockWaitTimeout($exception);
        }

        $this->assertTrue($waited, 'La guarda no esperó al bloqueo de la otra transacción.');
        $this->assertSame(
            OrganizationRole::Owner,
            $ours->refresh()->role,
            'La degradación bloqueada dejó a medias su escritura.',
        );

        $this->rivalDemotes($theirs);

        try {
            $this->demote($ours);
            $this->fail('La segunda degradación debió fallar: habría dejado la organización sin owner.');
        } catch (LastOwnerException $exception) {
            $this->assertStringContainsString('último owner', $exception->getMessage());
        }

        $this->assertSame(1, $this->activeOwnerCount($organization), 'La organización se quedó sin owner.');
        $this->assertSame(OrganizationRole::Owner, $ours->refresh()->role);
    }

    public function test_the_same_demotion_succeeds_when_nobody_else_holds_the_lock(): void
    {
        [$organization, $ours] = $this->seedOrganizationWithTwoOwners();

        $this->actingAsOrganization($organization);
        $this->shortenLockWaitTimeout();

        $this->demote($ours);

        $this->assertSame(OrganizationRole::Member, $ours->refresh()->role);
        $this->assertSame(1, $this->activeOwnerCount($organization));
    }

    /**
     * @return array{0: Organization, 1: User, 2: User}
     */
    private function seedOrganizationWithTwoOwners(): array
    {
        $organization = Organization::factory()->create([
            'slug' => self::SLUG_PREFIX.bin2hex(random_bytes(4)),
        ]);

        $neighbour = Organization::factory()->create([
            'slug' => self::SLUG_PREFIX.bin2hex(random_bytes(4)),
        ]);

        $this->withinOrganization($neighbour, fn (): User => User::factory()->owner()->create());

        /** @var array{0: User, 1: User} $owners */
        $owners = $this->withinOrganization($organization, fn (): array => [
            User::factory()->owner()->create(),
            User::factory()->owner()->create(),
        ]);

        $this->withoutOrganization();

        return [$organization, $owners[0], $owners[1]];
    }

    private function demote(User $user): void
    {
        app(LastOwnerGuard::class)->whileKeepingAnOwner(
            $user,
            'degradar',
            fn () => $user->update(['role' => OrganizationRole::Member]),
        );
    }

    private function activeOwnerCount(Organization $organization): int
    {
        return (int) DB::table('users')
            ->where('organization_id', $organization->getKey())
            ->where('role', OrganizationRole::Owner->value)
            ->whereNull('disabled_at')
            ->count();
    }

    private function rival(): Connection
    {
        if ($this->rival instanceof Connection) {
            return $this->rival;
        }

        config(['database.connections.mysql_rival' => config('database.connections.mysql')]);

        return $this->rival = DB::connection('mysql_rival');
    }

    private function rivalLocksItsOwnRow(User $theirs): void
    {
        $rival = $this->rival();

        $rival->beginTransaction();
        $rival->select('select id from users where id = ? for update', [$theirs->getKey()]);
    }

    private function rivalDemotes(User $user): void
    {
        $rival = $this->rival();

        $rival->update('update users set role = ? where id = ?', [
            OrganizationRole::Member->value,
            $user->getKey(),
        ]);

        $rival->commit();
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

    private function isLockWaitTimeout(QueryException $exception): bool
    {
        return str_contains($exception->getMessage(), 'Lock wait timeout');
    }

    private function purgeFixtures(): void
    {
        $organizations = DB::table('organizations')
            ->where('slug', 'like', self::SLUG_PREFIX.'%')
            ->pluck('id');

        DB::table('activity_log')->whereIn('organization_id', $organizations)->delete();

        DB::table('organizations')->whereIn('id', $organizations)->delete();
    }
}
