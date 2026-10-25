<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Domain\Auditing\AuditArea;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Concerns\ResolvesTableState;
use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use App\Models\User;
use App\Support\TableState;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class AuditLogController extends Controller
{
    use ResolvesTableState;

    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): Response
    {
        $organization = $this->context->organization();

        Gate::forUser($request->user())->authorize('viewAuditLog', $organization);

        $state = $this->tableState($request);
        $entries = $this->paginateTable($this->query($request, $state), $state);

        return Inertia::render('backoffice/audit/Index', [
            'rows' => $this->rows($entries),
            'meta' => $this->tableMeta($entries, $state),
            'events' => $this->events(),
            'actors' => $this->actors(),

            'timezone' => $organization->timezone,
        ]);
    }

    /**
     * @param TableState<*> $state
     * @return Builder<AuditEntry>
     */
    private function query(Request $request, TableState $state): Builder
    {
        $query = AuditEntry::query()->audited()->latest('created_at')->latest('id');

        $event = $state->filter('event', $request->query('event'), fn (mixed $raw): ?AuditEvent => is_string($raw) ? AuditEvent::tryFrom($raw) : null);

        if ($event instanceof AuditEvent) {
            $query->ofEvent($event);
        }

        $actor = $state->filter('actor', $request->query('actor'), fn (mixed $raw): ?int => $this->teamMemberId($raw));

        if ($actor !== null) {
            $query->where('causer_type', (new User)->getMorphClass())->where('causer_id', $actor);
        }

        $this->applyDateRange($query, $request, $state);

        return $query;
    }

    private function teamMemberId(mixed $raw): ?int
    {
        if (! is_string($raw) || ! ctype_digit($raw) || strlen($raw) > 18) {
            return null;
        }

        $id = User::query()->whereKey((int) $raw)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @param  Builder<AuditEntry>  $query
     * @param TableState<*> $state
     */
    private function applyDateRange(Builder $query, Request $request, TableState $state): void
    {
        $timezone = $this->context->organization()->timezone;
        $asDate = fn (Carbon $date): string => $date->toDateString();

        $from = $state->filter('from', $request->query('from'), fn (mixed $raw): ?Carbon => $this->dateFrom($raw), $asDate);

        if ($from instanceof Carbon) {
            $query->where('created_at', '>=', $from->setTimezone($timezone)->startOfDay()->utc());
        }

        $to = $state->filter('to', $request->query('to'), fn (mixed $raw): ?Carbon => $this->dateFrom($raw), $asDate);

        if ($to instanceof Carbon) {
            $query->where('created_at', '<=', $to->setTimezone($timezone)->endOfDay()->utc());
        }
    }

    private function dateFrom(mixed $raw): ?Carbon
    {
        $value = is_string($raw) ? trim($raw) : '';

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  Paginator<int, AuditEntry>  $entries
     * @return list<array<string, mixed>>
     */
    private function rows(Paginator $entries): array
    {
        $names = $this->teamMemberNames($entries->items());

        return array_map(fn (AuditEntry $entry): array => [
            'id' => $entry->getKey(),
            'event' => $entry->event,
            'eventLabel' => $this->labelOf($entry),
            'description' => $entry->description,
            'actor' => $this->actorOf($entry, $names),
            'properties' => $entry->properties?->toArray() ?? [],
            'occurredAt' => $entry->created_at?->toIso8601String(),
        ], $entries->items());
    }

    /**
     * @param  list<AuditEntry>  $entries
     * @return array<int, string>
     */
    private function teamMemberNames(array $entries): array
    {
        $type = (new User)->getMorphClass();

        $ids = array_values(array_filter(array_map(
            fn (AuditEntry $entry): ?int => $entry->causer_type === $type ? (int) $entry->causer_id : null,
            $entries,
        )));

        if ($ids === []) {
            return [];
        }

        /** @var array<int, string> $names */
        $names = User::query()->whereKey($ids)->pluck('name', 'id')->all();

        return $names;
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function actorOf(AuditEntry $entry, array $names): array
    {
        if ($entry->causer_type === null) {
            return ['kind' => 'system', 'name' => 'Sistema', 'id' => null];
        }

        $isTeamMember = $entry->causer_type === (new User)->getMorphClass();

        $fallback = $isTeamMember ? 'Miembro eliminado' : 'Soporte de Apartar';

        return [
            'kind' => $isTeamMember ? 'team' : 'platform',
            'name' => $names[(int) $entry->causer_id] ?? $fallback,
            'id' => $entry->causer_id,
        ];
    }

    private function labelOf(AuditEntry $entry): string
    {
        $event = AuditEvent::tryFrom((string) $entry->event);

        return $event instanceof AuditEvent ? $event->label() : (string) $entry->description;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(): array
    {
        return array_map(fn (AuditArea $area): array => [
            'area' => $area->value,
            'label' => $area->label(),
            'events' => array_map(fn (AuditEvent $event): array => [
                'value' => $event->value,
                'label' => $event->label(),
            ], $area->events()),
        ], AuditArea::cases());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function actors(): array
    {
        return User::query()
            ->orderBy('name')
            ->get()
            ->map(fn (User $member): array => [
                'id' => $member->getKey(),
                'name' => $member->name,
                'isActive' => $member->disabled_at === null,
            ])
            ->values()
            ->all();
    }
}
