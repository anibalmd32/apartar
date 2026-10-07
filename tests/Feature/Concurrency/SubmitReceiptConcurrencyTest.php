<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Actions\Payment\SubmitReceiptAction;
use App\Actions\Payment\SubmitReceiptData;
use App\Domain\Money\Currency;
use App\Domain\Payment\DuplicateCriterion;
use App\Domain\Payment\DuplicateReceiptException;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\ReceiptFile;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Receipt;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\UploadsReceiptFiles;
use Tests\TestCase;

/**
 * Dos subidas del mismo comprobante a la vez, contra otra transacción de
 * verdad (`REQ-PAY-003` SP-5, SP-11; `REQ-PAY-001` SP-6).
 *
 * Dos garantías que solo existen entre transacciones confirmadas:
 *
 *  1. **Sobre el mismo apartado, el bloqueo serializa.** La otra subida
 *     tiene el apartado cogido con su comprobante a medio escribir; la
 *     nuestra espera **en el `FOR UPDATE` de `layaways`**, antes de guardar
 *     nada en disco. Al soltarse, la comprobación ve el comprobante del otro
 *     y responde «ya fue reportado» por el camino normal.
 *  2. **Sobre otro apartado del mismo negocio, el único de la tabla es la
 *     garantía final.** No hay bloqueo común, así que las dos pasan la
 *     comprobación sin verse: la otra confirma justo **después** de nuestra
 *     lectura del hash —el `DB::listen` la ve pasar— y es
 *     `UNIQUE(organization_id, file_hash)` quien choca en nuestro `INSERT`.
 *     Queda un solo comprobante, el nuestro se deshace con su archivo, y el
 *     cliente ve la misma excepción.
 *
 * **Sin `RefreshDatabase` y en esta carpeta**, como los demás de aquí. Este
 * archivo barre lo suyo, tabla a tabla.
 */
final class SubmitReceiptConcurrencyTest extends TestCase
{
    use CreatesLayaways, UploadsReceiptFiles;

    private const SLUG_PREFIX = 'concurrencia-comprobante-';

    private ?Connection $rival = null;

    private ?int $originalLockWaitTimeout = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purgeFixtures();

        Storage::fake(Receipt::DISK);
        Queue::fake();
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

