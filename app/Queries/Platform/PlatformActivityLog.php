<?php

declare(strict_types=1);

namespace App\Queries\Platform;

use App\Models\Organization;
use App\Models\PlatformActivity;
use App\Models\PlatformUser;
use App\Support\Auditing\PlatformAudit;
use Carbon\CarbonImmutable;

final class PlatformActivityLog
{
    public const PER_PAGE = 50;

    /**
     * @return array{rows: list<array<string, mixed>>, hasMore: bool}
     */
    public function list(PlatformActivityFilters $filters): array
    {
        $entries = PlatformActivity::query()
            ->when($filters->operatorId !== null, fn ($query) => $query->where('platform_user_id', $filters->operatorId))
            ->when($filters->action !== null, fn ($query) => $query->where('action', $filters->action))
            ->when($filters->from !== null, fn ($query) => $query->where('created_at', '>=', $filters->from?->startOfDay()))
            ->when($filters->to !== null, fn ($query) => $query->where('created_at', '<=', $filters->to?->endOfDay()))
            ->latest('created_at')
            ->latest('id')
            ->offset(($filters->page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE + 1)
            ->get();

        $page = $entries->take(self::PER_PAGE);
        $operators = PlatformUser::query()->whereIn('id', $page->pluck('platform_user_id')->filter()->unique())->pluck('name', 'id');
        $organizations = Organization::query()->withTrashed()
            ->whereIn('id', $page->pluck('target_organization_id')->filter()->unique())
            ->get(['id', 'name', 'slug'])
            ->keyBy('id');

        return [
            'rows' => $page->map(function (PlatformActivity $entry) use ($operators, $organizations): array {
                $organization = $entry->target_organization_id === null ? null : $organizations->get($entry->target_organization_id);

                return [
                    'id' => $entry->getKey(),
                    'action' => $entry->action,
                    'label' => PlatformAudit::label($entry->action),
                    'description' => $entry->description,
                    'operator' => $entry->platform_user_id === null ? null : ($operators[$entry->platform_user_id] ?? '—'),
                    'organization' => $organization === null ? null : ['name' => $organization->name, 'slug' => $organization->slug],
                    'createdAt' => CarbonImmutable::parse($entry->created_at)->toIso8601String(),
                ];
            })->values()->all(),
            'hasMore' => $entries->count() > self::PER_PAGE,
        ];
    }
}
