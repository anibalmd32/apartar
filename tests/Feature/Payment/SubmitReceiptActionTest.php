<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Actions\Layaway\ActivateLayawayAction;
use App\Actions\Payment\SubmitReceiptAction;
use App\Actions\Payment\SubmitReceiptData;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Currency;
use App\Domain\Payment\DuplicateCriterion;
use App\Domain\Payment\DuplicateReceiptException;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\PaymentNotApplicableException;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\CompressReceiptImage;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Receipt;
use App\Models\UsageCounter;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\Concerns\UploadsReceiptFiles;
use Tests\TestCase;

/**
 * `SubmitReceiptAction` (`T-059`, `REQ-PAY-001`, `REQ-PAY-002`,
 * `REQ-PAY-003`): el cliente reporta su pago.
 *
 * Contra MySQL de verdad —los únicos por organización y el `CHECK` de banco y
 * referencia son de la base— y con el disco `receipts` falseado por
 * `Storage::fake()`, que es un disco local en una carpeta temporal: lo que se
 * afirma de rutas y archivos es lo que haría el de verdad. Los archivos son
 * reales (`UploadsReceiptFiles`), porque el tipo se decide por el contenido.
 *
 * El negocio está en `Asia/Tokyo`, nueve horas por delante de la zona de la
 * aplicación, para que la fecha del pago se vea guardada como el día que es.
 */
