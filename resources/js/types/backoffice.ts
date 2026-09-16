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

/**
 * Las áreas de `REQ-ORG-003` RB-1 —seis de gobierno y seis de operación
 * diaria— más `audit`, que `T-016` añade del lado del gobierno
 * (`REQ-BOF-005` RB-9).
 *
 * Es un espejo de `App\Domain\Tenancy\OrganizationCapability` y **no repite el
 * reparto**: qué capacidades ejerce cada rol lo decide el servidor y llega en
 * `AuthenticatedTeamMember.capabilities`. Esta unión solo nombra las áreas,
 * que es lo que TypeScript puede comprobar; la frontera vive en un único sitio
 * (`requiresOwner()`), que es lo que `T-014` vino a arreglar.
 */
export type OrganizationCapability =
    | 'subscription'
    | 'billing'
    | 'team'
    | 'settings'
    | 'payment_accounts'
    | 'branding'
    | 'audit'
    | 'products'
    | 'catalogs'
    | 'customers'
    | 'collections'
    | 'receipts'
    | 'chat';

export type OrganizationStatus = 'active' | 'suspended' | 'closed';

/**
 * Identidad del negocio tal como la necesita el marco del backoffice.
 */
export interface OrganizationSummary {
    readonly name: string;
    readonly slug: string;
    readonly status: OrganizationStatus;
}

/**
 * Cómo se presenta un negocio (`REQ-ORG-006`, contrato del módulo 04).
 *
 * `brandingEnabled` es lo único que no sale de la fila: lo responde el plan
 * (`LimitEnforcer::hasFeature(Branding)`). El logo y el color **viajan
 * igualmente cuando es `false`**, porque en Free se guardan y no se aplican
 * (`REQ-ORG-006` RB-5b, SP-3) y la pantalla tiene que enseñar lo que hay
 * guardado —quien está en Free tiene que ver lo que se pierde
 * (`REQ-SUB-004` RB-7)—.
 */
export interface OrganizationBrand {
    readonly name: string;
    readonly slug: string;
    readonly logoUrl: string | null;
    readonly brandColor: string | null;
    /** `false` en plan Free: el catálogo muestra el sello «Hecho con Apartar». */
    readonly brandingEnabled: boolean;
}

/**
 * A qué alcanza cambiar un ajuste (`REQ-ORG-006` RB-4).
 *
 * Espejo de `App\Domain\Settings\SettingScope`. La frase que lo explica la
 * manda el servidor en `SettingDefinition.scopeNote`, para que la pantalla no
 * pueda decir una cosa distinta de la que hace el cálculo.
 */
export type SettingScope = 'immediate' | 'daily' | 'future_only';

/**
 * Lo que puede valer un ajuste, en la forma en que viaja del servidor al
 * formulario y de vuelta.
 *
 * No es `unknown`, y la restricción es útil: obliga a decidir que
 * `reminder_offsets_days` viaja como lista de enteros y no como el texto que
 * escribió la persona, que es exactamente donde esa diferencia se pierde.
 */
export type SettingValue = string | number | boolean | null | number[];

/** Los diez ajustes de una organización, por clave (`REQ-ORG-006`). */
export type OrganizationSettings = Record<string, SettingValue>;

/**
 * Qué es cada clave de ajustes, resuelto por el servidor.
 *
 * Nombre, alcance y su explicación salen de `SettingKey`: repetirlos aquí
 * sería la segunda fuente de verdad que `T-014` vino a retirar del menú.
 */
export interface SettingDefinition {
    readonly key: string;
    readonly label: string;
    readonly scope: SettingScope;
    readonly scopeNote: string;
}

/**
 * La identidad editable del negocio, tal como la espera su formulario.
 */
export interface OrganizationIdentity {
    readonly name: string;
    readonly slug: string;
    readonly document: string | null;
    readonly phone: string | null;
    readonly timezone: string;
    readonly brandColor: string | null;
    readonly storefrontEnabled: boolean;
}

export interface AuthenticatedTeamMember {
    readonly name: string;
    readonly email: string;
    readonly role: OrganizationRole;
    /**
     * Áreas que su rol ejerce, resueltas por el servidor (`REQ-ORG-003` RB-4).
     *
     * El menú se filtra con esto y no con el rol, para que la frontera de RB-1
     * esté declarada una sola vez. Ocultar sigue sin ser control de acceso: la
     * policy del servidor es la que manda.
     */
    readonly capabilities: readonly OrganizationCapability[];
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
