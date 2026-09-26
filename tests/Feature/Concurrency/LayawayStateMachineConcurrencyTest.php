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

/**
 * `REQ-LAY-005` SP-3: dos transiciones simultáneas sobre el mismo apartado.
 *
 * El caso real es la aprobación de la inicial llegando a la vez que el job de
 * expiración: sin bloqueo, las dos leen `pending_down_payment`, las dos
 * concluyen que su paso es legal, y el apartado queda `active` y `expired` a
 * la vez según quién escribiera último —con el cupo liberado por una y el
 * cronograma generado por la otra—.
 *
 * **Cómo se prueba sin dos procesos**, como `LastOwnerConcurrencyTest`: con
 * dos conexiones a MySQL y el orden forzado a mano.
 *
 *  1. la conexión rival abre transacción y bloquea la fila del apartado, que
 *     es lo que tiene cogido el job de expiración mientras expira;
 *  2. nuestra transición se queda **esperando al leer**: su `SELECT … FOR
 *     UPDATE` choca con el bloqueo, y con `innodb_lock_wait_timeout` a un
 *     segundo esa espera se ve como error. Que espere es la prueba de que
 *     valida bajo el bloqueo: sin `FOR UPDATE` leería el estado viejo y
 *     seguiría;
 *  3. la rival expira el apartado y confirma;
 *  4. nuestra transición lo intenta otra vez y **lee lo que dejó la otra**
 *     —`expired`, terminal— y lanza `IllegalLayawayTransitionException`.
 *
 * **Sin `RefreshDatabase` y en esta carpeta, a propósito.** Un bloqueo entre
 * dos conexiones solo es observable sobre filas confirmadas, y dentro de la
 * transacción de `RefreshDatabase` la segunda conexión no vería siquiera el
 * apartado: el test pasaría sin bloquear nada. `tests/Feature/Concurrency`
 * corre en serie, aparte de la ronda paralela, y este archivo barre lo suyo.
 */
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

        // (1) El job de expiración llega primero y coge la fila.
        $rival = $this->rival();
        $rival->beginTransaction();
        $rival->select('select id from layaways where id = ? for update', [$layaway->getKey()]);

        // (2) La aprobación de la inicial se queda esperando al leer.
        $this->shortenLockWaitTimeout();

        $waited = false;

        try {
            $this->activate($organization, $layaway);
        } catch (QueryException $exception) {
            $waited = str_contains($exception->getMessage(), 'Lock wait timeout');
        }

        $this->assertTrue($waited, 'La transición no esperó al bloqueo de la otra: valida sin FOR UPDATE.');
        $this->assertSame('pending_down_payment', $this->statusOf($layaway), 'La transición bloqueada dejó algo escrito.');

        // (3) La otra expira el apartado y libera.
        $rival->update("update layaways set status = 'expired' where id = ?", [$layaway->getKey()]);
        $rival->commit();

        // (4) Y ahora la nuestra lee lo que dejó la otra.
        try {
            $this->activate($organization, $layaway);
            $this->fail('Se activó un apartado que otra transacción acababa de expirar.');
        } catch (IllegalLayawayTransitionException $exception) {
            $this->assertSame(LayawayStatus::Expired, $exception->from);
        }

        $this->assertSame('expired', $this->statusOf($layaway));
        $this->assertNull(DB::table('layaways')->where('id', $layaway->getKey())->value('activated_at'));
    }

    /**
     * El control: sin nadie bloqueando, la misma activación pasa sin esperar.
     * Sin este caso, una transición que fallara siempre pasaría el de arriba.
     */
    public function test_the_same_transition_succeeds_when_nobody_else_holds_the_lock(): void
    {
        [$organization, $layaway] = $this->seedPendingLayaway();
        $this->shortenLockWaitTimeout();

        $this->activate($organization, $layaway);

        $this->assertSame('active', $this->statusOf($layaway));
    }

    // -------------------------------------------------------------------

    /**
     * @return array{0: Organization, 1: Layaway}
     */
    private function seedPendingLayaway(): array
    {
        $organization = Organization::factory()->create(['slug' => self::SLUG_PREFIX.bin2hex(random_bytes(4))]);

        // La vecina (`REQ-QAX-003` RB-10), con su propio apartado: si el
        // bloqueo o la escritura se escaparan de la organización, se vería.
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

    /**
     * Barre lo que siembra, **tabla a tabla y en orden**: los apartados
     * primero, porque sus claves hacia `customers` y `products` son
     * `restrict` (`T-039`) y el `ON DELETE CASCADE` de la organización no
     * garantiza en qué orden borra sus hijas. Confiar en la cascada es el
     * fallo que la convención de «Cómo leer este plan» describe.
     */
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
