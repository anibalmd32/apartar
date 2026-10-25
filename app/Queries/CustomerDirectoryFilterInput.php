<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Tenancy\TenantContext;
use App\Models\Catalog;
use App\Support\BusinessDay;
use App\Support\TableState;
use Carbon\CarbonImmutable;

final class CustomerDirectoryFilterInput
{
    public const KEYS = ['q', 'overdue', 'due_soon', 'without_active', 'from', 'to', 'catalog'];

    /**
     * @param  array<string, mixed>  $input
     * @param TableState<*> $state
     */
    public static function resolve(array $input, TableState $state): CustomerDirectoryFilters
    {
        $timezone = app(TenantContext::class)->organization()->timezone;
        $flag = fn (mixed $raw): ?bool => $raw === '1' ? true : null;
        $asDate = fn (CarbonImmutable $day): string => $day->toDateString();

        $from = $state->filter('from', $input['from'] ?? null, fn (mixed $raw): ?CarbonImmutable => BusinessDay::parse($raw, $timezone), $asDate);
        $to = $state->filter('to', $input['to'] ?? null, fn (mixed $raw): ?CarbonImmutable => ($day = BusinessDay::parse($raw, $timezone)) !== null && ($from === null || ! $day->lessThan($from)) ? $day : null, $asDate);

        return new CustomerDirectoryFilters(
            search: $state->filter('q', $input['q'] ?? null, fn (mixed $raw): ?string => is_string($raw) && mb_strlen(trim($raw)) <= 100 ? trim($raw) : null),
            overdue: $state->filter('overdue', $input['overdue'] ?? null, $flag) === true,
            dueSoon: $state->filter('due_soon', $input['due_soon'] ?? null, $flag) === true,
            withoutActive: $state->filter('without_active', $input['without_active'] ?? null, $flag) === true,
            nextDueFrom: $from,
            nextDueTo: $to,
            catalogId: $state->filter('catalog', $input['catalog'] ?? null, fn (mixed $raw): ?int => is_int($id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) && Catalog::query()->whereKey($id)->exists() ? $id : null),
        );
    }
}
