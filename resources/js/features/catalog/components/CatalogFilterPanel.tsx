import { Icon } from '@/components/ui/icon';
import { type SelectOption } from '@/components/ui/select-field';
import { cn } from '@/lib/utils';
import { type CatalogFacets, type CatalogFilters, type CatalogSort, type Facet } from '@/types/catalog-view';
import { cilSortAscending } from '@coreui/icons';
import { type ReactNode, useId } from 'react';

const SORT_LABELS: Record<CatalogSort, string> = {
    curated: 'Recomendados',
    recent: 'Lo último añadido',
    price_asc: 'Precio: de menor a mayor',
    price_desc: 'Precio: de mayor a menor',
    name: 'Nombre (A-Z)',
};

const EXPLICIT: readonly CatalogSort[] = ['recent', 'price_asc', 'price_desc', 'name'];

export function sortOptions(searching: boolean): readonly SelectOption[] {
    const others = EXPLICIT.map((sort) => ({ value: sort, label: SORT_LABELS[sort] }));

    return searching
        ? [{ value: '', label: 'Más relevantes' }, { value: 'curated', label: SORT_LABELS.curated }, ...others]
        : [{ value: '', label: SORT_LABELS.curated }, ...others];
}

function isSort(value: string): value is CatalogSort {
    return value in SORT_LABELS;
}

interface ChipProps {
    pressed: boolean;
    onClick: () => void;
    label?: string;
    children: ReactNode;
}

function Chip({ pressed, onClick, label, children }: ChipProps) {
    return (
        <button
            type="button"
            aria-pressed={pressed}
            aria-label={label}
            className={cn(
                'inline-flex h-8 shrink-0 cursor-pointer items-center gap-1.5 rounded-full border px-3 text-[13px] font-semibold whitespace-nowrap transition-colors',
                pressed
                    ? 'bg-neutral text-neutral-content border-neutral'
                    : 'bg-base-100 border-line-strong text-ink-2 hover:text-base-content hover:border-ink-3',
            )}
            onClick={onClick}
        >
            {children}
        </button>
    );
}

function FacetChip({ facet, pressed, onClick }: { facet: Facet; pressed: boolean; onClick: () => void }) {
    return (
        <Chip pressed={pressed} onClick={onClick} label={`${facet.name} (${facet.count})`}>
            {facet.name}
            <span className={cn('text-xs', pressed ? 'opacity-80' : 'text-ink-3')}>{facet.count}</span>
        </Chip>
    );
}

function SortSelect({ searching, value, onSort }: { searching: boolean; value: CatalogSort | null; onSort: (sort: CatalogSort | null) => void }) {
    const id = useId();

    return (
        <div className="relative">
            <label htmlFor={id} className="sr-only">
                Ordenar por
            </label>
            <Icon
                icon={cilSortAscending}
                className="text-ink-2 pointer-events-none absolute top-1/2 left-1/2 z-10 -translate-x-1/2 -translate-y-1/2 lg:left-3 lg:translate-x-0"
            />
            <select
                id={id}
                className="select lg:text-ink-2 h-10 w-10 px-0 text-transparent lg:w-auto lg:pr-8 lg:pl-9 lg:text-[13px] lg:font-medium"
                value={value ?? ''}
                onChange={(event) => onSort(isSort(event.target.value) ? event.target.value : null)}
            >
                {sortOptions(searching).map((option) => (
                    <option key={option.value} value={option.value} className="text-base-content">
                        {option.label}
                    </option>
                ))}
            </select>
        </div>
    );
}

interface CatalogFilterPanelProps {
    facets: CatalogFacets;
    applied: CatalogFilters;
    searching: boolean;
    search: ReactNode;
    onCategory: (slug: string | null) => void;
    onToggleTag: (slug: string) => void;
    onSort: (sort: CatalogSort | null) => void;
}

export function CatalogFilterPanel({ facets, applied, searching, search, onCategory, onToggleTag, onSort }: CatalogFilterPanelProps) {
    return (
        <div className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-2.5 lg:grid-cols-[360px_minmax(0,1fr)_auto]">
            <div className="min-w-0">{search}</div>

            <div className="lg:col-start-3 lg:row-start-1">
                <SortSelect searching={searching} value={applied.sort} onSort={onSort} />
            </div>

            {facets.categories.length > 1 ? (
                <div
                    role="group"
                    aria-label="Categoría"
                    className="col-span-2 -mx-4 flex gap-2 overflow-x-auto px-4 lg:col-span-1 lg:col-start-2 lg:row-start-1 lg:mx-0 lg:px-0"
                >
                    <Chip pressed={applied.category === null} onClick={() => onCategory(null)}>
                        Todo
                    </Chip>
                    {facets.categories.map((category) => (
                        <FacetChip
                            key={category.slug}
                            facet={category}
                            pressed={applied.category === category.slug}
                            onClick={() => onCategory(applied.category === category.slug ? null : category.slug)}
                        />
                    ))}
                </div>
            ) : null}

            {facets.tags.length > 0 ? (
                <div role="group" aria-label="Etiquetas" className="col-span-full -mx-4 flex gap-2 overflow-x-auto px-4 lg:mx-0 lg:flex-wrap lg:px-0">
                    {facets.tags.map((tag) => (
                        <FacetChip key={tag.slug} facet={tag} pressed={applied.tags.includes(tag.slug)} onClick={() => onToggleTag(tag.slug)} />
                    ))}
                </div>
            ) : null}
        </div>
    );
}
