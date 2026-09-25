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

/**
 * Quién hizo qué y cuándo (`REQ-BOF-005` RB-9).
 *
 * **Solo lee.** No hay `store`, ni `update`, ni `destroy`, y esa ausencia es lo
 * que hace cierta la regla `append-only` de RB-3: una auditoría que la propia
 * aplicación puede editar no es una auditoría, es una tabla más. Lo que
 * sostiene la invariante es que no exista el código, no una comprobación que
 * alguien pueda saltarse.
 *
 * **Paginada por servidor** (RB-9), y no por comodidad: el registro de un
 * negocio con un año de operación son decenas de miles de filas, y traerlas
 * para filtrar en el navegador sería mandar por la red justo lo que más
 * cuidado pide.
 *
 * Los tres filtros son los de RB-9 —actor, tipo y rango— y los tres se
 * resuelven contra listas cerradas: el actor tiene que ser alguien del equipo,
 * el tipo tiene que ser un caso de `AuditEvent` y las fechas se parsean o se
 * ignoran. Un valor inventado en el `query string` no produce un error, produce
 * la lista sin ese filtro: quien manipula una URL no merece una traza, y quien
 * llega con un enlace viejo tampoco merece una pantalla rota.
 */
final class AuditLogController extends Controller
{
    use ResolvesTableState;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * El registro, paginado por `ResolvesTableState` (`REQ-BOF-045`).
     *
     * Fue la primera tabla paginada del proyecto y resolvía a mano su página y
     * sus filtros; desde `T-038` usa el mismo *concern* que las demás, y la
     * forma de sus props es la del contrato —`rows` y `meta`, con los filtros
     * resueltos y los ignorados dentro de `meta`—. **No tiene orden elegible**:
     * un registro se lee del más reciente al más antiguo, y ordenarlo por otra
     * cosa convertiría «qué pasó el martes» en una búsqueda.
     */
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

            // Las fechas se leen en la zona del negocio y no en la del
            // navegador de quien mira (`REQ-ORG-006` RB-7): «a las 9 de la
            // mañana» tiene que significar lo mismo para el dueño que para su
            // empleado, estén donde estén.
            'timezone' => $organization->timezone,
        ]);
    }

    /**
     * La consulta, con sus filtros aplicados y anotados en el estado.
     *
     * Sin un solo `where` de organización: el global scope de `AuditEntry` ya
     * filtra (`REQ-ORG-002` RB-1), que es lo que hace cierto SP-6 sin depender
     * de que nadie se acuerde. Un filtro que no se entiende —un evento que no
     * es de la lista, un actor que no es del equipo, una fecha ilegible— se
     * ignora **y se avisa** (`REQ-BOF-045` SP-4) en lugar de reventar: es un
     * filtro, no un dato que se guarde.
     *
     * @param  TableState<*>  $state
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
            // Por tipo **y** por identificador: sin el tipo, el `3` de un
            // `User` traería también lo que hiciera el `PlatformUser` número 3
            // el día que H8 exista.
            $query->where('causer_type', (new User)->getMorphClass())->where('causer_id', $actor);
        }

        $this->applyDateRange($query, $request, $state);

        return $query;
    }

    /**
     * El identificador si es de alguien del equipo, incluidos los
     * desactivados; `null` si no.
     *
     * Con el global scope de `User` puesto: el miembro de otro negocio no
     * existe aquí, y se ignora igual que uno inventado.
     */
    private function teamMemberId(mixed $raw): ?int
    {
        if (! is_string($raw) || ! ctype_digit($raw) || strlen($raw) > 18) {
            return null;
        }

        $id = User::query()->whereKey((int) $raw)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Rango de fechas, cada extremo por su cuenta.
     *
     * `from` va al principio de su día y `to` al final del suyo, en la zona de
     * la organización (`REQ-ORG-006` RB-7): quien filtra «hasta el 15» espera
     * que entre lo del 15, y una comparación cruda contra la medianoche del 15
     * se deja fuera el día entero.
     *
     * @param  Builder<AuditEntry>  $query
     * @param  TableState<*>  $state
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

    /**
     * Una fecha del `query string`, o `null` si no se entiende.
     */
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
     * Las entradas tal como las pinta la pantalla.
     *
     * **El actor se nombra desde la relación cuando existe y desde la propia
     * entrada cuando no.** Un miembro dado de baja conserva su fila, así que
     * casi siempre existe; lo que no puede pasar es que la pantalla reviente
     * con un `causer_type` que todavía no tiene modelo —el `PlatformUser` de
     * H8—, y por eso se resuelve con cuidado en vez de con un `->causer->name`.
     *
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
     * Nombres de los miembros que aparecen en esta página, en **una** consulta.
     *
     * Resolverlos fila a fila serían veinticinco consultas por pantalla, y la
     * lista es larga por definición: es un registro que crece con cada
     * operación del negocio.
     *
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
     * Quién lo hizo, en la forma que la pantalla necesita.
     *
     * RB-7 tiene tres respuestas y las tres se distinguen aquí: una persona del
     * equipo, **la plataforma** —que se marca como tal, porque RB-6 existe para
     * que el negocio vea cuándo alguien de Apartar tocó sus datos— y el
     * sistema, que es `causer` nulo y se lee en la descripción.
     *
     * @param  array<int, string>  $names  nombres del equipo ya resueltos
     * @return array<string, mixed>
     */
    private function actorOf(AuditEntry $entry, array $names): array
    {
        if ($entry->causer_type === null) {
            return ['kind' => 'system', 'name' => 'Sistema', 'id' => null];
        }

        $isTeamMember = $entry->causer_type === (new User)->getMorphClass();

        // «Miembro eliminado» y no un hueco: `REQ-ORG-005` RB-1 impide borrar a
        // nadie del equipo, así que llegar aquí significa que la fila se fue por
        // una vía que no es el producto —una invitación revocada, por ejemplo—.
        // La entrada sigue siendo legible porque la descripción guardó el
        // nombre cuando el hecho ocurrió (SP-5).
        $fallback = $isTeamMember ? 'Miembro eliminado' : 'Soporte de Apartar';

        return [
            'kind' => $isTeamMember ? 'team' : 'platform',
            'name' => $names[(int) $entry->causer_id] ?? $fallback,
            'id' => $entry->causer_id,
        ];
    }

    /**
     * Nombre del evento, o su valor crudo si la entrada es de un evento que ya
     * no está en el enum.
     *
     * No debería pasar —la lista es cerrada y no se quitan casos—, pero una
     * entrada guardada hace un año no se puede reescribir (RB-3): si alguna vez
     * se retirara un caso, la fila tiene que seguir leyéndose.
     */
    private function labelOf(AuditEntry $entry): string
    {
        $event = AuditEvent::tryFrom((string) $entry->event);

        return $event instanceof AuditEvent ? $event->label() : (string) $entry->description;
    }

    /**
     * Los eventos de la lista cerrada, agrupados por bloque de RB-1.
     *
     * Treinta y cinco eventos en un desplegable plano no se leen; los seis
     * bloques del requisito son la agrupación que ya existe, así que no hay
     * nada que inventar.
     *
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
     * Quién puede aparecer como actor: el equipo, incluidos los desactivados.
     *
     * Incluidos **sobre todo** los desactivados: la pregunta que trae a alguien
     * a esta pantalla suele ser «¿qué hizo la persona que ya no está?»
     * (`REQ-ORG-005` RB-7).
     *
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
