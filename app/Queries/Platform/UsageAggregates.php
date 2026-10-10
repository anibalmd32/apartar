<?php

declare(strict_types=1);

namespace App\Queries\Platform;

use App\Domain\Catalog\ProductStatus;
use App\Domain\Export\ExportStatus;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Subscription\UsageMetric;
use App\Models\Catalog;
use App\Models\ExportJob;
use App\Models\Layaway;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Lo que cada organización consume **de verdad**, medido en sus tablas
 * (`REQ-SUB-003` RB-2). Es **la única definición** de las seis métricas: la
 * usan el recálculo diario, el de una sola organización y el alta de una
 * nueva, y una segunda copia sería la que se quedara corta en silencio.
 *
 * **Una consulta por métrica para todo el lote** (SP-7): `whereIn` de las
 * organizaciones y `groupBy`, nunca una por organización —dos para el
 * almacenamiento, comprobantes e imágenes—. Con 10.000 organizaciones en lotes
 * de 200, son siete consultas por lote, no sesenta mil.
 *
 * Vive en `app/Queries/Platform` porque **cruza organizaciones**, y es de las
 * pocas rutas donde `withoutTenantScope()` está permitido (`REQ-QAX-008`
 * RB-4b). Que el filtro de tenant se quite no deja la consulta abierta: cada
 * una se acota por `organization_id IN (…)` con los ids que recibe, y una
 * organización que no aparece en el resultado consume cero.
 *
 * ## Las definiciones, y de dónde sale cada una
 *
 * Los estados que cuentan **no están escritos aquí**: los responde el propio
 * tipo (`ProductStatus`, `LayawayStatus`, `CatalogVisibility`), que es quien
 * los usa también para mover el contador en cada mutación. Si un estado nuevo
 * aparece, el `match` de su enum le obliga a decidir de qué lado cae, y esta
 * consulta lo hereda. La excepción son los catálogos, que no tienen columna de
 * estado: su visibilidad se deriva de las fechas (`Catalog::visibility()`), y
 * aquí se reescribe en SQL —publicado y sin caducar—. Por eso hay un test que
 * recorre los cuatro casos y compara las dos lecturas.
 *
 * ## El almacenamiento, en MB redondeados hacia arriba
 *
 * Se suman bytes —`receipts.file_size` y `media.size`, que es lo que pesa el
 * original de cada imagen de producto— y se convierte **una vez, al final, por
 * organización**, con `ceil(bytes / 1 MiB)`. Hacia arriba porque el techo se
 * escribe en MB enteros y medio mega sigue ocupando disco: redondear hacia
 * abajo dejaría a un negocio con 1.000,4 MB dentro de un plan de 1.000. Y al
 * final y no por archivo, porque cien comprobantes de 30 KB no son cien megas.
 *
 * Lo que **no** se cuenta, a sabiendas: las conversiones WebP de cada imagen
 * (`ImageSet`), que no tienen fila ni tamaño en la base —las genera la
 * plataforma para servir más rápido, y cobrárselas al negocio sería cobrarle
 * una decisión nuestra—, y las exportaciones, que no existen todavía: su
 * brazo lo añade `T-070` con su tabla.
 */
final class UsageAggregates
{
    private const BYTES_PER_MB = 1024 * 1024;

    /**
     * Consumo de una métrica por organización, con cero para las que no tienen
     * nada.
     *
     * Con `$locking`, cada consulta lleva un bloqueo compartido
     * (`LOCK IN SHARE MODE`): lee lo último confirmado en lugar de la
     * instantánea de la transacción, y no deja que nadie cambie lo contado
     * hasta el `COMMIT`. Es lo que usa `UsageCalculator::recountLocked()`.
     *
     * @param  list<int>  $organizationIds
     * @return array<int, int> consumo por `organization_id`, con todas las pedidas
     */
    public function measure(UsageMetric $metric, array $organizationIds, ?CarbonImmutable $now = null, bool $locking = false): array
    {
        if ($organizationIds === []) {
            return [];
        }

        $now ??= CarbonImmutable::now();

        // Exhaustivo y sin `default`: una métrica nueva tiene que decidir aquí
        // cómo se mide en vez de caer por descuido en un cero.
        $measured = match ($metric) {
            // Las invitaciones pendientes **ya están**: el invitado es una
            // fila de `users` sin contraseña y sin desactivar desde que se
            // envía, y por eso consume cupo desde ese instante (`REQ-ORG-004`
            // RB-2). Sumarlas aparte las contaría dos veces.
            UsageMetric::Users => $this->countPer(
                User::query()->withoutTenantScope()->whereNull('disabled_at'),
                $organizationIds,
                $locking,
            ),

            UsageMetric::Catalogs => $this->countPer(
                Catalog::query()->withoutTenantScope()
                    ->whereNotNull('published_at')
                    ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>=', $now)),
                $organizationIds,
                $locking,
            ),

            UsageMetric::Products => $this->countPer(
                Product::query()->withoutTenantScope()->whereIn('status', $this->productStatusesThatCount()),
                $organizationIds,
                $locking,
            ),

            UsageMetric::ActiveLayaways => $this->countPer(
                Layaway::query()->withoutTenantScope()->whereIn('status', $this->layawayStatusesThatCount()),
                $organizationIds,
                $locking,
            ),

            UsageMetric::StorageMb => $this->megabytesPer($organizationIds, $locking),

            UsageMetric::PaymentAccounts => $this->countPer(
                PaymentAccount::query()->withoutTenantScope()->where('is_active', true),
                $organizationIds,
                $locking,
            ),
        };

        return array_replace(array_fill_keys($organizationIds, 0), $measured);
    }

