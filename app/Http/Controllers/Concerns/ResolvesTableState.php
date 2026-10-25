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

trait ResolvesTableState
{
    /**
     * @var list<int>
     */
    private static array $perPageOptions = [25, 50, 100];

    /**
     * @template TSort of BackedEnum
     *
     * @param  SortWhitelist<TSort>|null  $sorts
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
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param TableState<*> $state
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
     * @template TModel of Model
     *
     * @param  Paginator<int, TModel>  $page
     * @param TableState<*> $state
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
