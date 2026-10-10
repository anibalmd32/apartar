<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Export\ExportStatus;
use App\Domain\Export\ExportType;
use App\Domain\Subscription\CreationSuspendedException;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanLimitReachedException;
use App\Domain\Subscription\UsageCalculator;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\TenantContext;
use App\Exports\CollectionsExport;
use App\Exports\CustomersExport;
use App\Exports\InventoryExport;
use App\Models\ExportJob;
use App\Models\Organization;
use App\Notifications\ExportFinishedNotification;
use App\Queries\CollectionFilterInput;
use App\Queries\CustomerDirectoryFilterInput;
use App\Queries\InventoryFilters;
use App\Support\Settings;
use App\Support\TableState;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Throwable;

/**
 * Genera el `.xlsx` de una exportación (`REQ-BOF-043` RB-2, RB-3, RB-5,
 * RB-6, RB-11, SP-2, SP-6, SP-7, SP-11, `T-070`), en la cola `exports`.
 *
 *  1. **Fija el tenant** de la exportación (RB-11): el job corre fuera de
 *     cualquier petición, y lo que no sepa de quién es no se lee.
 *  2. **Rehidrata los filtros** con la misma regla que la pantalla
 *     (`CollectionFilterInput`, `CustomerDirectoryFilterInput`,
 *     `InventoryFilters`): lo que dejó de valer desde la petición —una
 *     categoría borrada— se ignora y se dice en la cabecera (SP-6), y la
 *     gracia es la vigente **ahora** (`REQ-BOF-042` SP-4).
 *  3. **Lee por `chunkById`** (`StreamsRowsById`) y escribe al disco privado
 *     `exports`, bajo la carpeta de la organización (RB-5). Cero filas es un
 *     archivo con solo la cabecera, no un fallo (SP-7).
 *  4. **Mide el almacenamiento después del `COMMIT`** (`REQ-SUB-003` RB-5):
 *     el archivo cuenta en `storage_mb` mientras esté vigente.
 *  5. **Avisa** a quien la pidió, lista o fallida (RB-6, SP-2).
 *
 * Con la cuota de almacenamiento llena no genera nada: falla con el motivo y
 * la sugerencia de borrar exportaciones (SP-11). Un fallo deja `failed` con
 * su mensaje y borra lo que hubiera llegado al disco; se puede pedir otra.
 */
class GenerateExport implements ShouldQueue
{
    use Queueable;

    /** Un fallo se reintenta pidiendo otra desde la pantalla, no a ciegas. */
    public int $tries = 1;

    /**
     * Segundos antes de que el worker la mate. Por debajo del `retry_after`
     * de la conexión (90 s, `config/queue.php`): por encima, otro worker la
     * tomaría por perdida y la arrancaría otra vez mientras esta sigue. Un
     * worker propio de `exports` con más margen es de producción (`T-087`).
     */
    public int $timeout = 85;

    public function __construct(
        public readonly int $exportId,
        public readonly int $organizationId,
    ) {
        // [90] RB-1: tercera de las cuatro colas; un archivo pesado no
        // retrasa ni un correo de invitación ni un aviso de pago.
        $this->onQueue('exports');
    }

    public function handle(TenantContext $context, LimitEnforcer $limits, UsageCalculator $usage, Settings $settings): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if (! $organization instanceof Organization) {
            return;
        }

