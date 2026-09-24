import { Button } from '@/components/ui/button';
import { type Facet } from '@/types/catalog-view';

/**
 * Una búsqueda o una combinación de filtros que no encontró nada
 * (`REQ-CAT-002` SP-2, `REQ-CAT-006` SP-2).
 *
 * No es el catálogo vacío (`REQ-CAT-021` SP-4): el catálogo tiene productos,
 * solo que no estos. Así que la salida no es «escríbele al negocio» a secas,
 * sino volver a lo que sí hay: todo el catálogo con un botón, o directamente
 * una de sus categorías, que son las que **están** en este catálogo y no todo
 * el vocabulario del negocio.
 *
 * Y el negocio al final, porque «no lo encontré» a veces es «no lo tiene
 * publicado todavía». El enlace directo a su chat llega con el chat
 * (`T-080`); hasta entonces se le nombra, sin un botón que no llevaría a
 * ninguna parte.
 */

interface CatalogNoResultsProps {
    categories: readonly Facet[];
    businessName: string;
    onClear: () => void;
    onCategory: (slug: string) => void;
}

export function CatalogNoResults({ categories, businessName, onClear, onCategory }: CatalogNoResultsProps) {
    return (
        <div className="bg-base-100 rounded-box flex flex-col items-center gap-4 p-8 text-center">
            <p className="font-medium">No hay productos que coincidan con lo que buscas.</p>

            <Button variant="secondary" onClick={onClear}>
                Ver todo el catálogo
            </Button>

            {categories.length > 0 ? (
                <div className="flex flex-col items-center gap-2">
                    <p className="text-sm opacity-70">O mira por categoría:</p>
                    <ul aria-label="Categorías de este catálogo" className="flex flex-wrap justify-center gap-2">
                        {categories.map((category) => (
                            <li key={category.slug}>
                                <button type="button" className="btn btn-sm btn-ghost" onClick={() => onCategory(category.slug)}>
                                    {category.name} ({category.count})
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}

            <p className="text-sm opacity-70">Si buscabas algo en concreto, escríbele a {businessName}: puede que lo tenga en camino.</p>
        </div>
    );
}