    /**
     * Una sola consulta `COUNT(*) … GROUP BY organization_id` para el lote.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<int>  $organizationIds
     * @return array<int, int>
     */
    private function countPer(Builder $query, array $organizationIds, bool $locking): array
    {
        $table = $query->getModel()->getTable();

        return $this->totals($query
            ->whereIn("{$table}.organization_id", $organizationIds)
            ->groupBy("{$table}.organization_id")
            ->selectRaw("{$table}.organization_id AS organization, COUNT(*) AS total"), $locking);
    }

    /**
     * Comprobantes, imágenes de producto y exportaciones vigentes (`T-070`),
     * en bytes, y convertido a MB al final.
     *
     * `media` es polimórfica y **no tiene `organization_id`**: la organización
     * de una imagen es la de su producto, así que se une por
     * `model_type`/`model_id`. Los productos borrados lógicamente **cuentan**:
     * su fila sigue y sus archivos también —medialibrary solo los borra al
     * borrar de verdad—, y lo que se cobra es el disco ocupado, no lo que se
     * ve.
     *
     * @param  list<int>  $organizationIds
     * @return array<int, int>
     */
    private function megabytesPer(array $organizationIds, bool $locking): array
    {
        $megabytes = [];

        foreach ($this->bytesPer($organizationIds, $locking) as $organization => $bytes) {
            $megabytes[$organization] = self::megabytes($bytes);
        }

        return $megabytes;
    }

    /**
     * Los mismos bytes que `storage_mb` suma, sin redondear todavía: para
     * saber cuántos MB **más** ocupa un archivo hay que redondear una vez, el
     * total con él, y no sumar dos redondeos (`T-067`).
     *
     * @param  list<int>  $organizationIds
     * @return array<int, int> bytes por `organization_id`, con todas las pedidas
     */
    public function bytesPer(array $organizationIds, bool $locking = false): array
    {
        $receipts = $this->totals(Receipt::query()->withoutTenantScope()
            ->whereIn('organization_id', $organizationIds)
            ->groupBy('organization_id')
            ->selectRaw('organization_id AS organization, SUM(file_size) AS total'), $locking);

        $media = $this->totals(Product::query()->withoutTenantScope()->withTrashed()
            ->join('media', function ($join): void {
                $join->on('media.model_id', '=', 'products.id')
                    ->where('media.model_type', '=', (new Product)->getMorphClass());
            })
            ->whereIn('products.organization_id', $organizationIds)
            ->groupBy('products.organization_id')
            ->selectRaw('products.organization_id AS organization, SUM(media.size) AS total'), $locking);

        // `T-070` · las exportaciones **vigentes**: terminadas y sin purgar.
        // Una caducada ya no tiene archivo, y una en curso todavía no pesa.
        $exports = $this->totals(ExportJob::query()->withoutTenantScope()
            ->where('status', ExportStatus::Completed)
            ->whereIn('organization_id', $organizationIds)
            ->groupBy('organization_id')
            ->selectRaw('organization_id AS organization, SUM(file_size) AS total'), $locking);

        $bytes = [];

        foreach ($organizationIds as $organization) {
            $bytes[$organization] = (int) ($receipts[$organization] ?? 0) + (int) ($media[$organization] ?? 0) + (int) ($exports[$organization] ?? 0);
        }

        return $bytes;
    }

    /**
     * Bytes a MB enteros, hacia arriba: la única conversión de `storage_mb`.
     */
    public static function megabytes(int $bytes): int
    {
        return (int) ceil($bytes / self::BYTES_PER_MB);
    }

    /**
     * Las filas `organization, total` de una agregación, como mapa.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return array<int, int>
     */
    private function totals(Builder $query, bool $locking): array
    {
        $totals = [];

        if ($locking) {
            $query->sharedLock();
        }

        foreach ($query->toBase()->get() as $row) {
            $totals[(int) $row->organization] = (int) $row->total;
        }

        return $totals;
    }

    /**
     * @return list<string>
     */
    private function productStatusesThatCount(): array
    {
        return array_values(array_map(
            fn (ProductStatus $status): string => $status->value,
            array_filter(ProductStatus::cases(), fn (ProductStatus $status): bool => $status->countsTowardPlanLimit()),
        ));
    }

    /**
     * @return list<string>
     */
    private function layawayStatusesThatCount(): array
    {
        return array_values(array_map(
            fn (LayawayStatus $status): string => $status->value,
            array_filter(LayawayStatus::cases(), fn (LayawayStatus $status): bool => $status->countsTowardPlanLimit()),
        ));
    }
}
