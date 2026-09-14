/**
 * Quién está dentro del backoffice y de qué negocio (`REQ-BOF-001`).
 *
 * Viaja en las props compartidas de todas las pantallas del backoffice, y no
 * por comodidad: el rol decide qué se pinta —ocultar lo que va a dar `403` es
 * cortesía, y la policy del servidor es la seguridad (`REQ-ORG-003` RB-4)— y
 * el estado del negocio decide si se ve el aviso de suspensión, que tiene que
 * verse en **todas** las pantallas y no solo en el panel.
 */

export type OrganizationRole = 'owner' | 'member';

export type OrganizationStatus = 'active' | 'suspended' | 'closed';

/**
 * Identidad del negocio tal como la necesita el marco del backoffice.
 *
 * `OrganizationBrand` —logo, color y el sello «Hecho con Apartar»— es de
 * `REQ-ORG-006` y llega con su tarea, cuando exista algo que los pinte.
 */
export interface OrganizationSummary {
    readonly name: string;
    readonly slug: string;
    readonly status: OrganizationStatus;
}

export interface AuthenticatedTeamMember {
    readonly name: string;
    readonly email: string;
    readonly role: OrganizationRole;
    readonly mustChangePassword: boolean;
    readonly organization: OrganizationSummary;
}

/**
 * Una persona del equipo tal como la pinta la pantalla de Equipo
 * (`REQ-ORG-004`, `REQ-ORG-005`).
 *
 * Amplía el `TeamMember` del contrato del módulo 04 con tres banderas que
 * **decide el servidor**, y ahí está la idea: `REQ-SUB-004` RB-1 dice que el
 * frontend no decide, refleja. Calcular en la pantalla si alguien es el último
 * `owner` exigiría reimplementar la regla de `LastOwnerGuard` en TypeScript, y
 * dos implementaciones de una invariante son una que se queda vieja.
 *
 * Ninguna de las tres es control de acceso: sirven para **deshabilitar el
 * botón con su motivo** en lugar de dejar que la persona lo pulse y reciba un
 * `422`. Quien autoriza de verdad es la policy, y quien protege la invariante
 * es el bloqueo transaccional.
 */
export interface TeamMember {
    readonly id: number;
    readonly name: string;
    readonly email: string;
    readonly role: OrganizationRole;
    readonly isActive: boolean;
    readonly invitedAt: string | null;
    /** Invitado que todavía no ha elegido contraseña (`REQ-ORG-004` RB-3). */
    readonly isPending: boolean;
    /** Es quien está mirando la pantalla: nadie se desactiva a sí mismo (RB-4). */
    readonly isSelf: boolean;
    /** Último `owner` activo: no se puede desactivar (`REQ-ORG-005` RB-3). */
    readonly isLastOwner: boolean;
}

/**
 * Props compartidas que el backoffice añade a las de la aplicación.
 */
export interface BackofficeSharedData {
    readonly teamMember: AuthenticatedTeamMember | null;
    readonly status: string | null;
    [key: string]: unknown;
}
