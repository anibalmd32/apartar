<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Domain\Payment\DuplicateCriterion;
use App\Domain\Payment\DuplicateReceiptException;
use App\Domain\Payment\LayawayReceipts;
use App\Domain\Payment\PaymentNotApplicableException;
use App\Domain\Payment\ReceiptFile;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Subscription\UsageCalculator;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\TenantContext;
use App\Events\ReceiptSubmitted;
use App\Jobs\CompressReceiptImage;
use App\Models\Layaway;
use App\Models\Receipt;
use App\Rules\RealMimeType;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * El cliente reporta un pago subiendo su comprobante (`REQ-PAY-001`,
 * `REQ-PAY-002`, `REQ-PAY-003`).
 *
 * Lo que sale de aquí es un `Receipt` en `pending_review` y su archivo en el
 * disco privado. **No toca el balance ni las cuotas** (RB-6): lo que mueve el
 * dinero es la aprobación (`T-061`), y hasta entonces el comprobante es una
 * declaración del cliente.
 *
 * ## En orden
 *
 *  1. **El `sha256` del archivo tal cual se subió** (`REQ-PAY-003` RB-7), y su
 *     tipo real por contenido. Antes de abrir la transacción: es una cuenta
 *     sobre el archivo, no depende de nada que se bloquee, y hacerla dentro
 *     alargaría el bloqueo del apartado lo que tarde en leerse un PDF de 5 MB.
 *  2. **El apartado, con `FOR UPDATE`** y por el global scope —el de otro
 *     negocio no existe para esta búsqueda—, y su estado comprobado **bajo el
 *     bloqueo**: un terminal no admite comprobantes (RB-1, SP-4). Una
 *     cancelación concurrente que gane deja este envío fuera, y una que pierda
 *     se encuentra el comprobante y lo rechaza (`LayawayReceipts`).
 *  3. **Los duplicados, dentro de la organización** (`REQ-PAY-003`): el mismo
 *     hash, o la misma pareja banco y referencia cuando hay referencia (RB-3).
 *     **También contra los rechazados** (RB-5): un rechazo no libera nada.
 *  4. **El archivo, dentro de la transacción** (SP-6), en el disco `receipts`
 *     bajo `{organization_id}/{uuid}.{ext}` (`REQ-PAY-002` RB-4, RB-7). La
 *     organización sale **del apartado**, no del contexto, y la extensión del
 *     tipo real, no del nombre que trajo el archivo.
 *  5. **El comprobante**, en `pending_review`, con lo que declaró el cliente
 *     tal cual —el importe sin convertir (RB-5) y la fecha como el día que
 *     es—, **y encadenado al rechazado al que sustituye** (`REQ-PAY-006`
 *     RB-4): si el último comprobante del apartado está rechazado y nadie lo
 *     resolvió con un pago, este es su reintento. Se mira bajo el bloqueo del apartado, así que dos envíos a la
 *     vez no se encadenan los dos al mismo.
 *  6. **La recompresión, después del commit** (`REQ-PAY-002` RB-6): un job
 *     despachado dentro de la transacción podría correr antes de que la fila
 *     exista para su conexión, o correr para una fila que se deshizo. Solo
 *     para JPEG y PNG, que son lo único que el job sabe recomprimir.
 *  7. **El aviso a la bandeja, también después del commit** (RB-8,
 *     `REQ-PAY-004`): `ReceiptSubmitted` por el canal de la organización. Un
 *     aviso de algo que se deshizo mandaría al negocio a buscar un comprobante
 *     que no existe; y uno que falla **no deshace nada** (SP-6 de
 *     `REQ-PAY-004`): se informa y la subida sigue siendo un éxito.
 *
 * ## Si algo falla, el archivo no se queda
 *
 * SP-6: «nada se persiste». La fila la deshace el rollback; el archivo no lo
 * deshace nadie, así que se borra a mano al salir por la excepción. Es la
 * única vez que la aplicación borra un comprobante (`REQ-PAY-002` RB-8), y es
 * porque no llegó a existir.
 *
 * Esto cubre lo que falla **dentro** de esta transacción, que es la frontera:
 * se espera que la llame el controlador, sin otra abierta. Si alguien la
 * envolviera en una suya y la deshiciera después, el archivo quedaría
 * huérfano —sin fila, en la carpeta de su organización—: no se filtra ni se
 * sirve, pero ocupa.
 *
 * ## Dos barreras contra el duplicado
 *
 * La comprobación del paso 3 es la primera, y la que da el mensaje. Dos
 * subidas simultáneas del mismo archivo pasan las dos por ella sin verse
 * (SP-5); entonces es `UNIQUE(organization_id, file_hash)` o
 * `UNIQUE(organization_id, bank, reference)` quien choca, y la violación se
 * traduce aquí a la misma `DuplicateReceiptException` con su criterio. El
 * cliente que pierde la carrera ve lo mismo que el que llega tarde.
 *
 * ## Lo que no hace
 *
 *  - **No mira la cuota de almacenamiento del plan** (RB-10, SP-12): la
 *    subida de un comprobante de cliente nunca se rechaza por ella. El peso
 *    **sí se cuenta** —`storage_mb` se vuelve a sumar tras el `COMMIT`
 *    (`T-066`)—; bloquear aquí rompería la cobranza, y el perjudicado sería
 *    el cliente.
 *  - **No pregunta por la suspensión del negocio** (RB-11): el portal del
 *    cliente sigue funcionando con la organización `suspended`.
 *  - **No emite `receipt_uploaded`** (RB-8): la telemetría es de `T-084`.
 *  - **No valida el reloj** (RB-4, SP-5): `paid_at` futura o anterior al
 *    apartado es del formulario, que sabe qué día es para quien sube.
 *  - **No comprueba la cuenta de cobro**: `SubmitReceiptData` ya no se puede
 *    construir con una de otro negocio (SP-7).
 */
