import { type SelectOption, SelectField } from '@/components/ui/select-field';
import { cn } from '@/lib/utils';
import { type CatalogFacets, type CatalogFilters, type CatalogSort, type Facet } from '@/types/catalog-view';

/**
 * El panel de filtros y el orden del catálogo (`REQ-CAT-006` RB-3 a RB-5,
 * SP-6).
 *
 * Ofrece **lo que hay en este catálogo** y nada más: las facetas llegan ya
 * contadas y sin las vacías (`CatalogFacetsQuery`), y el número de cada una es
 * el que sale al pulsarla. La categoría es **una o ninguna** —pulsar otra
 * cambia de sitio— y las etiquetas se **suman**: basta con que el producto
 * tenga una de las marcadas (RB-4). Por eso se ven distinto: la categoría es
 * un grupo de opciones, las etiquetas son interruptores.
 *
 * SP-6: si todo el catálogo es de una sola categoría, el grupo no se pinta,
 * porque elegir entre una opción no filtra nada. Lo mismo sin etiquetas.
 *
 * ## El orden por defecto depende de si se busca
 *
 * Sin término, el defecto es el orden del negocio. Con término, es la
 * relevancia (`REQ-CAT-002` RB-4), y entonces «Recomendados» pasa a ser una
 * elección explícita más. La opción vacía es siempre «el defecto», y no se
 * escribe en la URL.
 */

const SORT_LABELS: Record<CatalogSort, string> = {
    curated: 'Recomendados',
    recent: 'Lo último añadido',
    price_asc: 'Precio: de menor a mayor',
    price_desc: 'Precio: de mayor a menor',
    name: 'Nombre (A-Z)',
};

const EXPLICIT: readonly CatalogSort[] = ['recent', 'price_asc', 'price_desc', 'name'];

/** Las opciones del orden: con término, el defecto es la relevancia. */
export function sortOptions(searching: boolean): readonly SelectOption[] {
    const others = EXPLICIT.map((sort) => ({ value: sort, label: SORT_LABELS[sort] }));

    return searching
        ? [{ value: '', label: 'Más relevantes' }, { value: 'curated', label: SORT_LABELS.curated }, ...others]
        : [{ value: '', label: SORT_LABELS.curated }, ...others];
}

function isSort(value: string): value is CatalogSort {
    return value in SORT_LABELS;
}

interface FacetButtonProps {
    facet: Facet;
    pressed: boolean;
    onClick: () => void;
}

function FacetButton({ facet, pressed, onClick }: FacetButtonProps) {
    return (
        <button type="button" aria-pressed={pressed} className={cn('btn btn-sm', pressed ? 'btn-primary' : 'btn-outline')} onClick={onClick}>
            {facet.name} <span className="opacity-70">({facet.count})</span>
        </button>
    );
}

interface CatalogFilterPanelProps {
    facets: CatalogFacets;
    applied: CatalogFilters;
    searching: boolean;
    onCategory: (slug: string | null) => void;
    onToggleTag: (slug: string) => void;
    onSort: (sort: CatalogSort | null) => void;
}

export function CatalogFilterPanel({ facets, applied, searching, onCategory, onToggleTag, onSort }: CatalogFilterPanelProps) {
    return (
        <div className="flex flex-col gap-4">
            {facets.categories.length > 1 ? (
                <div role="group" aria-label="Categoría" className="flex flex-wrap gap-2">
                    <button
                        type="button"
                        aria-pressed={applied.category === null}
                        className={cn('btn btn-sm', applied.category === null ? 'btn-primary' : 'btn-outline')}
                        onClick={() => onCategory(null)}
                    >
                        Todas
                    </button>
                    {facets.categories.map((category) => (
                        <FacetButton
                            key={category.slug}
                            facet={category}
                            pressed={applied.category === category.slug}
                            onClick={() => onCategory(applied.category === category.slug ? null : category.slug)}
                        />
                    ))}
                </div>
            ) : null}

            {facets.tags.length > 0 ? (
                <div role="group" aria-label="Etiquetas" className="flex flex-wrap gap-2">
                    {facets.tags.map((tag) => (
                        <FacetButton key={tag.slug} facet={tag} pressed={applied.tags.includes(tag.slug)} onClick={() => onToggleTag(tag.slug)} />
                    ))}
                </div>
            ) : null}

            <div className="sm:max-w-xs">
                <SelectField
                    label="Ordenar por"
                    options={sortOptions(searching)}
                    value={applied.sort ?? ''}
                    onChange={(event) => onSort(isSort(event.target.value) ? event.target.value : null)}
                />
            </div>
        </div>
    );
}
