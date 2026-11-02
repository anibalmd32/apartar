import { Icon } from '@/components/ui/icon';
import { ScrollRow } from '@/components/ui/scroll-row';
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
                'touch:h-11 inline-flex h-8 shrink-0 cursor-pointer items-center gap-1.5 rounded-full border px-3 text-[13px] font-semibold whitespace-nowrap transition-colors',
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
    const options = sortOptions(searching);
    const current = options.find((option) => option.value === (value ?? ''))?.label ?? options[0]?.label;

    return (
        <div className="relative">
            <label htmlFor={id} className="sr-only">
                Ordenar por
            </label>
            <span aria-hidden="true" className="select text-ink-2 pointer-events-none h-11 w-auto rounded-full pr-8 pl-9 font-medium lg:hidden">
                {current}
            </span>
            <Icon icon={cilSortAscending} className="text-ink-2 pointer-events-none absolute top-1/2 left-3 z-10 -translate-y-1/2" />
            <select
                id={id}
                className="select text-ink-2 h-10 w-auto pr-8 pl-9 text-[13px] font-medium max-lg:absolute max-lg:inset-0 max-lg:h-full max-lg:w-full max-lg:appearance-none max-lg:opacity-0"
                value={value ?? ''}
                onChange={(event) => onSort(isSort(event.target.value) ? event.target.value : null)}
            >
                {options.map((option) => (
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
    const divider = <span aria-hidden="true" className="bg-line-strong h-6 w-px shrink-0 lg:hidden" />;

    return (
        <div className="grid grid-cols-1 items-center gap-2.5 lg:grid-cols-[360px_minmax(0,1fr)_auto]">
            <div className="min-w-0">{search}</div>

            <ScrollRow activeKey={applied.category} className="-mx-4 flex items-center gap-2 px-4 lg:contents">
                <div className="shrink-0 lg:col-start-3 lg:row-start-1">
                    <SortSelect searching={searching} value={applied.sort} onSort={onSort} />
                </div>

                {facets.categories.length > 1 ? (
                    <>
                        {divider}
                        <div
                            role="group"
                            aria-label="Categoría"
                            className="flex shrink-0 gap-2 lg:col-start-2 lg:row-start-1 lg:min-w-0 lg:shrink lg:overflow-x-auto"
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
                    </>
                ) : null}

                {facets.tags.length > 0 ? (
                    <>
                        {divider}
                        <div role="group" aria-label="Etiquetas" className="flex shrink-0 gap-2 lg:col-span-full lg:flex-wrap">
                            {facets.tags.map((tag) => (
                                <FacetChip
                                    key={tag.slug}
                                    facet={tag}
                                    pressed={applied.tags.includes(tag.slug)}
                                    onClick={() => onToggleTag(tag.slug)}
                                />
                            ))}
                        </div>
                    </>
                ) : null}
            </ScrollRow>
        </div>
    );
}
