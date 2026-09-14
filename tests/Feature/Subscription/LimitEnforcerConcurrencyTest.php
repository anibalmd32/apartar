<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

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

/**
 * Dos invitaciones simultáneas con **un solo cupo** (`REQ-SUB-004` RB-2 y
 * SP-1, `REQ-ORG-004` RB-2).
 *
 * Es el caso que justifica que `assertCanCreate()` lea el contador bloqueado y
 * dentro de la transacción, y el único que un test de un solo hilo no ve: sin
 * el bloqueo, las dos peticiones leen «queda uno», las dos concluyen que
 * pueden, y la organización acaba con un usuario más de los que su plan
 * admite. De ahí no se sale solo —no hay a quién expulsar, porque
 * `REQ-SUB-008` RB-4 prohíbe expulsar— así que prevenirlo es lo único que
 * funciona.
 *
 * **Cómo se prueba sin dos procesos**, con la misma receta que
 * `LastOwnerConcurrencyTest` (`T-009`): dos conexiones a MySQL y el orden
 * forzado a mano, que es más determinista que lanzar hilos y esperar a que
 * choquen.
 *
 *  1. la conexión rival abre transacción y bloquea **la fila del contador de
 *     `users`**, que es exactamente lo que coge la otra petición al comprobar
 *     su cupo;
 *  2. nuestra invitación ejecuta la acción de verdad y **se queda esperando**;
 *     con `innodb_lock_wait_timeout` a un segundo, esa espera se convierte en
 *     un error que el test puede ver. Que llegue a esperar es la prueba de que
 *     el bloqueo serializa: una comprobación que solo contase filas de `users`
 *     pasaría de largo, porque nadie tiene cogida ninguna;
 *  3. la rival completa su invitación y libera;
 *  4. la nuestra lo intenta otra vez, lee el estado de verdad —dos de dos— y
 *     falla con `PlanLimitReachedException`.
 *
 * Al final hay exactamente dos usuarios, que es el techo del plan.
 *
 * **Sin `RefreshDatabase`, a propósito.** Un bloqueo entre dos conexiones solo
 * es observable sobre filas confirmadas; dentro de la transacción de test la
 * segunda conexión no vería siquiera la organización. A cambio, este archivo
 * barre lo que siembra en `setUp` y en `tearDown`.
 */
final class LimitEnforcerConcurrencyTest extends TestCase
{
    /**
     * Marca de las organizaciones de este archivo, para poder barrerlas.
     */
    private const SLUG_PREFIX = 'concurrencia-cupo-';

    /**
     * Techo del plan del escenario: el `owner` más un sitio libre.
     */
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

    /**
     * El escenario entero: el bloqueo serializa y el techo aguanta.
     */
    public function test_two_simultaneous_invitations_with_one_slot_leave_one_out(): void
    {
        [$organization, $owner, $counterId] = $this->seedOrganizationWithOneFreeSlot();

        $this->actingAsOrganization($organization);

        // (1) La otra petición llega primero y coge el contador para comprobar
        //     su cupo.
        $this->rivalLocksTheCounter($counterId);

        // (2) La nuestra ejecuta la acción de verdad y se queda esperando ahí.
        $this->shortenLockWaitTimeout();

        $waited = false;

        try {
            $this->invite($owner, 'primera@ejemplo.test');
        } catch (QueryException $exception) {
            $waited = $this->isLockWaitTimeout($exception);
        }

        $this->assertTrue($waited, 'La comprobación de cupo no esperó al bloqueo de la otra transacción.');
        $this->assertSame(1, $this->userCount($organization), 'La invitación bloqueada dejó a medias su escritura.');

        // (3) La otra termina su invitación y libera.
        $this->rivalInvites($organization, $counterId);

        // (4) Y ahora la nuestra lee el estado de verdad: dos de dos.
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

    /**
     * El control que evita que lo anterior pase por el motivo equivocado: sin
     * nadie bloqueando, la misma invitación entra sin esperar a nada.
     *
     * Sin este caso, un enforcer que fallara **siempre** —por un error de
     * consulta, por ejemplo— pasaría el test de arriba con nota.
     */
    public function test_the_same_invitation_succeeds_when_nobody_else_holds_the_lock(): void
    {
        [$organization, $owner] = $this->seedOrganizationWithOneFreeSlot();

        $this->actingAsOrganization($organization);
        $this->shortenLockWaitTimeout();

        $this->invite($owner, 'sola@ejemplo.test');

        $this->assertSame(2, $this->userCount($organization));
    }

    /**
     * La regla que sostiene a las dos de arriba: **sin transacción no hay
     * bloqueo**. Un `assertCanCreate` en *autocommit* sería una comprobación
     * que dos peticiones simultáneas se saltan sin enterarse, así que falla
     * ruidosamente en vez de proteger a medias.
     *
     * Este caso vive en este archivo y no en `LimitEnforcerTest` por una razón
     * concreta: `RefreshDatabase` envuelve cada test en una transacción, de
     * modo que allí **siempre** hay una abierta y la guarda no se puede
     * observar. Aquí, sin él, el nivel de transacción es de verdad cero.
     */
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

        // Y la acción de verdad sí la abre, así que invitar sigue funcionando.
        $this->invite($owner, 'con-transaccion@ejemplo.test');

        $this->assertSame(2, $this->userCount($organization));
    }

    // -------------------------------------------------------------------
    // El escenario
    // -------------------------------------------------------------------

    /**
     * Organización con un plan de dos usuarios y solo el `owner` dentro:
     * queda exactamente un sitio. Confirmada en disco para que la segunda
     * conexión pueda verla.
     *
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

        // La vecina de REQ-QAX-003 RB-10: si el bloqueo o el conteo se
        // llevaran por delante a otra organización, aquí se vería.
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

    /**
     * Ejecuta la invitación real, con el enforcer por delante.
     */
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

    // -------------------------------------------------------------------
    // La otra conexión
    // -------------------------------------------------------------------

    private function rival(): Connection
    {
        if ($this->rival instanceof Connection) {
            return $this->rival;
        }

        config(['database.connections.mysql_rival' => config('database.connections.mysql')]);

        return $this->rival = DB::connection('mysql_rival');
    }

    /**
     * La otra transacción coge el contador, que es lo que tiene bloqueado
     * mientras decide si le queda cupo.
     *
     * Bloquea **solo esa fila** y ninguna de `users`: así lo único que puede
     * esperar nuestra petición es la comprobación del cupo. Si bloqueara
     * también las filas de usuarios, un enforcer sin `lockForUpdate` acabaría
     * esperando igual —al insertar— y el test pasaría midiendo el bloqueo
     * equivocado.
     */
    private function rivalLocksTheCounter(int $counterId): void
    {
        $rival = $this->rival();

        $rival->beginTransaction();
        $rival->select('select id from usage_counters where id = ? for update', [$counterId]);
    }

    /**
     * Completa la invitación de la otra persona y libera el bloqueo.
     */
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

    // -------------------------------------------------------------------
    // Esperas y limpieza
    // -------------------------------------------------------------------

    /**
     * Convierte la espera por el bloqueo en un error observable.
     */
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

    /**
     * Borra lo que este archivo siembra. Va en `setUp` y en `tearDown`: lo
     * segundo limpia el caso que acaba, lo primero recoge lo que dejaría un
     * proceso muerto a mitad.
     */
    private function purgeFixtures(): void
    {
        DB::table('organizations')->where('slug', 'like', self::SLUG_PREFIX.'%')->delete();
        DB::table('plans')->where('code', 'like', self::SLUG_PREFIX.'%')->delete();
    }
}
