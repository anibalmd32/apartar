import { Button } from '@/components/ui/button';
import { IconChip } from '@/components/ui/icon-chip';
import { type Facet } from '@/types/catalog-view';
import { cilSearch } from '@coreui/icons';

interface CatalogNoResultsProps {
    categories: readonly Facet[];
    businessName: string;
    onClear: () => void;
    onCategory: (slug: string) => void;
}

export function CatalogNoResults({ categories, businessName, onClear, onCategory }: CatalogNoResultsProps) {
    return (
        <div className="bg-base-100 border-line shadow-card flex flex-col items-center gap-4 rounded-2xl border px-6 py-10 text-center">
            <IconChip icon={cilSearch} tone="neutral" size={48} />
            <p className="text-h2">No hay productos que coincidan con lo que buscas.</p>

            <Button variant="secondary" onClick={onClear}>
                Ver todo el catálogo
            </Button>

            {categories.length > 0 ? (
                <div className="flex flex-col items-center gap-2">
                    <p className="text-ink-2 text-sm">O mira por categoría:</p>
                    <ul aria-label="Categorías de este catálogo" className="flex flex-wrap justify-center gap-2">
                        {categories.map((category) => (
                            <li key={category.slug}>
                                <button
                                    type="button"
                                    className="border-line-strong bg-base-100 text-ink-2 hover:text-base-content inline-flex h-8 cursor-pointer items-center rounded-full border px-3 text-[13px] font-semibold"
                                    onClick={() => onCategory(category.slug)}
                                >
                                    {category.name} ({category.count})
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}

            <p className="text-ink-3 max-w-md text-sm">Si buscabas algo en concreto, escríbele a {businessName}: puede que lo tenga en camino.</p>
        </div>
    );
}
