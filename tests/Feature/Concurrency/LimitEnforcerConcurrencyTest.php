<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Actions\Organization\InviteTeamMemberAction;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanLimitReachedException;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationRole;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

final class LimitEnforcerConcurrencyTest extends TestCase
{
    private const SLUG_PREFIX = 'concurrencia-cupo-';

    private const MAX_USERS = 2;

    private ?Connection $rival = null;

    private ?int $originalLockWaitTimeout = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('usage_counters')) {
            $this->artisan('migrate', ['--force' => true]);
        }

        Notification::fake();

        $this->purgeFixtures();
    }

    protected function tearDown(): void
    {
        $this->releaseRival();
        $this->restoreLockWaitTimeout();
        $this->purgeFixtures();

        parent::tearDown();
    }

    public function test_two_simultaneous_invitations_with_one_slot_leave_one_out(): void
    {
        [$organization, $owner, $counterId] = $this->seedOrganizationWithOneFreeSlot();

        $this->actingAsOrganization($organization);

        $this->rivalLocksTheCounter($counterId);

        $this->shortenLockWaitTimeout();

        $waited = false;

        try {
            $this->invite($owner, 'primera@ejemplo.test');
        } catch (QueryException $exception) {
            $waited = $this->isLockWaitTimeout($exception);
        }

        $this->assertTrue($waited, 'La comprobación de cupo no esperó al bloqueo de la otra transacción.');
        $this->assertSame(1, $this->userCount($organization), 'La invitación bloqueada dejó a medias su escritura.');

        $this->rivalInvites($organization, $counterId);

        try {
            $this->invite($owner, 'segunda@ejemplo.test');
            $this->fail('La segunda invitación debió fallar: habría superado el techo del plan.');
        } catch (PlanLimitReachedException $exception) {
            $this->assertSame(UsageMetric::Users, $exception->metric);
            $this->assertSame(self::MAX_USERS, $exception->used);
            $this->assertSame(self::MAX_USERS, $exception->limit);
        }

        $this->assertSame(self::MAX_USERS, $this->userCount($organization), 'El plan admitió a uno de más.');
    }

    public function test_the_same_invitation_succeeds_when_nobody_else_holds_the_lock(): void
    {
        [$organization, $owner] = $this->seedOrganizationWithOneFreeSlot();

        $this->actingAsOrganization($organization);
        $this->shortenLockWaitTimeout();

        $this->invite($owner, 'sola@ejemplo.test');

        $this->assertSame(2, $this->userCount($organization));
    }

    public function test_checking_a_limit_outside_a_transaction_throws(): void
    {
        [$organization, $owner] = $this->seedOrganizationWithOneFreeSlot();

        $this->actingAsOrganization($organization);

        $this->assertSame(0, DB::transactionLevel(), 'El escenario ya venía dentro de una transacción.');

        try {
            app(LimitEnforcer::class)->assertCanCreate(UsageMetric::Users);
            $this->fail('Comprobar un límite sin transacción debió lanzar.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('fuera de una transacción', $exception->getMessage());
        }

        $this->invite($owner, 'con-transaccion@ejemplo.test');

        $this->assertSame(2, $this->userCount($organization));
    }

    /**
     * @return array{0: Organization, 1: User, 2: int}
     */
    private function seedOrganizationWithOneFreeSlot(): array
    {
        $plan = Plan::factory()->create([
            'code' => self::SLUG_PREFIX.bin2hex(random_bytes(4)),
            'name' => 'Dos usuarios',
            'position' => 1,
            'max_users' => self::MAX_USERS,
        ]);

        $organization = Organization::factory()->create([
            'slug' => self::SLUG_PREFIX.bin2hex(random_bytes(4)),
        ]);

        $neighbour = Organization::factory()->create([
            'slug' => self::SLUG_PREFIX.bin2hex(random_bytes(4)),
        ]);

        $this->withinOrganization($neighbour, function () use ($plan): void {
            Subscription::factory()->forPlan($plan)->create();
            User::factory()->count(2)->create();
            UsageCounter::factory()->forMetric(UsageMetric::Users, 2)->create();
        });

        /** @var array{0: User, 1: int} $seeded */
        $seeded = $this->withinOrganization($organization, function () use ($plan): array {
            Subscription::factory()->forPlan($plan)->create();

            $owner = User::factory()->owner()->create();
            $counter = UsageCounter::factory()->forMetric(UsageMetric::Users, 1)->create();

            return [$owner, (int) $counter->getKey()];
        });

        $this->withoutOrganization();

        return [$organization, $seeded[0], $seeded[1]];
    }

    private function invite(User $owner, string $email): void
    {
        app(InviteTeamMemberAction::class)->handle('Invitado', $email, OrganizationRole::Member, $owner);
    }

    private function userCount(Organization $organization): int
    {
        return (int) DB::table('users')
            ->where('organization_id', $organization->getKey())
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

    private function rivalLocksTheCounter(int $counterId): void
    {
        $rival = $this->rival();

        $rival->beginTransaction();
        $rival->select('select id from usage_counters where id = ? for update', [$counterId]);
    }

    private function rivalInvites(Organization $organization, int $counterId): void
    {
        $rival = $this->rival();

        $rival->insert(
            'insert into users (organization_id, name, email, role, must_change_password, invited_at, created_at, updated_at) '
            .'values (?, ?, ?, ?, 0, now(), now(), now())',
            [$organization->getKey(), 'Rival', 'rival@ejemplo.test', OrganizationRole::Member->value],
        );

        $rival->update('update usage_counters set value = 2 where id = ?', [$counterId]);

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
        $organizations = DB::table('organizations')->where('slug', 'like', self::SLUG_PREFIX.'%')->pluck('id');

        DB::table('activity_log')->whereIn('organization_id', $organizations)->delete();

        DB::table('organizations')->whereIn('id', $organizations)->delete();
        DB::table('plans')->where('code', 'like', self::SLUG_PREFIX.'%')->delete();
    }
}
