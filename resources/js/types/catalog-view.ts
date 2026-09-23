import { type OrganizationBrand } from '@/types/backoffice';
import { type MoneyView } from '@/types/money';
import { type ImageSet } from '@/types/product';

/**
 * La cara pública del catálogo (`REQ-CAT-021`).
 *
 * Espejo de `CatalogViewResource` y `ProductCardResource`. **Todo lo que hay
 * aquí lo ve cualquiera con el enlace**, y por eso no hay identificadores de
 * base de datos: el slug es el único, y ya estaba en la URL.
 */

/**
 * El catálogo tal como lo ve quien abre su enlace (contrato `CatalogView` del
 * módulo [12]).
 *
 * Faltan dos campos del contrato y es deliberado: `productsCount` sería un
 * `count(*)` por visita que SP-7 prohíbe, y `subscription` es el estado del CTA
 * de seguir, que llega con las cuentas de H6 (`T-075`).
 */
export interface CatalogView {
    readonly slug: string;
    readonly name: string;
    readonly description: string | null;
    /** La portada propia o, si no hay, la foto del primer producto (`REQ-BOF-024` SP-9). */
    readonly cover: ImageSet | null;
    readonly business: OrganizationBrand;
    /** La URL completa, la misma que el negocio copia para difundir. */
    readonly shareUrl: string;
    readonly expiresAt: string | null;
    /** `true` en plan Free: se renderiza el sello «Hecho con Apartar» (RB-9). */
    readonly showsPlatformBadge: boolean;
}

/**
 * Un producto en la parrilla (contrato `ProductCard` del módulo [11]).
 *
 * Los tres importes llegan **proyectados y formateados** por el servidor con la
 * tasa de esta respuesta: la card elige el orden de las dos monedas y nada
 * más. Aquí no hay ninguna cifra con la que se pueda calcular una cuota.
 */
export interface ProductCard {
    readonly slug: string;
    readonly name: string;
    readonly price: MoneyView;
    readonly compareAtPrice: MoneyView | null;
    readonly downPayment: MoneyView;
    readonly installmentAmount: MoneyView;
    /**
     * La última cuota cuando el reparto no es exacto, o `null` si son todas
     * iguales. Fuera del contrato de [11]: sin ella, la card enseñaría una
     * cuota que no se cobra.
     */
    readonly lastInstallmentAmount: MoneyView | null;
    readonly installmentsCount: number;
    readonly frequencyDays: number;
    readonly image: ImageSet | null;
    readonly categoryName: string;
    readonly tags: readonly string[];
    /** Si queda alguna unidad; la cifra no viaja (RB-7). */
    readonly inStock: boolean;
    readonly catalogSlug: string;
}

/**
 * Un lote de una lista paginada por cursor (RB-6).
 *
 * Sin total y sin número de página, a propósito: el total es el `count(*)` que
 * SP-7 prohíbe, y lo único que la pantalla necesita saber es si hay más.
 * `nextCursor` es opaco —lo compone y lo valida el servidor— y `null` quiere
 * decir que no queda nada que cargar.
 */
export interface Paginated<T> {
    readonly data: readonly T[];
    readonly nextCursor: string | null;
}

/** Las props de `catalog/CatalogShow`. */
export interface CatalogShowProps {
    readonly catalog: CatalogView;
    readonly products: Paginated<ProductCard>;
}

/** Las props de `catalog/CatalogExpired`, el `410` de SP-3. */
export interface CatalogExpiredProps {
    readonly catalogName: string;
    readonly businessName: string;
}