        $context->runFor($organization, function () use ($organization, $limits, $usage, $settings): void {
            $export = $this->start();

            if (! $export instanceof ExportJob) {
                return;
            }

            $stored = null;

            try {
                $this->assertRoom($limits);

                $built = $this->build($export, $organization, $settings);
                $stored = sprintf('%d/%s.xlsx', $organization->getKey(), Str::uuid()->toString());

                if (! Excel::store($built, $stored, ExportJob::DISK, ExcelFormat::XLSX)) {
                    throw new RuntimeException('El disco de exportaciones no guardó el archivo.');
                }

                $export->forceFill([
                    'status' => ExportStatus::Completed,
                    'file_path' => $stored,
                    'file_size' => Storage::disk(ExportJob::DISK)->size($stored),
                    'row_count' => $built->rowCount,
                    'completed_at' => CarbonImmutable::now(),
                    'expires_at' => CarbonImmutable::now()->addDays(ExportJob::RETENTION_DAYS),
                ])->save();
            } catch (Throwable $exception) {
                if ($stored !== null) {
                    $this->discard($stored);
                }

                $export->forceFill([
                    'status' => ExportStatus::Failed,
                    'error_message' => mb_substr($this->reasonOf($exception), 0, 500),
                    'completed_at' => CarbonImmutable::now(),
                ])->save();

                if (! $exception instanceof PlanLimitReachedException && ! $exception instanceof CreationSuspendedException) {
                    report($exception);
                }
            }

            $this->measure($organization, $usage);
            $this->notify($export);
        });
    }

    /**
     * Lo que pasa cuando la cola la da por fallida **fuera** del `try`: el
     * worker la mató por tiempo, o se quedó sin memoria (revisión de
     * `T-070`). Sin esto la fila se quedaba en `processing` para siempre, y
     * RB-10 —una en curso por tipo— no dejaba volver a pedir ese tipo nunca.
     * No sabe si llegó a escribir el archivo: si no se guardó su ruta, no hay
     * archivo que cuente ni que descargar.
     */
    public function failed(?Throwable $exception): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if (! $organization instanceof Organization) {
            return;
        }

        app(TenantContext::class)->runFor($organization, function (): void {
            $export = ExportJob::query()->find($this->exportId);

            if (! $export instanceof ExportJob || ! $export->status->isInProgress()) {
                return;
            }

            $export->forceFill([
                'status' => ExportStatus::Failed,
                'error_message' => 'La exportación tardó demasiado y se interrumpió. Prueba con menos filas (más filtros) o vuelve a pedirla.',
                'completed_at' => CarbonImmutable::now(),
            ])->save();

            $this->notify($export);
        });
    }

    /**
     * Borra lo que llegó al disco de una exportación fallida; si ni eso se
     * puede, se informa y la exportación queda `failed` igual.
     */
    private function discard(string $path): void
    {
        try {
            Storage::disk(ExportJob::DISK)->delete($path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Pasa la exportación a `processing`, una sola vez: un job repetido por la
     * cola no la genera dos veces.
     */
    private function start(): ?ExportJob
    {
        return DB::transaction(function (): ?ExportJob {
            $export = ExportJob::query()->whereKey($this->exportId)->lockForUpdate()->first();

            if (! $export instanceof ExportJob || $export->status !== ExportStatus::Queued) {
                return null;
            }

            $export->forceFill(['status' => ExportStatus::Processing, 'started_at' => CarbonImmutable::now()])->save();

            return $export;
        });
    }

    /**
     * SP-11 · con la cuota de almacenamiento llena, no se genera: el archivo
     * no cabría. Se pregunta al plan como cualquier subida del negocio.
     *
     * @throws PlanLimitReachedException
     * @throws CreationSuspendedException
     */
    private function assertRoom(LimitEnforcer $limits): void
    {
        DB::transaction(fn () => $limits->assertCanCreate(UsageMetric::StorageMb));
    }

    /**
     * La exportación de su tipo, con los filtros rehidratados y la cabecera.
     *
     * Los filtros se resuelven **antes** que la cabecera (los argumentos se
     * evalúan de izquierda a derecha): la cabecera dice qué se aplicó y qué
     * se ignoró, y eso lo sabe el `TableState` cuando ya se resolvieron.
     */
    private function build(ExportJob $export, Organization $organization, Settings $settings): CollectionsExport|CustomersExport|InventoryExport
    {
        $state = new TableState(page: 1, perPage: 1, sort: null, descending: false);
        $timezone = $organization->timezone;

        return match ($export->type) {
            ExportType::Collections => new CollectionsExport(
                CollectionFilterInput::resolve($export->filters, $state),
                $this->meta($state, $timezone) + ['Días de gracia' => (string) $settings->graceDays()],
                $timezone,
            ),
            ExportType::Customers => new CustomersExport(
                CustomerDirectoryFilterInput::resolve($export->filters, $state),
                $this->meta($state, $timezone),
            ),
            ExportType::Inventory => new InventoryExport(
                InventoryFilters::resolve($export->filters, $state),
                $this->meta($state, $timezone),
                $timezone,
            ),
        };
    }

    /**
     * La cabecera: cuándo, con qué filtros y cuáles se ignoraron (SP-6).
     *
     * @param  TableState<*>  $state
     * @return array<string, string>
     */
    private function meta(TableState $state, string $timezone): array
    {
        $meta = ['Generado el' => CarbonImmutable::now($timezone)->format('d/m/Y H:i')];
        $applied = array_filter($state->filters(), fn (mixed $value): bool => $value !== null);
        $meta['Filtros'] = $applied === [] ? 'ninguno' : implode('; ', array_map(
            fn (string $key, mixed $value): string => $key.': '.(is_array($value) ? implode(', ', $value) : (string) $value),
            array_keys($applied),
            $applied,
        ));

        if ($state->ignored() !== []) {
            $meta['Filtros ignorados'] = implode(', ', $state->ignored()).' (ya no existen o dejaron de valer)';
        }

        return $meta;
    }

    /**
     * Lo que se le dice a quien la pidió, sin detalles internos.
     */
    private function reasonOf(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof PlanLimitReachedException => 'Tu almacenamiento está lleno. Libera espacio (imágenes de productos que ya no uses; las exportaciones se borran solas a los siete días) o sube de plan, y vuelve a pedirla.',
            $exception instanceof CreationSuspendedException => $exception->getMessage(),
            default => 'No pudimos generar el archivo. Vuelve a pedirlo; si se repite, escríbenos.',
        };
    }

    /**
     * `storage_mb`, después del `COMMIT`: el archivo nuevo pesa. Un fallo al
     * medir no deshace la exportación; la noche lo cuadra.
     */
    private function measure(Organization $organization, UsageCalculator $usage): void
    {
        try {
            $usage->refresh($organization, UsageMetric::StorageMb);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * RB-6, SP-2 · el aviso a quien la pidió; un fallo al avisar no cambia el
     * estado de la exportación.
     */
    private function notify(ExportJob $export): void
    {
        $requester = $export->requester;

        if ($requester === null) {
            return;
        }

        try {
            $requester->notify(new ExportFinishedNotification($export->type, $export->status, route('exports.index')));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
