<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

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

/**
 * `REQ-ORG-003` SP-3 y `REQ-ORG-005` SP-3: dos `owner` degradándose a la vez.
 *
 * Es el caso que justifica que la comprobación sea transaccional y con
 * bloqueo, y el único que un test de un solo hilo no ve: sin bloqueo, las dos
 * transacciones leen "hay dos owners", las dos concluyen que pueden proceder y
 * la organización se queda sin ninguno. De ese estado no se sale sin tocar la
 * base de datos a mano.
 *
 * **Cómo se prueba sin dos procesos.** Con dos conexiones a MySQL y el orden
 * forzado a mano, que es más determinista que lanzar hilos y esperar a que
 * choquen:
 *
 *  1. la conexión rival abre transacción y bloquea las filas de los owners,
 *     igual que haría la guarda en la petición de la otra persona;
 *  2. nuestra petición ejecuta la guarda de verdad y **se queda esperando**;
 *     con `innodb_lock_wait_timeout` a un segundo, esa espera se convierte en
 *     un error que el test puede ver. Que llegue a esperar es la prueba de que
 *     el bloqueo serializa: sin `FOR UPDATE` pasaría de largo;
 *  3. la rival completa su degradación y libera;
 *  4. nuestra petición lo intenta otra vez y ahora lee el estado de verdad
 *     —queda uno— y falla con `LastOwnerException`.
 *
 * Al final queda exactamente un `owner`, que es la invariante.
 *
 * **Sin `RefreshDatabase`, a propósito.** Un bloqueo entre dos conexiones solo
 * es observable sobre filas confirmadas; dentro de la transacción de test, la
 * segunda conexión no vería siquiera la organización. A cambio, este archivo
 * se limpia lo suyo: siembra con un `slug` reconocible y lo borra antes y
 * después de cada caso, para no dejar filas que hagan fallar a los demás.
 */
final class LastOwnerConcurrencyTest extends TestCase
{
    /**
     * Marca de las organizaciones de este archivo, para poder barrerlas.
     */
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

    /**
     * El escenario entero: el bloqueo serializa y la invariante aguanta.
     */
    public function test_two_simultaneous_demotions_leave_one_owner(): void
    {
        [$organization, $ours, $theirs] = $this->seedOrganizationWithTwoOwners();

        $this->actingAsOrganization($organization);

        // (1) La otra petición llega primero y coge su fila para degradarse.
        $this->rivalLocksItsOwnRow($theirs);

        // (2) La nuestra ejecuta la guarda de verdad y se queda esperando **al
        //     contar**: su SELECT … FOR UPDATE incluye la fila del rival.
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

        // (3) La otra termina su degradación y libera.
        $this->rivalDemotes($theirs);

        // (4) Y ahora la nuestra lee el estado de verdad: queda uno, y es ella.
        try {
            $this->demote($ours);
            $this->fail('La segunda degradación debió fallar: habría dejado la organización sin owner.');
        } catch (LastOwnerException $exception) {
            $this->assertStringContainsString('último owner', $exception->getMessage());
        }

        $this->assertSame(1, $this->activeOwnerCount($organization), 'La organización se quedó sin owner.');
        $this->assertSame(OrganizationRole::Owner, $ours->refresh()->role);
    }

    /**
     * El control que evita que lo anterior pase por el motivo equivocado: sin
     * nadie bloqueando, la misma degradación se aplica sin esperar a nada.
     *
     * Sin este caso, una guarda que fallara **siempre** —por un error de
     * consulta, por ejemplo— pasaría el test de arriba con nota.
     */
    public function test_the_same_demotion_succeeds_when_nobody_else_holds_the_lock(): void
    {
        [$organization, $ours] = $this->seedOrganizationWithTwoOwners();

        $this->actingAsOrganization($organization);
        $this->shortenLockWaitTimeout();

        $this->demote($ours);

        $this->assertSame(OrganizationRole::Member, $ours->refresh()->role);
        $this->assertSame(1, $this->activeOwnerCount($organization));
    }

