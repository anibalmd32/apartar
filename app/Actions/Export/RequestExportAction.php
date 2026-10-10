<?php

declare(strict_types=1);

namespace App\Actions\Export;

use App\Domain\Export\ExportStatus;
use App\Domain\Export\ExportType;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Subscription\PlanFeatureUnavailableException;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\GenerateExport;
use App\Models\Customer;
use App\Models\ExportJob;
use App\Models\Layaway;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Pide una exportación a Excel (`REQ-BOF-043` RB-1, RB-2, RB-10, `T-070`).
 *
 * **El request no genera nada** (RB-2): crea el `ExportJob` en `queued` y
 * encola `GenerateExport` en la cola `exports` **después del `COMMIT`** —un
 * worker rápido no puede leer una fila que todavía no existe—, y responde.
 *
 * **Una en curso por organización y tipo** (RB-10, SP-5): si ya hay una en
 * cola o generándose, se devuelve esa. La búsqueda va con bloqueo dentro de la
 * transacción, que en `REPEATABLE READ` coge también el hueco del índice
 * `(organization_id, type, status)`: dos peticiones simultáneas no crean dos.
 *
 * Quién puede: el plan tiene que incluir `excel_export` (RB-1; la ruta ya lo
 * cierra con `plan-feature:excel_export`, y aquí se vuelve a preguntar porque
 * la acción es la autoridad, `REQ-SUB-004` SP-7), y la persona tiene que poder
 * ver la pantalla que exporta.
 */
final class RequestExportAction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly LimitEnforcer $limits,
    ) {}

    /**
     * @throws PlanFeatureUnavailableException si el plan no incluye la exportación
     * @throws AuthorizationException si la persona no puede ver lo que exporta
     */
    public function handle(ExportRequestData $data, User $actor): ExportJob
    {
        $this->limits->assertHasFeature(PlanFeature::ExcelExport);
        $this->authorize($data->type, $actor);

        // Con dos intentos más: dos primeras peticiones simultáneas cogen las
        // dos el hueco del índice y una muere por interbloqueo; al reintentar,
        // encuentra la de la otra y la devuelve (SP-5).
        return DB::transaction(function () use ($data, $actor): ExportJob {
            $this->failTheStale($data->type);

            $running = ExportJob::query()
                ->where('type', $data->type)
                ->whereIn('status', [ExportStatus::Queued, ExportStatus::Processing])
                ->lockForUpdate()
                ->first();

            if ($running instanceof ExportJob) {
                return $running;
            }

            $export = new ExportJob;
            $export->forceFill([
                'organization_id' => $this->context->organization()->getKey(),
                'requested_by' => $actor->getKey(),
                'type' => $data->type,
                'status' => ExportStatus::Queued,
                'filters' => $data->filters,
            ])->save();

            GenerateExport::dispatch((int) $export->getKey(), (int) $export->organization_id)->afterCommit();

            return $export;
        }, attempts: 3);
    }

    /**
     * Una en curso desde hace más de `ExportJob::STALE_MINUTES` está muerta:
     * el worker la perdió sin pasar por `GenerateExport::failed()` (un
     * `SIGKILL`, la cola vaciada, la organización borrada entre medias). Se
     * da por fallida para que RB-10 no bloquee ese tipo para siempre; si el job
     * llegara a arrancar después, ya no la encuentra en cola y no hace nada.
     */
    private function failTheStale(ExportType $type): void
    {
        ExportJob::query()
            ->where('type', $type)
            ->whereIn('status', [ExportStatus::Queued, ExportStatus::Processing])
            ->where('created_at', '<', CarbonImmutable::now()->subMinutes(ExportJob::STALE_MINUTES))
            ->lockForUpdate()
            ->get()
            ->each(fn (ExportJob $stale) => $stale->forceFill([
                'status' => ExportStatus::Failed,
                'error_message' => 'Esta exportación se interrumpió. Pide otra.',
                'completed_at' => CarbonImmutable::now(),
            ])->save());
    }

    /**
     * Si la persona puede ver lo que exporta ese tipo: la cobranza y los
     * clientes, el equipo; el inventario, quien lo gestiona. Lo usan también
     * la lista y la descarga (`ExportController`): el archivo de clientes que
     * pidió un compañero no lo abre quien no puede ver los clientes.
     */
    public function allows(ExportType $type, User $actor): bool
    {
        $gate = Gate::forUser($actor);

        return match ($type) {
            ExportType::Collections => $gate->allows('viewAny', Layaway::class),
            ExportType::Customers => $gate->allows('viewAny', Customer::class),
            ExportType::Inventory => $gate->allows('manageProducts', $this->context->organization()),
        };
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(ExportType $type, User $actor): void
    {
        if (! $this->allows($type, $actor)) {
            throw new AuthorizationException(sprintf('No puedes exportar %s.', mb_strtolower($type->label())));
        }
    }
}