final class SubmitReceiptAction
{
    /** El nombre que Laravel da a los únicos de `receipts` (migración de `T-054`). */
    private const HASH_INDEX = 'receipts_organization_id_file_hash_unique';

    private const REFERENCE_INDEX = 'receipts_organization_id_bank_reference_unique';

    public function __construct(
        private readonly TenantContext $context,
        private readonly LayawayReceipts $receipts,
        private readonly UsageCalculator $usage,
    ) {}

    /**
     * Crea el comprobante en revisión y guarda su archivo.
     *
     * @throws ModelNotFoundException si el apartado no es de la organización fijada
     * @throws PaymentNotApplicableException si el apartado ya no admite pagos (SP-4)
     * @throws DuplicateReceiptException si el archivo o la referencia ya se reportaron en esa organización (`REQ-PAY-003`)
     * @throws InvalidArgumentException si el archivo no es de un tipo de comprobante o pasa del tamaño máximo
     */
    public function handle(SubmitReceiptData $data): Receipt
    {
        $file = $data->file;
        [$mime, $extension] = $this->typeOf($file);
        $hash = ReceiptFile::fingerprint($file);
        $disk = Storage::disk(Receipt::DISK);
        $stored = null;

        try {
            return DB::transaction(function () use ($data, $file, $mime, $extension, $hash, $disk, &$stored): Receipt {
                /** @var Layaway $layaway */
                $layaway = Layaway::query()->whereKey($data->layaway->getKey())->lockForUpdate()->firstOrFail();

                if (! $layaway->status->acceptsPayments()) {
                    throw PaymentNotApplicableException::layawayNotCollecting($layaway->status);
                }

                $criterion = $this->duplicateOf($hash, $data->bank, $data->reference);

                if ($criterion !== null) {
                    throw new DuplicateReceiptException($criterion);
                }

                // RB-4 de `REQ-PAY-006` · el reintento de un rechazado que nada
                // ha resuelto todavía (`LayawayReceipts::rejectedToReplace()`).
                $replaces = $this->receipts->rejectedToReplace($layaway)?->getKey();

                // Se apunta **antes** de escribir: si la escritura se queda a
                // medias y lanza, lo que haya llegado al disco también se borra.
                $stored = ReceiptFile::pathFor((int) $layaway->organization_id, $extension);
                $this->store($disk, $file, $stored);

                $receipt = new Receipt;
                $receipt->forceFill([
                    'organization_id' => $layaway->organization_id,
                    'layaway_id' => $layaway->getKey(),
                    'customer_id' => $layaway->customer_id,
                    'payment_account_id' => $data->paymentAccount?->getKey(),
                    'replaces_receipt_id' => $replaces,
                    'payment_method' => $data->method,
                    'bank' => $data->bank,
                    'reference' => $data->reference,
                    // El día que dice el cliente, tal cual: es una fecha de su
                    // calendario, no un instante que haya que llevar a otra zona.
                    'paid_at' => $data->paidAt->toDateString(),
                    'declared_amount' => $data->declaredAmount,
                    'declared_currency' => $data->declaredCurrency,
                    'note' => $data->note,
                    'file_path' => $stored,
                    'file_hash' => $hash,
                    'file_mime' => $mime,
                    'file_size' => (int) $file->getSize(),
                    'status' => ReceiptStatus::PendingReview,
                ])->save();

                // Confirmada la fila, el archivo ya es de un comprobante que
                // existe y no se borra nunca (RB-8), pase lo que pase después.
                // Es el primer callback, así que corre antes que el despacho.
                DB::afterCommit(function () use (&$stored): void {
                    $stored = null;
                });

                $this->compressAfterCommit($receipt, $mime);
                $this->measureStorageAfterCommit();
                $this->announceAfterCommit($receipt, $layaway->code);

                return $receipt;
            });
        } catch (Throwable $exception) {
            if ($stored !== null) {
                $this->discard($disk, $stored);
            }

            if ($exception instanceof UniqueConstraintViolationException) {
                throw $this->duplicateFrom($exception);
            }

            throw $exception;
        }
    }