    // -------------------------------------------------------------------
    // El escenario
    // -------------------------------------------------------------------

    /**
     * Organización con dos `owner` activos, confirmada en disco para que la
     * segunda conexión pueda verla.
     *
     * @return array{0: Organization, 1: User, 2: User}
     */
    private function seedOrganizationWithTwoOwners(): array
    {
        $organization = Organization::factory()->create([
            'slug' => self::SLUG_PREFIX.bin2hex(random_bytes(4)),
        ]);

        // La vecina de REQ-QAX-003 RB-10: si el bloqueo se llevara por delante
        // a los owners de otra organización, aquí se vería.
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

    /**
     * Ejecuta la degradación real, con la guarda por delante.
     */
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

    // -------------------------------------------------------------------
    // La otra conexión
    // -------------------------------------------------------------------

    /**
     * Segunda conexión a la misma base de datos: la petición de la otra
     * persona.
     */
    private function rival(): Connection
    {
        if ($this->rival instanceof Connection) {
            return $this->rival;
        }

        config(['database.connections.mysql_rival' => config('database.connections.mysql')]);

        return $this->rival = DB::connection('mysql_rival');
    }

    /**
     * La otra transacción bloquea **su propia fila**, que es lo que tiene
     * cogido mientras se degrada.
     *
     * Bloquea solo esa y no todas las de los owners, y la diferencia es lo que
     * hace que este test sirva de algo. Con todas bloqueadas, una guarda sin
     * `FOR UPDATE` también acabaría esperando —al escribir sobre la fila
     * nuestra, que estaría cogida— y el test pasaría igual, midiendo el
     * bloqueo equivocado. Con solo la del rival, la escritura nuestra no choca
     * con nada: lo único que puede esperar es el **conteo**, que es
     * exactamente lo que se quiere probar.
     */
    private function rivalLocksItsOwnRow(User $theirs): void
    {
        $rival = $this->rival();

        $rival->beginTransaction();
        $rival->select('select id from users where id = ? for update', [$theirs->getKey()]);
    }

    /**
     * Completa la degradación de la otra persona y libera el bloqueo.
     */
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

    // -------------------------------------------------------------------
    // Esperas y limpieza
    // -------------------------------------------------------------------

    /**
     * Convierte la espera por el bloqueo en un error observable.
     *
     * Un segundo es de sobra: o la guarda bloquea —y entonces espera todo el
     * segundo— o pasa de largo, y eso se ve enseguida.
     */
    private function shortenLockWaitTimeout(): void
    {
        $this->originalLockWaitTimeout ??= (int) DB::selectOne('select @@innodb_lock_wait_timeout as value')->value;

        DB::statement('set session innodb_lock_wait_timeout = 1');
    }

    /**
     * Y se devuelve como estaba: la conexión por defecto sobrevive al test, y
     * dejarla con un segundo de paciencia haría frágil a cualquier otro.
     */
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
        $organizations = DB::table('organizations')
            ->where('slug', 'like', self::SLUG_PREFIX.'%')
            ->pluck('id');

        // **La auditoría se borra a mano y antes, porque no cae en cascada.**
        // `activity_log.organization_id` no lleva clave foránea a propósito
        // (`T-016`): `REQ-ORG-008` RB-5 conserva los datos de un negocio
        // cerrado, y el rastro de quién aprobó qué comprobante tiene que
        // sobrevivir justo al momento en que la organización deja de existir.
        // Como este archivo **confirma de verdad** —no usa `RefreshDatabase`—,
        // sus entradas se quedarían en la base contaminando los tests que
        // corran después.
        DB::table('activity_log')->whereIn('organization_id', $organizations)->delete();

        DB::table('organizations')->whereIn('id', $organizations)->delete();
    }
}
