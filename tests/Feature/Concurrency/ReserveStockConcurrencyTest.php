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

/**
 * `REQ-LAY-003` SP-1: dos apartados simultáneos de la última unidad.
 *
 * Es el caso por el que existe la reserva: sin bloqueo, las dos peticiones
 * leen `stock = 1`, las dos concluyen que cabe, y el negocio vende dos veces
 * la misma nevera —y lo descubre cuando el segundo cliente va a buscarla—.
 *
 * **Cómo se provoca sin dos procesos**, como los demás de esta carpeta: con
 * dos conexiones y el orden forzado a mano.
 *
 *  1. la conexión rival abre transacción, bloquea la fila del producto y se
 *     lleva la última unidad **sin confirmar**, que es la otra petición a
 *     mitad de su apartado;
 *  2. nuestra reserva se queda **esperando al leer**: su `SELECT … FOR
 *     UPDATE` choca con el bloqueo, y con `innodb_lock_wait_timeout` a un
 *     segundo esa espera se ve como error. Se exige que la espera sea **en el
 *     `FOR UPDATE`** y no en el `UPDATE` de después: sin el bloqueo, la
 *     lectura pasaría sin esperar, vería la unidad todavía libre, y lo que
 *     esperaría sería la escritura — la prueba de que se comprobó sobre un
 *     dato viejo;
 *  3. la rival confirma;
 *  4. nuestra reserva lo intenta otra vez, lee `stock = 0` y lanza
 *     `InsufficientStockException`. El stock queda en cero, no en `-1`.
 *
 * Se comprobó quitando el `lockForUpdate()` de `ProductLock`: el paso 2 se
 * pone rojo, porque la espera pasa a darse en el `UPDATE`.
 *
 * **Sin `RefreshDatabase` y en esta carpeta, a propósito**: un bloqueo entre
 * dos conexiones solo es observable sobre filas confirmadas. Este archivo
 * barre lo suyo.
 */
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

        // (1) La otra petición llega primero y se lleva la unidad.
        $rival = $this->rival();
        $rival->beginTransaction();
        $rival->select('select id from products where id = ? for update', [$product->getKey()]);
        $rival->update('update products set stock = 0 where id = ?', [$product->getKey()]);

        // (2) La nuestra se queda esperando **al leer**.
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

        // (3) La otra confirma.
        $rival->commit();

        // (4) Y la nuestra ve lo que dejó.
        try {
            $this->reserve($organization, $product);
            $this->fail('Se reservó dos veces la última unidad.');
        } catch (InsufficientStockException $exception) {
            $this->assertSame(0, $exception->available);
        }

        $this->assertSame(0, (int) DB::table('products')->where('id', $product->getKey())->value('stock'));
    }

    /**
     * El control: sin nadie bloqueando, la misma reserva pasa sin esperar.
     * Sin este caso, una reserva que fallara siempre pasaría el de arriba.
     */
    public function test_the_same_reservation_succeeds_when_nobody_else_holds_the_lock(): void
    {
        [$organization, $product] = $this->seedLastUnit();
        $this->shortenLockWaitTimeout();

        $this->reserve($organization, $product);

        $this->assertSame(0, (int) DB::table('products')->where('id', $product->getKey())->value('stock'));
    }

    /**
     * RB-3 · la reserva va **dentro** de la transacción del apartado. Fuera de
     * una, el `FOR UPDATE` se suelta en el acto y no serializa nada; y una
     * reserva confirmada sin su apartado sería stock que desaparece. Solo aquí
     * se puede afirmar: `RefreshDatabase` abre siempre una transacción.
     */
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

    // -------------------------------------------------------------------

    /**
     * @return array{0: Organization, 1: Product}
     */
    private function seedLastUnit(): array
    {
        $organization = Organization::factory()->create(['slug' => self::SLUG_PREFIX.bin2hex(random_bytes(4))]);

        // La vecina (`REQ-QAX-003` RB-10), con su propio producto: si el
        // bloqueo se escapara de la organización, se vería contra él.
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

    /**
     * Barre lo que siembra, tabla a tabla (convención de «Cómo leer este
     * plan»): los productos antes que las organizaciones.
     */
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