final class SubmitReceiptActionTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase, UploadsReceiptFiles;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        // Sin `Queue::fake()` por defecto: con la cola `sync`, la
        // recompresión corre de verdad tras cada subida —sobre imágenes que ya
        // caben, así que no cambia nada— y el camino entero queda ejercitado.
        // Los casos que miran la cola la falsean ellos.
        Storage::fake(Receipt::DISK);

        $this->organization = Organization::factory()->create(['timezone' => 'Asia/Tokyo']);
    }

    /**
     * RB-6 · nace en `pending_review` con lo que declaró el cliente, y **el
     * balance no se mueve**: ni el apartado, ni sus cuotas, ni un pago.
     */
    public function test_it_creates_the_receipt_pending_review_without_touching_the_balance(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $this->within(fn () => app(ActivateLayawayAction::class)->handle($layaway));
        $account = $this->within(fn (): PaymentAccount => PaymentAccount::factory()->create());
        $before = $this->balanceOf($layaway);
        $file = $this->receiptImage();

        $receipt = $this->submit($layaway, ['paymentAccount' => $account, 'note' => 'Pagué la segunda cuota.', 'file' => $file]);

        $this->assertSame($before, $this->balanceOf($layaway), 'Subir un comprobante movió el balance (REQ-PAY-001 RB-6).');
        $this->assertSame(0, DB::table('payments')->count());

        $stored = $this->within(fn (): Receipt => Receipt::query()->findOrFail($receipt->getKey()));
        $this->assertSame(ReceiptStatus::PendingReview, $stored->status);
        $this->assertSame((int) $this->organization->getKey(), $stored->organization_id);
        $this->assertSame((int) $layaway->getKey(), $stored->layaway_id);
        $this->assertSame((int) $layaway->customer_id, $stored->customer_id);
        $this->assertSame((int) $account->getKey(), $stored->payment_account_id);
        $this->assertSame(PaymentMethod::PagoMovil, $stored->payment_method);
        $this->assertSame('Banesco', $stored->bank);
        $this->assertSame('0123456789', $stored->reference);
        $this->assertSame('850.00', $stored->declared_amount);
        $this->assertSame(Currency::VES, $stored->declared_currency);
        $this->assertSame('Pagué la segunda cuota.', $stored->note);
        $this->assertSame(hash_file('sha256', (string) $file->getRealPath()), $stored->file_hash);
        $this->assertSame('image/jpeg', $stored->file_mime);
        $this->assertSame(filesize((string) $file->getRealPath()), $stored->file_size);
        $this->assertNull($stored->reviewed_by);
    }

    /**
     * RB-4 · la fecha del pago es un día del calendario del cliente, y se
     * guarda como ese día: las 00:30 del 5 en Tokio son todavía el 4 en
     * Caracas, y el comprobante dice 5.
     */
    public function test_the_payment_date_is_stored_as_the_day_it_is(): void
    {
        $receipt = $this->submit($this->layawayIn($this->organization), [
            'paidAt' => CarbonImmutable::parse('2026-10-05 00:30:00', 'Asia/Tokyo'),
        ]);

        $this->assertSame('2026-10-05', DB::table('receipts')->where('id', $receipt->getKey())->value('paid_at'));
    }

    /**
     * `REQ-PAY-002` RB-4, RB-7 · la ruta física empieza por la organización
     * **del apartado**, el nombre es un UUID y del nombre original no queda
     * nada. Lo guardado es, byte a byte, lo que se subió.
     */
    public function test_the_file_is_stored_under_the_organization_with_a_uuid_name(): void
    {
        $file = $this->receiptImage(name: 'Mi pago de octubre.jpg');

        $receipt = $this->submit($this->layawayIn($this->organization), ['file' => $file]);

        $this->assertMatchesRegularExpression(
            '#^'.$this->organization->getKey().'/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.jpg$#',
            $receipt->file_path,
        );
        $this->assertStringNotContainsString('octubre', $receipt->file_path);
        Storage::disk(Receipt::DISK)->assertExists($receipt->file_path);
        $this->assertSame($receipt->file_hash, hash('sha256', (string) Storage::disk(Receipt::DISK)->get($receipt->file_path)));
    }

    /**
     * La extensión en disco sale del contenido: un PNG que se llama `.jpg` se
     * guarda como `.png`, y su tipo real queda escrito.
     */
    public function test_the_extension_and_mime_come_from_the_content(): void
    {
        $receipt = $this->submit($this->layawayIn($this->organization), [
            'file' => $this->receiptImage(type: 'png', name: 'captura.jpg'),
        ]);

        $this->assertStringEndsWith('.png', $receipt->file_path);
        $this->assertSame('image/png', $receipt->file_mime);
    }

    /**
     * El PDF se guarda tal cual y no se encola para recomprimir: no es una
     * imagen.
     */
    public function test_a_pdf_is_stored_and_not_queued_for_compression(): void
    {
        Queue::fake();

        $receipt = $this->submit($this->layawayIn($this->organization), ['file' => $this->receiptPdf()]);

        $this->assertStringEndsWith('.pdf', $receipt->file_path);
        $this->assertSame('application/pdf', $receipt->file_mime);
        Queue::assertNothingPushed();
    }

    /**
     * Lo que el formulario tenía que haber parado no llega al disco aunque el
     * formulario se olvide: un ejecutable con nombre de captura.
     */
    public function test_a_file_that_is_not_a_receipt_type_never_reaches_the_disk(): void
    {
        $layaway = $this->layawayIn($this->organization);

        try {
            $this->submit($layaway, ['file' => $this->receiptUpload('captura.jpg', "\x7FELF\x02\x01\x01\x00".str_repeat("\x00", 56))]);
            $this->fail('Se guardó un ejecutable como comprobante.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('no es un tipo de comprobante', $exception->getMessage());
        }

        $this->assertSame([], Storage::disk(Receipt::DISK)->allFiles());
        $this->assertSame(0, DB::table('receipts')->count());
    }

    /**
     * `REQ-PAY-003` RB-2 (a), SP-11 · el mismo archivo dos veces en el mismo
     * negocio, aunque sea para otro apartado y con otra referencia.
     */
    public function test_the_same_file_in_the_same_organization_is_refused(): void
    {
        $file = $this->receiptImage();
        $first = $this->submit($this->layawayIn($this->organization), ['file' => $file]);

        $exception = $this->refused(fn () => $this->submit($this->layawayIn($this->organization), [
            'file' => $this->sameFileAs($file),
            'reference' => '9999999999',
        ]));

        $this->assertSame(DuplicateCriterion::FileHash, $exception->criterion);
        $this->assertSame('file', $exception->field());
        $this->assertSame([$first->file_path], Storage::disk(Receipt::DISK)->allFiles());
    }

    /**
     * `REQ-PAY-003` RB-2 (b), SP-2, SP-3 · otra captura —recortada, otro
     * hash— con el mismo banco y la misma referencia, aunque la suba otro
     * cliente del mismo negocio.
     */
    public function test_the_same_bank_and_reference_in_the_same_organization_is_refused(): void
    {
        $this->submit($this->layawayIn($this->organization));

        $exception = $this->refused(fn () => $this->submit($this->layawayIn($this->organization)));

        $this->assertSame(DuplicateCriterion::BankReference, $exception->criterion);
        $this->assertSame('reference', $exception->field());
        $this->assertCount(1, Storage::disk(Receipt::DISK)->allFiles());
    }

    /**
     * `REQ-PAY-003` RB-5 · un rechazo no libera ni el archivo ni la
     * referencia.
     */
    public function test_a_rejected_receipt_still_blocks_its_file_and_its_reference(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $file = $this->receiptImage();
        $this->receiptFor($layaway, ReceiptStatus::Rejected, ['file_hash' => hash_file('sha256', (string) $file->getRealPath()), 'reference' => '1111111111']);
        $this->receiptFor($layaway, ReceiptStatus::Rejected, ['bank' => 'Banesco', 'reference' => '0123456789']);

        $byFile = $this->refused(fn () => $this->submit($layaway, ['file' => $file, 'reference' => '2222222222']));
        $byReference = $this->refused(fn () => $this->submit($layaway));

        $this->assertSame(DuplicateCriterion::FileHash, $byFile->criterion);
        $this->assertSame(DuplicateCriterion::BankReference, $byReference->criterion);
    }

    /**
     * **`REQ-PAY-001` SP-3, `REQ-PAY-003` RB-4 · el test explícito.** El mismo
     * archivo y la misma referencia, ya reportados en otro negocio, se aceptan:
     * la unicidad es por organización, y ninguna se entera de la otra.
     */
    public function test_the_same_file_and_reference_in_another_organization_are_accepted(): void
    {
        $file = $this->receiptImage();
        $theirs = $this->submit($this->layawayIn($this->neighbourOrganization()), ['file' => $file]);

        $ours = $this->submit($this->layawayIn($this->organization), ['file' => $this->sameFileAs($file)]);

        $this->assertSame($theirs->file_hash, $ours->file_hash);
        $this->assertSame([$theirs->reference, $theirs->bank], [$ours->reference, $ours->bank]);
        $this->assertStringStartsWith($this->neighbourOrganization()->getKey().'/', $theirs->file_path);
        $this->assertStringStartsWith($this->organization->getKey().'/', $ours->file_path);
        $this->assertSame(2, DB::table('receipts')->count());
    }

    /**
     * `REQ-PAY-003` RB-3 · sin referencia —el efectivo— no hay criterio (b):
     * dos pagos en efectivo del mismo apartado son dos comprobantes.
     */
    public function test_cash_receipts_without_reference_do_not_block_each_other(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $cash = ['method' => PaymentMethod::EfectivoUsd, 'bank' => null, 'reference' => null, 'declaredAmount' => '30.00', 'declaredCurrency' => Currency::USD];

        $first = $this->submit($layaway, $cash);
        $second = $this->submit($layaway, $cash);

        $this->assertNull($first->reference);
        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertCount(2, Storage::disk(Receipt::DISK)->allFiles());
    }

    /**
     * RB-1, SP-4 · un apartado terminal no admite comprobantes, y no se queda
     * nada en el disco.
     */
    #[DataProvider('terminalStatuses')]
    public function test_a_terminal_layaway_is_refused(LayawayStatus $status): void
    {
        $layaway = $this->layawayIn($this->organization, $status);

        try {
            $this->submit($layaway);
            $this->fail("Un apartado {$status->value} admitió un comprobante.");
        } catch (PaymentNotApplicableException $exception) {
            $this->assertStringContainsString($status->label(), $exception->getMessage());
        }

        $this->assertSame([], Storage::disk(Receipt::DISK)->allFiles());
        $this->assertSame(0, DB::table('receipts')->count());
    }

    /**
     * @return array<string, array{LayawayStatus}>
     */
    public static function terminalStatuses(): array
    {
        return [
            'completed' => [LayawayStatus::Completed],
            'cancelled' => [LayawayStatus::Cancelled],
            'expired' => [LayawayStatus::Expired],
        ];
    }

    /**
     * RB-7 · los tres vivos admiten comprobantes, varios a la vez.
     */
    public function test_an_active_and_an_overdue_layaway_accept_receipts(): void
    {
        $this->submit($this->layawayIn($this->organization, LayawayStatus::Active));
        $this->submit($this->layawayIn($this->organization, LayawayStatus::Overdue), ['reference' => '5555555555']);

        $this->assertSame(2, DB::table('receipts')->where('status', ReceiptStatus::PendingReview->value)->count());
    }

    /**
     * **SP-6 · el rollback elimina el archivo.** Algo falla después de
     * guardarlo —aquí, al guardar la fila—, y no queda ni la fila ni el
     * archivo, ni se encola nada.
     */
    public function test_a_failure_after_storing_the_file_removes_it(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $written = null;

        Queue::fake();

        Receipt::saving(function (Receipt $receipt) use (&$written): void {
            $written = $receipt->file_path;
            Storage::disk(Receipt::DISK)->assertExists($receipt->file_path);

            throw new RuntimeException('Se cayó la base a mitad de la subida.');
        });

        try {
            $this->submit($layaway);
            $this->fail('La subida no falló.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Se cayó la base a mitad de la subida.', $exception->getMessage());
        }

        $this->assertNotNull($written, 'El fallo llegó antes de guardar el archivo: el test no prueba el rollback.');
        Storage::disk(Receipt::DISK)->assertMissing((string) $written);
        $this->assertSame([], Storage::disk(Receipt::DISK)->allFiles());
        $this->assertSame(0, DB::table('receipts')->count());
        Queue::assertNothingPushed();
    }

    /**
     * `REQ-PAY-003` SP-5 · la carrera, simulada: la otra subida del mismo
     * archivo se inserta **entre** la comprobación y el `INSERT` de la
     * acción. El único de la tabla choca y sale la misma excepción que en el
     * camino normal, con su criterio, y sin dejar el archivo del perdedor.
     *
     * Simulada en la misma conexión —un `saving` que inserta la fila rival—,
     * así que el rollback se lleva también la rival: lo que se afirma aquí es
     * la traducción. La carrera de dos conexiones de verdad, con la rival
     * confirmada y una sola fila al final, está en
     * `Concurrency\SubmitReceiptConcurrencyTest`.
     */
    #[DataProvider('races')]
    public function test_a_race_lost_on_the_unique_index_is_a_duplicate(DuplicateCriterion $criterion): void
    {
        $layaway = $this->layawayIn($this->organization);
        $file = $this->receiptImage();
        $hash = hash_file('sha256', (string) $file->getRealPath());
        $rivalInserted = false;

        Receipt::saving(function () use ($layaway, $criterion, $hash, &$rivalInserted): void {
            if ($rivalInserted) {
                return;
            }

            $rivalInserted = true;

            $this->receiptFor($layaway, attributes: $criterion === DuplicateCriterion::FileHash
                ? ['file_hash' => $hash, 'reference' => '7777777777']
                : ['bank' => 'Banesco', 'reference' => '0123456789']);
        });

        try {
            $this->submit($layaway, ['file' => $file]);
            $this->fail('La carrera no chocó contra el único.');
        } catch (DuplicateReceiptException $exception) {
            $this->assertSame($criterion, $exception->criterion);
            $this->assertInstanceOf(UniqueConstraintViolationException::class, $exception->getPrevious());
        }

        $this->assertTrue($rivalInserted);
        $this->assertSame([], Storage::disk(Receipt::DISK)->allFiles(), 'El archivo del perdedor se quedó en el disco.');
    }

    /**
     * @return array<string, array{DuplicateCriterion}>
     */
    public static function races(): array
    {
        return [
            'mismo archivo' => [DuplicateCriterion::FileHash],
            'misma referencia' => [DuplicateCriterion::BankReference],
        ];
    }

    /**
     * **RB-10, SP-12 · se acepta con la cuota de almacenamiento agotada.** El
     * plan está en su techo de `storage_mb` y la subida pasa igual: el
     * perjudicado de bloquearla sería el cliente.
     */
    public function test_it_is_accepted_with_the_storage_quota_exhausted(): void
    {
        $this->subscribe($this->organization);
        $this->within(function (): void {
            $ceiling = (int) app(LimitEnforcer::class)->plan()->max_storage_mb;
            UsageCounter::query()->updateOrCreate(['metric' => UsageMetric::StorageMb], ['value' => $ceiling + 50, 'computed_at' => now()]);
            $this->assertSame(0, app(LimitEnforcer::class)->remaining(UsageMetric::StorageMb), 'El plan no está en su techo: el test no prueba nada.');
        });

        $receipt = $this->submit($this->layawayIn($this->organization));

        $this->assertSame(ReceiptStatus::PendingReview, $receipt->status);
        Storage::disk(Receipt::DISK)->assertExists($receipt->file_path);
    }

    /**
     * RB-11 · con el negocio suspendido, el cliente sigue pudiendo pagar.
     */
    public function test_it_works_with_the_organization_suspended(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $receipt = $this->submit($layaway);

        $this->assertSame(ReceiptStatus::PendingReview, $receipt->status);
    }

    /**
     * RB-6 de `REQ-PAY-002` · la recompresión se encola **después del
     * commit**: dentro de una transacción que sigue abierta, nada; al
     * confirmarse, el job con el comprobante y su organización.
     */
    public function test_the_compression_is_queued_after_the_commit(): void
    {
        Queue::fake();
        $layaway = $this->layawayIn($this->organization);

        $receipt = DB::transaction(function () use ($layaway): Receipt {
            $receipt = $this->submit($layaway);
            Queue::assertNothingPushed();

            return $receipt;
        });

        Queue::assertPushedOn('default', CompressReceiptImage::class, fn (CompressReceiptImage $job): bool => $job->receiptId === (int) $receipt->getKey()
            && $job->organizationId === (int) $this->organization->getKey());
    }

    /**
     * Y si la transacción de fuera se deshace, el job no sale: el
     * comprobante no existe.
     */
    public function test_nothing_is_queued_if_the_surrounding_transaction_rolls_back(): void
    {
        Queue::fake();
        $layaway = $this->layawayIn($this->organization);

        try {
            DB::transaction(function () use ($layaway): void {
                $this->submit($layaway);

                throw new RuntimeException('Algo falló después.');
            });
        } catch (RuntimeException) {
            // Lo esperado.
        }

        Queue::assertNothingPushed();
    }

    /**
     * Un despacho que falla **después** del commit —la cola caída— no borra
     * el archivo de un comprobante que ya existe (`REQ-PAY-002` RB-8) ni
     * convierte en error una subida que quedó guardada.
     */
    public function test_a_failed_dispatch_after_the_commit_keeps_the_receipt_and_its_file(): void
    {
        $layaway = $this->layawayIn($this->organization);
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('La cola no responde.'));

        $receipt = $this->submit($layaway);

        Storage::disk(Receipt::DISK)->assertExists($receipt->file_path);
        $this->assertSame(1, DB::table('receipts')->where('id', $receipt->getKey())->count());
    }

    /**
     * Con la cola `sync` —la de los tests y la del desarrollo sin *worker*—
     * el job corre dentro de la subida, recomprime de verdad, y **la petición
     * conserva su organización**: `RunsInTenantContext` la limpia al terminar
     * y la acción la restaura.
     */
    public function test_with_a_sync_queue_the_image_is_compressed_and_the_tenant_survives(): void
    {
        $this->assertSame('sync', config('queue.default'));
        $layaway = $this->layawayIn($this->organization);
        $this->actingAsOrganization($this->organization);

        $receipt = $this->submit($layaway, ['file' => $this->receiptImage(2400, 1200)]);

        $this->assertSame((int) $this->organization->getKey(), app(TenantContext::class)->organizationId());
        $dimensions = getimagesizefromstring((string) Storage::disk(Receipt::DISK)->get($receipt->file_path));
        $this->assertNotFalse($dimensions);
        $this->assertSame([2000, 1000], [$dimensions[0], $dimensions[1]]);
    }

    // -------------------------------------------------------------------

    /**
     * Sube un comprobante del apartado, con su organización fijada como la
     * fijaría el portal.
     *
     * @param  array<string, mixed>  $overrides  argumentos de `SubmitReceiptData` por nombre
     */
    private function submit(Layaway $layaway, array $overrides = []): Receipt
    {
        $arguments = $overrides + [
            'layaway' => $layaway,
            'paymentAccount' => null,
            'method' => PaymentMethod::PagoMovil,
            'bank' => 'Banesco',
            'reference' => '0123456789',
            'paidAt' => CarbonImmutable::parse('2026-10-05', 'Asia/Tokyo'),
            'declaredAmount' => '850.00',
            'declaredCurrency' => Currency::VES,
            'note' => null,
            'file' => $overrides['file'] ?? $this->receiptImage(),
        ];

        /** @var Organization $organization */
        $organization = Organization::query()->findOrFail($layaway->organization_id);

        return $this->withinOrganization($organization, fn (): Receipt => app(SubmitReceiptAction::class)->handle(new SubmitReceiptData(...$arguments)));
    }

    /**
     * @param  callable(): mixed  $submission
     */
    private function refused(callable $submission): DuplicateReceiptException
    {
        try {
            $submission();
        } catch (DuplicateReceiptException $exception) {
            $this->assertSame('Este comprobante ya fue reportado.', $exception->getMessage());

            return $exception;
        }

        $this->fail('El duplicado se aceptó.');
    }

    /**
     * Las columnas del balance del apartado y de sus cuotas, leídas de la
     * base.
     *
     * @return array<string, mixed>
     */
    private function balanceOf(Layaway $layaway): array
    {
        return [
            'layaway' => (array) DB::table('layaways')->where('id', $layaway->getKey())->first(['status', 'paid_cents', 'remaining_cents', 'next_due_date']),
            'installments' => DB::table('installments')->where('layaway_id', $layaway->getKey())->orderBy('number')->get(['id', 'paid_cents', 'status', 'paid_at'])->map(fn (object $row): array => (array) $row)->all(),
        ];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function within(callable $callback): mixed
    {
        return $this->withinOrganization($this->organization, $callback(...));
    }
}
