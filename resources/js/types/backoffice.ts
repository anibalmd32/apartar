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
 * Props compartidas que el backoffice añade a las de la aplicación.
 */
export interface BackofficeSharedData {
    readonly teamMember: AuthenticatedTeamMember | null;
    readonly status: string | null;
    [key: string]: unknown;
}
