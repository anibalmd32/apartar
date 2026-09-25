import { type ReactNode } from 'react';

/**
 * Las tablas server-driven del backoffice (`REQ-BOF-045`).
 *
 * Espejo de `ResolvesTableState` y `TableState` en PHP. La regla que gobierna
 * este archivo es que **el cliente nunca ordena ni filtra el conjunto** (RB-1):
 * todo lo que hay aquí describe lo que el servidor ya resolvió.
 *
 * No confundir con `CursorPage<T>` de `types/catalog-view.ts`: aquella es la
 * paginación por cursor de la cara pública, sin total ni número de página;
 * esta tiene página, tamaño y orden, y en las tablas pequeñas, total.
 */

/** El estado de una tabla tal como viaja en la query string (RB-3). */
export interface ServerTableState {
    readonly page: number;
    readonly perPage: number;
    /** `"name"` o `"-name"`; `null` en una tabla sin orden elegible. */
    readonly sort: string | null;
    readonly filters: Readonly<Record<string, string | string[] | null>>;
}

/**
 * Lo que la recarga parcial devuelve junto a las filas (RB-2).
 *
 * `total` y `lastPage` son **`null` en la paginación simple** (RB-6): no se
 * contaron, y un `0` ahí sería una cifra inventada. `hasMore` vale en los dos
 * modos.
 */
export interface TableMeta extends ServerTableState {
    readonly total: number | null;
    readonly lastPage: number | null;
    readonly hasMore: boolean;
    /** Filtros que llegaron con un valor que no se pudo usar: se ignoraron y la UI lo dice (SP-4). */
    readonly ignoredFilters: readonly string[];
}

/**
 * Las page props de una tabla server-driven: `rows` y `meta`, que es lo único
 * que pide la recarga parcial (RB-2).
 */
export interface Paginated<T> {
    readonly rows: readonly T[];
    readonly meta: TableMeta;
}

/** Una columna de `DataTable`. */
export interface DataTableColumn<TRow> {
    readonly id: string;
    readonly header: string;
    readonly cell: (row: TRow) => ReactNode;
    /** La clave del orden en el servidor, si la columna ordena. Tiene que estar en su lista blanca (RB-4). */
    readonly sortKey?: string;
    /** Las prioritarias son las que se ven en la tarjeta del móvil (RB-8). */
    readonly primary?: boolean;
    readonly align?: 'start' | 'end';
}
