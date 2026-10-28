import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { SortableHandle, SortableList, SortableRow } from '@/components/ui/sortable';
import { CatalogProductPicker, ProductThumb } from '@/features/catalogs/components/CatalogProductPicker';
import { useSortableProducts } from '@/features/catalogs/hooks/useSortableProducts';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { type CatalogProductOption } from '@/types/catalog';
import { cilX } from '@coreui/icons';

interface CatalogProductSorterProps {
    catalogId: number;
    products: readonly CatalogProductOption[];
    available: readonly CatalogProductOption[];
    suggested?: readonly number[];
    isPublished: boolean;
}

export function CatalogProductSorter({ catalogId, products, available, suggested = [], isPublished }: CatalogProductSorterProps) {
    const { chosen, ids, isDirty, saving, add, remove, reorder, save } = useSortableProducts({
        catalogId,
        initial: products,
    });

    return (
        <section className="flex flex-col gap-4">
            <CatalogProductPicker
                available={available}
                chosenIds={ids}
                suggestedIds={[...products.map((product) => product.id).filter((id) => !ids.includes(id)), ...suggested]}
                onAdd={add}
            />

            {chosen.length === 0 ? (
                <p className="text-ink-3 text-sm">
                    Este catálogo todavía no tiene productos. Búscalos arriba y añádelos; podrás ordenarlos arrastrándolos.
                </p>
            ) : (
                <ol className="flex flex-col gap-2">
                    <SortableList ids={ids} onReorder={reorder} labelOf={(id) => chosen.find((row) => row.id === id)?.name ?? 'el producto'}>
                        {chosen.map((product, index) => (
                            <SortableRow key={product.id} id={product.id} label={product.name}>
                                {({ containerProps, handleProps }) => (
                                    <li
                                        ref={containerProps.ref}
                                        style={containerProps.style}
                                        className={`bg-surface-2 flex items-center gap-2.5 rounded-xl py-2 pr-2 pl-1 ${containerProps.className ?? ''}`}
                                    >
                                        <SortableHandle handleProps={handleProps} />

                                        <span className="text-ink-3 w-5 shrink-0 text-center text-xs font-semibold">{index + 1}</span>

                                        <ProductThumb product={product} />

                                        <span className="flex min-w-0 flex-1 flex-col">
                                            <span className="truncate text-sm font-semibold">{product.name}</span>
                                            <span className="text-ink-3 text-xs">
                                                <MoneyLabel money={product.price} compact /> · {product.sku}
                                            </span>
                                        </span>

                                        {product.status === 'published' ? null : (
                                            <Badge tone="warning" outline>
                                                No se muestra
                                            </Badge>
                                        )}

                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className="btn-square text-ink-3 hover:text-error"
                                            onClick={() => remove(product.id)}
                                            aria-label={`Quitar ${product.name}`}
                                        >
                                            <Icon icon={cilX} />
                                        </Button>
                                    </li>
                                )}
                            </SortableRow>
                        ))}
                    </SortableList>
                </ol>
            )}

            {isPublished && chosen.length === 0 ? (
                <Alert tone="warning">
                    Este catálogo está publicado: su enlace ya circula, así que no podrás guardarlo sin productos. Añade al menos uno, o despublícalo
                    primero.
                </Alert>
            ) : null}

            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-ink-3 text-sm">
                    {isDirty ? 'Tienes cambios sin guardar.' : 'Todo guardado.'} El orden de esta lista es el que verán tus clientes.
                </p>

                <Button variant="secondary" onClick={save} disabled={!isDirty} loading={saving}>
                    Guardar orden
                </Button>
            </div>
        </section>
    );
}
