/**
 * El registro de quién hizo qué y cuándo (`REQ-BOF-005`).
 *
 * Espejo de `app/Domain/Auditing/`. Los nombres de evento **no se repiten
 * aquí**: la lista cerrada de RB-1 son treinta y cinco casos y copiarlos sería
 * el segundo censo que se queda viejo. El servidor manda los que hay, con su
 * etiqueta ya resuelta, en `AuditEventOption`.
 */

/**
 * Quién ejecutó la acción (`REQ-BOF-005` RB-7).
 *
 * Los tres actores del requisito, y la distinción importa de verdad en el
 * segundo: `platform` es alguien de Apartar actuando bajo un grant de soporte,
 * y RB-6 existe precisamente para que el negocio **lo vea**.
 */
export type AuditActorKind = 'team' | 'platform' | 'system';

export interface AuditActor {
    readonly kind: AuditActorKind;
    /** Nombre guardado; «Miembro eliminado» si su fila ya no está (SP-5). */
    readonly name: string;
    readonly id: number | null;
}

/**
 * Lo que cambió, por campo (RB-2).
 *
 * `unknown` y no un tipo concreto porque aquí caben un precio, una fecha, un
 * booleano y una lista: el valor se pinta como texto y la pantalla no decide
 * nada con él. Un valor redactado llega como `[redactado]` (RB-5).
 */
export interface AuditChange {
    readonly before: unknown;
    readonly after: unknown;
}

export interface AuditProperties {
    readonly changed?: Record<string, AuditChange>;
    /** El detalle no cupo y esto es su resumen (SP-2). */
    readonly truncated?: boolean;
}

export interface AuditEntry {
    readonly id: number;
    readonly event: string | null;
    readonly eventLabel: string;
    readonly description: string;
    readonly actor: AuditActor;
    readonly properties: AuditProperties;
    readonly occurredAt: string | null;
}

/** Un evento del desplegable de tipos. */
export interface AuditEventOption {
    readonly value: string;
    readonly label: string;
}

/** Los seis bloques de RB-1, con sus eventos. */
export interface AuditEventGroup {
    readonly area: string;
    readonly label: string;
    readonly events: readonly AuditEventOption[];
}

/** Quién puede aparecer como actor: el equipo, incluidos los desactivados. */
export interface AuditActorOption {
    readonly id: number;
    readonly name: string;
    readonly isActive: boolean;
}

/** Filtros aplicados, ya resueltos por el servidor. */
export interface AuditFilters {
    readonly event: string | null;
    readonly actor: number | null;
    readonly from: string | null;
    readonly to: string | null;
}

/**
 * Paginación por servidor (RB-9).
 *
 * El registro de un negocio con un año de operación son decenas de miles de
 * filas: traerlas para filtrar en el navegador sería mandar por la red justo lo
 * que más cuidado pide.
 */
export interface AuditPageMeta {
    readonly page: number;
    readonly perPage: number;
    readonly total: number;
    readonly lastPage: number;
}