    /**
     * Qué criterio de `REQ-PAY-003` RB-2 coincide con ese archivo y esa
     * referencia **en la organización fijada**, o `null` si ninguno.
     *
     * Pública porque la pregunta es la misma para la regla del formulario
     * (`UniqueReceiptFingerprint`) que para esta acción, y si cada una la
     * escribiera a su manera la primera barrera y la segunda acabarían
     * mirando cosas distintas.
     *
     * Sin filtro de estado, a propósito: un rechazado también cuenta (RB-5).
     * Y sin filtro de organización escrito, también a propósito: lo pone el
     * global scope, el mismo mecanismo que aísla el resto de lecturas del
     * tenant, y lo que esta consulta no ve es lo de otros negocios (RB-4).
     */
    public function duplicateOf(string $fileHash, ?string $bank, ?string $reference): ?DuplicateCriterion
    {
        if ($this->fileTaken($fileHash)) {
            return DuplicateCriterion::FileHash;
        }

        return $this->referenceTaken($bank, $reference) ? DuplicateCriterion::BankReference : null;
    }

    /**
     * Criterio (a): ese archivo ya se reportó en la organización fijada.
     */
    public function fileTaken(string $fileHash): bool
    {
        return Receipt::query()->where('file_hash', $fileHash)->exists();
    }

    /**
     * Criterio (b): esa pareja banco + referencia ya se reportó en la
     * organización fijada. RB-3: sin referencia —el efectivo— no hay criterio,
     * y el único de la tabla tampoco ve una pareja con `NULL`: los dos dicen
     * lo mismo.
     *
     * Por separado del (a) para que el formulario devuelva cada duplicado en
     * su campo: el archivo en `file`, la referencia en `reference`.
     */
    public function referenceTaken(?string $bank, ?string $reference): bool
    {
        return $reference !== null && Receipt::query()->where('bank', $bank)->where('reference', $reference)->exists();
    }

    /**
     * El tipo real del archivo, por su contenido, y la extensión con que se
     * guarda, que sale de ese tipo y no del nombre.
     *
     * Es la misma comprobación que `RealMimeType::receipts()` hace en el
     * formulario, repetida aquí porque lo que entra en el disco lo decide esta
     * acción: si un formulario se olvida de la regla, un ejecutable no tiene
     * que acabar guardado como comprobante.
     *
     * @return array{0: string, 1: string}
     *
     * @throws InvalidArgumentException si no es un tipo de comprobante o pasa del tamaño máximo
     */
    private function typeOf(UploadedFile $file): array
    {
        $mime = (string) $file->getMimeType();
        $extensions = RealMimeType::RECEIPT_TYPES[$mime] ?? null;

        if ($extensions === null) {
            throw new InvalidArgumentException(sprintf(
                'El archivo es %s, que no es un tipo de comprobante (REQ-PAY-002 RB-5). El formulario tenía que haberlo rechazado con RealMimeType::receipts().',
                $mime,
            ));
        }

        if ((int) $file->getSize() > ReceiptFile::MAX_SIZE_MB * 1024 * 1024) {
            throw new InvalidArgumentException(sprintf(
                'El archivo pesa %d bytes y el máximo de un comprobante es de %d MB (REQ-PAY-002 RB-5).',
                (int) $file->getSize(),
                ReceiptFile::MAX_SIZE_MB,
            ));
        }

        return [$mime, $extensions[0]];
    }

    /**
     * @throws RuntimeException si el disco no lo escribió
     */
    private function store(Filesystem $disk, UploadedFile $file, string $path): void
    {
        if ($disk->putFileAs(dirname($path), $file, basename($path)) === false) {
            throw new RuntimeException(sprintf('El disco «%s» no guardó el comprobante en %s.', Receipt::DISK, $path));
        }
    }

