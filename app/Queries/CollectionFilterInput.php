<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Collections\CollectionPreset;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Catalog;
use App\Models\Category;
use App\Support\BusinessDay;
use App\Support\TableState;
use Carbon\CarbonImmutable;

final class CollectionFilterInput
{
    public const KEYS = ['preset', 'from', 'to', 'status', 'q', 'category', 'catalog'];

    /**
     * @param  array<string, mixed>  $input
     * @param TableState<*> $state
     */
    public static function resolve(array $input, TableState $state): CollectionFilters
    {
        $timezone = app(TenantContext::class)->organization()->timezone;
        $asDate = fn (CarbonImmutable $day): string => $day->toDateString();

        $preset = $state->filter('preset', $input['preset'] ?? null, fn (mixed $raw): ?CollectionPreset => is_string($raw) ? CollectionPreset::tryFrom($raw) : null);
        $fromDay = BusinessDay::parse($input['from'] ?? null, $timezone);
        $toDay = BusinessDay::parse($input['to'] ?? null, $timezone);
        $inverted = $fromDay !== null && $toDay !== null && $toDay->lessThan($fromDay);
        $from = $state->filter('from', $input['from'] ?? null, fn (mixed $raw): ?CarbonImmutable => $inverted ? null : BusinessDay::parse($raw, $timezone), $asDate);
        $to = $state->filter('to', $input['to'] ?? null, fn (mixed $raw): ?CarbonImmutable => $inverted ? null : BusinessDay::parse($raw, $timezone), $asDate);
        $status = $state->filter('status', $input['status'] ?? null, fn (mixed $raw): ?LayawayStatus => is_string($raw) ? LayawayStatus::tryFrom($raw) : null);
        $search = $state->filter('q', $input['q'] ?? null, fn (mixed $raw): ?string => is_string($raw) && mb_strlen(trim($raw)) <= 100 ? trim($raw) : null);
        $category = $state->filter('category', $input['category'] ?? null, fn (mixed $raw): ?int => self::ownId(Category::class, $raw));
        $catalog = $state->filter('catalog', $input['catalog'] ?? null, fn (mixed $raw): ?int => self::ownId(Catalog::class, $raw));

        return new CollectionFilters($preset, $from, $to, $status instanceof LayawayStatus ? [$status] : [], $search, $category, $catalog);
    }

    /**
     * @param  class-string<Category>|class-string<Catalog>  $model
     */
    private static function ownId(string $model, mixed $raw): ?int
    {
        $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($id) && $model::query()->whereKey($id)->exists() ? $id : null;
    }
}
