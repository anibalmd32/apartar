<?php

declare(strict_types=1);

namespace App\Support;

use BackedEnum;
use Closure;

/**
 * El estado de una tabla del backoffice, ya resuelto desde la query string
 * (`REQ-BOF-045`).
 *
 * Es el espejo de `ServerTableState` en `resources/js/features/data-table`, y
 * lo construye `ResolvesTableState` con las tres guardas del requisito puestas:
 * la página es un entero ≥ 1 (SP-3), `perPage` está en {25, 50, 100} o es 25
 * (RB-5, SP-2), y el orden es un caso de la lista blanca de esa tabla o el
 * defecto (RB-4, SP-1). Nada de lo que llega aquí es texto de la petición.
 *
 * ## Los filtros los resuelve cada tabla, y los anota aquí
 *
 * Cada listado tiene los suyos —estado y categoría en productos, evento y
 * actor en la auditoría—, así que la resolución es del controlador. Lo común es
 * **qué se hace con el resultado**: el valor resuelto es lo que se aplica y lo
 * que vuelve a la pantalla (no el crudo, que dejaría un desplegable enseñando
 * un filtro que no se aplicó), y un valor presente que no se pudo resolver se
 * ignora **y se dice** (SP-4). Eso vive aquí para que ninguna tabla lo
 * resuelva a su manera.
 *
 * @template TSort of BackedEnum
 */
final class TableState
{
    /**
     * Los filtros aplicados, tal como vuelven a la query string.
     *
     * @var array<string, string|list<string>|null>
     */
    private array $filters = [];

    /**
     * Los filtros que llegaron con un valor que no se pudo usar.
     *
     * @var list<string>
     */
    private array $ignored = [];

    /**
     * @param  TSort|null  $sort
     */
    public function __construct(
        public readonly int $page,
        public readonly int $perPage,
        public readonly ?BackedEnum $sort,
        public readonly bool $descending,
    ) {}

    /**
     * Resuelve un filtro y lo anota.
     *
     * `$resolve` recibe el valor crudo —ya sabido no vacío— y devuelve el
     * resuelto o `null` si no vale. Un filtro ausente o vacío no es un error y
     * no se anota como ignorado; uno presente que `$resolve` rechaza, sí.
     *
     * @template TValue
     *
     * @param  Closure(mixed): (TValue|null)  $resolve
     * @param  (Closure(TValue): (string|list<string>))|null  $echo  cómo vuelve a la URL; por defecto, como texto
     * @return TValue|null
     */
    public function filter(string $key, mixed $raw, Closure $resolve, ?Closure $echo = null): mixed
    {
        if ($raw === null || $raw === '' || $raw === []) {
            $this->filters[$key] = null;

            return null;
        }

        $value = $resolve($raw);

        if ($value === null) {
            $this->filters[$key] = null;
            $this->ignored[] = $key;

            return null;
        }

        $this->filters[$key] = $echo instanceof Closure ? $echo($value) : $this->asQueryValue($value);

        return $value;
    }

    /**
     * Dirección del orden, para el `ORDER BY`.
     */
    public function direction(): string
    {
        return $this->descending ? 'desc' : 'asc';
    }

    /**
     * El orden como viaja en la URL: `name` o `-name`.
     */
    public function sortParameter(): ?string
    {
        if ($this->sort === null) {
            return null;
        }

        return ($this->descending ? '-' : '').$this->sort->value;
    }

    /**
     * @return array<string, string|list<string>|null>
     */
    public function filters(): array
    {
        return $this->filters;
    }

    /**
     * @return list<string>
     */
    public function ignored(): array
    {
        return $this->ignored;
    }

    /**
     * Un valor resuelto tal como vuelve a la query string.
     */
    private function asQueryValue(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
