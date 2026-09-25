<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Support\SortWhitelist;
use App\Support\TableState;
use BackedEnum;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * El estado de una tabla server-driven, resuelto una sola vez para todas
 * (`REQ-BOF-045`).
 *
 * Página, tamaño y orden llegan de la query string —la vista es recargable y
 * compartible (RB-3)— y lo que la escribe es cualquiera, así que cada uno pasa
 * por su guarda y **cae a su defecto sin error**:
 *
 *  - `page` no entero o menor que 1 es 1; mayor que la última es la última con
 *    datos, sin `404` (SP-3);
 *  - `per_page` fuera de {25, 50, 100} es 25 (RB-5, SP-2);
 *  - `sort` pasa por `SortWhitelist` (`T-034`): un caso del enum de esa tabla o
 *    el defecto (RB-4, SP-1). Ningún texto de la petición llega al `ORDER BY`.
 *
 * ## Una forma de resolverlo, no una por pantalla
 *
 * `AuditLogController` resolvía su propia página y su propio orden antes de que
 * esto existiera, y funcionaba — que es justo por lo que habría sobrevivido como
 * segundo mecanismo. Ahora lo usa, y de él salieron dos requisitos que el
 * *concern* no tenía: una tabla **sin orden elegible** (el registro va siempre
 * del más reciente al más antiguo) y los filtros **devueltos resueltos**, no
 * crudos. Los dos están aquí.
 *
 * ## Qué no hace
 *
 * No filtra por organización: el global scope ya lo hace, y es lo que hace
 * cierto SP-8 —la misma URL enseña a cada equipo lo suyo— sin que ninguna tabla
 * tenga que acordarse. Y no decide qué filtros existen: eso es de cada listado,
 * que los anota en `TableState::filter()`.
 */
trait ResolvesTableState
{
    /**
     * Los tamaños de página permitidos (RB-5). El primero es el defecto.
     *
     * @var list<int>
     */
    private static array $perPageOptions = [25, 50, 100];

    /**
     * El estado de la tabla desde la petición.
     *
     * @template TSort of BackedEnum
     *
     * @param  SortWhitelist<TSort>|null  $sorts  los órdenes elegibles; `null` si la tabla no deja elegir
     * @param  TSort|null  $defaultSort
     * @return TableState<TSort>
     */
    protected function tableState(
        Request $request,
        ?SortWhitelist $sorts = null,
        ?BackedEnum $defaultSort = null,
        bool $descendingByDefault = false,
    ): TableState {
        $page = filter_var($request->query('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $perPage = filter_var($request->query('per_page'), FILTER_VALIDATE_INT);

        $requested = $request->query('sort');
        $descending = is_string($requested) && str_starts_with($requested, '-');
        $sort = $sorts?->resolve($descending ? substr((string) $requested, 1) : $requested);

        return new TableState(
            page: is_int($page) ? $page : 1,
            perPage: in_array($perPage, self::$perPageOptions, true) ? $perPage : self::$perPageOptions[0],
            sort: $sort ?? $defaultSort,
            descending: $sort === null ? $descendingByDefault : $descending,
        );
    }

    /**
     * Pagina la consulta con el estado resuelto.
     *
     * Completa (con total) por defecto, que es lo que RB-6 permite en las tablas
     * del backoffice que no crecen sin techo; **simple** (sin `count(*)`) para
     * cobranza, clientes, comprobantes y suscriptores. Con la completa, una
     * página desbordada vuelve a la última con datos (SP-3): la cuenta ya se
     * hizo, y un estado vacío con datos detrás desconcierta más que ayuda.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  TableState<*>  $state
     * @return Paginator<int, TModel>
     */
    protected function paginateTable(Builder $query, TableState $state, bool $simple = false): Paginator
    {
        if ($simple) {
            return $query->simplePaginate($state->perPage, ['*'], 'page', $state->page);
        }

        $page = (clone $query)->paginate($state->perPage, ['*'], 'page', $state->page);

        if ($page->isEmpty() && $page->total() > 0 && $state->page > $page->lastPage()) {
            return $query->paginate($state->perPage, ['*'], 'page', $page->lastPage());
        }

        return $page;
    }

    /**
     * La `meta` de la tabla: lo que la recarga parcial devuelve junto a `rows`
     * (RB-2).
     *
     * Lleva los filtros **resueltos** y los ignorados, para que la pantalla
     * repinte lo que se aplicó de verdad y avise de lo que no (SP-4). `total` y
     * `lastPage` son `null` en la paginación simple: no se contaron, y un cero
     * ahí sería una cifra inventada.
     *
     * @template TModel of Model
     *
     * @param  Paginator<int, TModel>  $page
     * @param  TableState<*>  $state
     * @return array{page: int, perPage: int, sort: string|null, total: int|null, lastPage: int|null, hasMore: bool, filters: array<string, string|list<string>|null>, ignoredFilters: list<string>}
     */
    protected function tableMeta(Paginator $page, TableState $state): array
    {
        $full = $page instanceof LengthAwarePaginator;

        return [
            'page' => $page->currentPage(),
            'perPage' => $page->perPage(),
            'sort' => $state->sortParameter(),
            'total' => $full ? $page->total() : null,
            'lastPage' => $full ? $page->lastPage() : null,
            'hasMore' => $page->hasMorePages(),
            'filters' => $state->filters(),
            'ignoredFilters' => $state->ignored(),
        ];
    }
}
