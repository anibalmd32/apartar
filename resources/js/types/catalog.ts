import { type MoneyView } from '@/types/money';
import { type ProductStatus } from '@/types/product';

/**
 * Los catálogos del negocio, vistos desde el backoffice
 * (`REQ-CAT-023`, `REQ-BOF-024`).
 *
 * Espejo de `App\Domain\Catalog\CatalogProductOrder`, de
 * `SyncCatalogProductsAction` y —desde `T-032`— del CRUD, la publicación y el
 * estado derivado `CatalogVisibility`.
 */

/**
 * Un producto tal como se ve al componer un catálogo.
 *
 * Lleva su **estado** porque es lo que explica dos cosas que el negocio tiene
 * que poder leer de un vistazo: que un `draft` puede estar en el catálogo y
 * todavía no se renderiza (`REQ-CAT-023` RB-5), y que un archivado que ya
 * estaba **sigue dentro** pero no se muestra (`REQ-BOF-024` SP-7). Sin el
 * estado, esas dos filas parecerían iguales que las demás y el negocio se
 * preguntaría por qué su catálogo enseña menos productos de los que ve aquí.
 *
 * No lleva `position`: el orden es el de la lista y lo renumera el servidor
 * contiguo desde 1 (RB-2). Un número aquí sería una segunda verdad sobre el
 * orden.
 */
export interface CatalogProductOption {
    readonly id: number;
    readonly name: string;
    readonly sku: string;
    readonly status: ProductStatus;
    readonly price: MoneyView;
    readonly categoryName: string | null;
}

/**
 * Estado derivado de un catálogo (`REQ-CAT-022`).
 *
 * Espejo de `App\Domain\Catalog\CatalogVisibility`. **No es una columna** y no
 * puede serlo: sale de `is_listed`, `published_at` y `expires_at`, y el
 * servidor la deriva en cada lectura. Aquí llega ya resuelta, así que la
 * pantalla no tiene que comparar fechas contra el reloj del navegador —que es
 * otro reloj, y en otra zona—.
 */
export type CatalogVisibility = 'draft' | 'listed' | 'unlisted' | 'expired';

/**
 * Un catálogo tal como se ve en el listado y en la cabecera de su formulario
 * (`REQ-BOF-024` RB-5).
 */
export interface CatalogListItem {
    readonly id: number;
    readonly name: string;
    readonly slug: string;
    readonly visibility: CatalogVisibility;
    readonly isListed: boolean;
    readonly publishedAt: string | null;
    readonly expiresAt: string | null;
    /** La URL completa que se copia y se difunde, no el slug suelto. */
    readonly shareUrl: string;
    /** Tras la primera publicación el enlace es inmutable (RB-2, SP-5). */
    readonly slugIsLocked: boolean;
    readonly productsCount: number;
    /**
     * La última modificación, ya formateada en la zona del negocio
     * (`REQ-CAT-023` SP-4). Incluye la composición: añadir un producto la mueve.
     */
    readonly updatedAt: string | null;
    /** Quién la hizo; `null` en lo que se tocó antes de que existiera la columna (`T-038`). */
    readonly updatedBy: string | null;
}

/**
 * El catálogo que carga su formulario.
 */
export interface CatalogFormValues extends CatalogListItem {
    readonly description: string | null;
    readonly coverUrl: string | null;
}

/**
 * Qué impide publicar hoy, campo → qué corregir (`REQ-CAT-022` RB-4).
 *
 * Lo compone el servidor con la **misma** función que rechazaría la
 * publicación después, así que el aviso y el `422` no pueden discrepar. Las
 * claves son las de los campos —`products`, `payment_accounts`, `expires_at`,
 * `owner_email`— y por eso es un mapa abierto y no una unión cerrada: el
 * quinto requisito, el cupo del plan, lo añade `T-067` y esta pantalla no
 * tendría que cambiar para pintarlo.
 */
export type CatalogPublishBlockers = Readonly<Record<string, string>>;
