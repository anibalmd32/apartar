import { type MoneyView } from '@/types/money';
import { type ProductStatus } from '@/types/product';

/**
 * Los catálogos del negocio, vistos desde el backoffice
 * (`REQ-CAT-023`, `REQ-BOF-024`).
 *
 * Espejo de `App\Domain\Catalog\CatalogProductOrder` y de
 * `SyncCatalogProductsAction`. Nace con la composición; el CRUD, la
 * publicación y el estado derivado (`CatalogVisibility`) llegan con `T-032` y
 * se añaden aquí.
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