    public function test_the_layaway_lock_serialises_two_uploads_to_the_same_layaway(): void
    {
        [$organization, $layaway] = $this->seedLayaways();
        $file = $this->receiptImage();

        // La otra subida tiene el apartado y su comprobante a medio escribir.
        $rival = $this->rival();
        $rival->beginTransaction();
        $rival->select('select id from layaways where id = ? for update', [$layaway->getKey()]);
        $this->rivalInsertsReceipt($layaway, hash_file('sha256', (string) $file->getRealPath()));

        $this->shortenLockWaitTimeout();

        $waitedOn = null;

        try {
            $this->submit($organization, $layaway, $file, '2222222222');
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'Lock wait timeout')) {
                $waitedOn = strtolower($exception->getSql());
            }
        }

        $this->assertNotNull($waitedOn, 'La subida no esperó al bloqueo del apartado.');
        $this->assertStringContainsString('from `layaways`', (string) $waitedOn);
        $this->assertStringContainsString('for update', (string) $waitedOn);
        $this->assertSame([], Storage::disk(Receipt::DISK)->allFiles(), 'Guardó el archivo antes de tener el apartado.');

        $rival->commit();

        // Al soltarse, la comprobación ve el comprobante del otro.
        try {
            $this->submit($organization, $layaway, $file, '2222222222');
            $this->fail('Se aceptaron dos veces el mismo archivo.');
        } catch (DuplicateReceiptException $exception) {
            $this->assertSame(DuplicateCriterion::FileHash, $exception->criterion);
            $this->assertNull($exception->getPrevious(), 'Lo paró el único y no la comprobación: el bloqueo no serializó.');
        }

        $this->assertSame(1, DB::table('receipts')->where('organization_id', $organization->getKey())->count());
        $this->assertSame([], Storage::disk(Receipt::DISK)->allFiles());
    }

    public function test_two_uploads_racing_past_the_check_leave_one_receipt(): void
    {
        [$organization, $layaway, $other] = $this->seedLayaways();
        $file = $this->receiptImage();
        $hash = hash_file('sha256', (string) $file->getRealPath());

        // La otra subida, sobre otro apartado del mismo negocio: escrita y sin
        // confirmar todavía.
        $rival = $this->rival();
        $rival->beginTransaction();
        $winner = $this->rivalInsertsReceipt($other, $hash);
        $this->commitRivalRightAfterOurHashLookup($hash);

        try {
            $this->submit($organization, $layaway, $file, '2222222222');
            $this->fail('Las dos subidas del mismo archivo se aceptaron.');
        } catch (DuplicateReceiptException $exception) {
            $this->assertSame(DuplicateCriterion::FileHash, $exception->criterion);
            $this->assertInstanceOf(UniqueConstraintViolationException::class, $exception->getPrevious(), 'No hubo carrera: la comprobación vio al otro.');
        }

        $this->assertSame([$winner], DB::table('receipts')->where('organization_id', $organization->getKey())->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
        $this->assertSame([], Storage::disk(Receipt::DISK)->allFiles(), 'El archivo del perdedor se quedó en el disco.');
        Queue::assertNothingPushed();
    }

    // -------------------------------------------------------------------

    /**
     * Confirma la transacción rival **en cuanto nuestra lectura del hash haya
     * pasado**, y ni un momento antes. `DB::listen` avisa después de ejecutar
     * la sentencia: es el hueco entre la comprobación y el `INSERT`.
     */
    private function commitRivalRightAfterOurHashLookup(string $hash): void
    {
        $committed = false;

        DB::listen(function ($query) use ($hash, &$committed): void {
            if ($committed || $query->connectionName === 'mysql_rival') {
                return;
            }

            if (str_contains($query->sql, 'file_hash') && in_array($hash, $query->bindings, true)) {
                $committed = true;
                $this->rival()->commit();
            }
        });
    }

    /**
     * @return array{0: Organization, 1: Layaway, 2: Layaway}
     */
    private function seedLayaways(): array
    {
        $organization = Organization::factory()->create(['slug' => self::SLUG_PREFIX.bin2hex(random_bytes(4))]);

        return [$organization, $this->layawayIn($organization), $this->layawayIn($organization)];
    }

    private function submit(Organization $organization, Layaway $layaway, UploadedFile $file, string $reference): Receipt
    {
        $data = new SubmitReceiptData($layaway, null, PaymentMethod::PagoMovil, 'Banesco', $reference, CarbonImmutable::now(), '850.00', Currency::VES, null, $file);

        return $this->withinOrganization($organization, fn (): Receipt => app(SubmitReceiptAction::class)->handle($data));
    }

    /**
     * El comprobante de la otra subida, escrito por la conexión rival dentro
     * de su transacción, con otro banco y otra referencia: lo único que
     * comparte con el nuestro es el archivo.
     */
    private function rivalInsertsReceipt(Layaway $layaway, string $hash): int
    {
        return (int) $this->rival()->table('receipts')->insertGetId([
            'organization_id' => $layaway->organization_id,
            'layaway_id' => $layaway->getKey(),
            'customer_id' => $layaway->customer_id,
            'payment_method' => PaymentMethod::PagoMovil->value,
            'bank' => 'Mercantil',
            'reference' => '1111111111',
            'paid_at' => now()->toDateString(),
            'declared_amount' => '850.00',
            'declared_currency' => Currency::VES->value,
            'file_path' => ReceiptFile::pathFor((int) $layaway->organization_id, 'jpg'),
            'file_hash' => $hash,
            'file_mime' => 'image/jpeg',
            'file_size' => 1_000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
     * Barre lo que siembra, tabla a tabla y en orden (convención de «Cómo leer
     * este plan»): los comprobantes antes que los apartados, y los apartados
     * antes que las fichas y los productos, porque sus claves son `restrict`.
     */
    private function purgeFixtures(): void
    {
        $organizations = DB::table('organizations')
            ->where('slug', 'like', self::SLUG_PREFIX.'%')
            ->pluck('id');

        DB::table('receipts')->whereIn('organization_id', $organizations)->delete();
        DB::table('layaways')->whereIn('organization_id', $organizations)->delete();
        DB::table('customers')->whereIn('organization_id', $organizations)->delete();
        DB::table('products')->whereIn('organization_id', $organizations)->delete();
        DB::table('usage_counters')->whereIn('organization_id', $organizations)->delete();
        DB::table('activity_log')->whereIn('organization_id', $organizations)->delete();
        DB::table('organization_settings')->whereIn('organization_id', $organizations)->delete();
        DB::table('organizations')->whereIn('id', $organizations)->delete();
    }
}