    /**
     * Borra el archivo de un comprobante que no llegó a existir (SP-6).
     *
     * Si el borrado también falla, se informa y se sigue: lo que tiene que
     * llegar a quien llamó es la causa del fallo, no un error del disco al
     * limpiar que la tape.
     */
    private function discard(Filesystem $disk, string $path): void
    {
        try {
            $disk->delete($path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Vuelve a sumar `storage_mb` cuando la transacción se confirme
     * (`REQ-SUB-003` SP-4, `REQ-PAY-002` RB-10).
     *
     * **Suma, nunca decide**: aquí no hay `assertCanCreate()`, y no es un
     * olvido. Un comprobante de cliente se acepta con la cuota agotada
     * (`REQ-PAY-001` RB-10): bloquearlo rompería la cobranza del negocio y el
     * perjudicado sería su cliente. Lo que pesa se cuenta igual, para que el
     * negocio lo vea.
     *
     * Después del `COMMIT` porque la medida del almacenamiento tiene que abrir
     * su propia transacción (`UsageCalculator`), y registrado detrás del
     * despacho de la recompresión: con la cola `sync` el archivo ya llega
     * recomprimido. Y como el despacho, un fallo aquí se informa y la subida
     * sigue siendo un éxito: el recálculo de la noche cuadra la cifra.
     */
    private function measureStorageAfterCommit(): void
    {
        $organization = $this->context->organization();

        DB::afterCommit(function () use ($organization): void {
            try {
                $this->usage->refresh($organization, UsageMetric::StorageMb);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * Despacha la recompresión cuando la transacción se confirme, y nunca si
     * se deshace.
     *
     * Con el tenant del apartado fijado alrededor del despacho, porque con la
     * cola `sync` —la de los tests— el job corre aquí mismo, y
     * `RunsInTenantContext` limpia el contexto al terminar: sin `runFor()`, la
     * petición seguiría sin organización después de subir un comprobante.
     *
     * Y si el despacho falla —la cola caída, o el job que lanza con `sync`—,
     * se informa y la subida sigue siendo un éxito: el comprobante ya está
     * confirmado y se revisa igual sin recomprimir (SP-7). Responder con un
     * error a un pago que sí quedó guardado solo haría que el cliente lo
     * reintentara y se encontrara con «ya fue reportado».
     */
    private function compressAfterCommit(Receipt $receipt, string $mime): void
    {
        if (! CompressReceiptImage::compresses($mime)) {
            return;
        }

        $organization = $this->context->organization();
        $receiptId = (int) $receipt->getKey();

        // `Bus::dispatch()` y no `::dispatch()`: este último devuelve un
        // `PendingDispatch` que despacha al destruirse, y eso pasaría ya fuera
        // de `runFor()`, con el contexto restaurado y listo para que el job lo
        // borre.
        DB::afterCommit(function () use ($organization, $receiptId): void {
            try {
                $this->context->runFor($organization, function () use ($organization, $receiptId): void {
                    Bus::dispatch(new CompressReceiptImage($receiptId, (int) $organization->getKey()));
                });
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * Avisa a la bandeja del negocio cuando la transacción se confirme, y
     * nunca si se deshace (`REQ-PAY-004`).
     *
     * El evento se arma **ahora**, con la fila recién escrita, y se emite
     * después: dentro del *callback* ya no hay transacción, y volver a leer el
     * comprobante sería una consulta para saber lo que ya se sabe.
     *
     * Con el tenant del apartado fijado alrededor, por lo mismo que la
     * recompresión: con la cola `sync` la difusión corre aquí mismo, y nada de
     * lo que corra dentro tiene que poder dejar la petición sin organización.
     *
     * Un fallo —Reverb caído, la cola caída, el broadcaster que lanza con
     * `sync`— se informa y se sigue (SP-6): el comprobante está confirmado, la
     * bandeja lo enseña en la próxima carga y el contador lo recoge en su
     * siguiente consulta. Responder con un error haría que el cliente lo
     * reintentara y se encontrara con «ya fue reportado».
     */
    private function announceAfterCommit(Receipt $receipt, string $layawayCode): void
    {
        $organization = $this->context->organization();
        $event = ReceiptSubmitted::of($receipt, $layawayCode);

        DB::afterCommit(function () use ($organization, $event): void {
            try {
                $this->context->runFor($organization, fn () => event($event));
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * La carrera de SP-5: la otra subida ganó y el único de la tabla lo dice.
     * Cualquier otra violación de un único no es un duplicado y sube tal cual.
     */
    private function duplicateFrom(UniqueConstraintViolationException $exception): Throwable
    {
        $criterion = match (true) {
            str_contains($exception->getMessage(), self::HASH_INDEX) => DuplicateCriterion::FileHash,
            str_contains($exception->getMessage(), self::REFERENCE_INDEX) => DuplicateCriterion::BankReference,
            default => null,
        };

        return $criterion === null ? $exception : new DuplicateReceiptException($criterion, $exception);
    }
}
