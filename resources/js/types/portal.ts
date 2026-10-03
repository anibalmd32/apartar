/**
 * Tipos del portal del cliente (`REQ-IDN-004`, `REQ-IDN-008`).
 *
 * Ninguno lleva `id` numéricos de la ficha ni el de su cuenta (`REQ-IDN-004`
 * RB-4): el apartado se nombra por su código y la persona por su documento.
 */

/** La sesión del portal, compartida en `auth.portal`. Hoy solo la acotada. */
export interface PortalSessionView {
    readonly mode: 'scoped';
    readonly fullName: string;
    readonly businessName: string;
}

export interface PortalAccessProps {
    readonly status?: string | null;
}

/** Un apartado en la portada mínima de `T-049`; la completa es de `T-051`. */
export interface PortalLayawayItem {
    readonly code: string;
    readonly productName: string;
    readonly quantity: number;
    readonly status: string;
    readonly statusLabel: string;
    readonly total: string;
}

export interface PortalDashboardProps {
    readonly organization: { readonly name: string };
    readonly customer: { readonly fullName: string; readonly documentNumber: string };
    readonly layaways: readonly PortalLayawayItem[];
}

/** La confirmación del enlace por correo (`REQ-IDN-003` RB-9). */
export interface PortalLoginLinkProps {
    readonly token: string;
}

/** El enlace que ya no sirve: un solo mensaje para los tres motivos (SP-2). */
export interface PortalLoginLinkUnusableProps {
    readonly message: string;
}
