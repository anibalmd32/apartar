import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { SortableHandle, SortableList, SortableRow } from '@/components/ui/sortable';
import { TextField } from '@/components/ui/text-field';
import { useSortableProducts } from '@/features/catalogs/hooks/useSortableProducts';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { type CatalogProductOption } from '@/types/catalog';
import { useState } from 'react';

/**
 * Qué productos entran en el catálogo y en qué orden se ven
 * (`REQ-CAT-023`, `REQ-BOF-024` RB-2).
 *
 * Dos mitades: el buscador, que es de donde salen los productos, y la lista
 * curada, que es la que se arrastra y se guarda.
 *
 * ## El buscador filtra lo que ya llegó
 *
 * El inventario del negocio viaja entero en las props, igual que en el listado
 * de productos, y la búsqueda por nombre y SKU se resuelve en el cliente. No
 * hay endpoint de búsqueda aquí a propósito: el que `REQ-CAT-002` describe es
 * el del **catálogo público**, con su `FULLTEXT` y su orden por relevancia
 * sobre descripciones largas, y usarlo para que un negocio encuentre un código
 * que ya conoce sería montar un motor para un `LIKE`.
 *
 * ## El orden se guarda al pulsar, no al soltar (RB-8)
 *
 * Componer un escaparate son veinte gestos seguidos. Un autoguardado por
 * arrastre mandaría veinte peticiones para una sola decisión, y dejaría el
 * catálogo publicado pasando por diecinueve órdenes que nadie eligió. Mientras
 * haya cambios sin guardar se dice con esas palabras, porque lo peor de un
 * guardado explícito es no saber que hace falta.
 *
 * ## Y las filas dicen lo que el catálogo público no enseñará
 *
 * Un `draft` puede estar dentro —es el caso de uso de RB-5, preparar la
 * campaña antes de lanzarla— y un archivado que ya estaba **sigue dentro**
 * (`REQ-BOF-024` SP-7). Las dos se marcan «no se muestra»: sin eso, el negocio
 * cuenta doce productos aquí, ve nueve en su enlace y no tiene forma de saber
 * cuáles faltan ni por qué.
 */

interface CatalogProductSorterProps {
    catalogId: number;
    /** Los que ya están en el catálogo, en su orden curado. */
    products: readonly CatalogProductOption[];
    /** Todo el inventario del negocio, de donde se eligen. */
    available: readonly CatalogProductOption[];
    /** El catálogo ya está publicado: vaciarlo será `422` (SP-7). */
    isPublished: boolean;
}

/** Cuántos resultados se ofrecen de una vez: una lista larga no se lee. */
const MAX_RESULTS = 8;

export function CatalogProductSorter({ catalogId, products, available, isPublished }: CatalogProductSorterProps) {
    const { chosen, ids, isDirty, saving, add, remove, reorder, save } = useSortableProducts({
        catalogId,
        initial: products,
    });

    const [search, setSearch] = useState('');

    const term = search.trim().toLowerCase();

    const results =
        term === ''
            ? []
            : available
                  .filter((product) => !ids.includes(product.id))
                  .filter((product) => product.name.toLowerCase().includes(term) || product.sku.toLowerCase().includes(term))
                  .slice(0, MAX_RESULTS);

    return (
        <section className="flex flex-col gap-4">
            <div className="flex flex-col gap-2">
                <TextField
                    label="Añadir productos"
                    name="catalog-product-search"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    hint="Busca por nombre o por SKU."
                    autoComplete="off"
                />

                {term !== '' && results.length === 0 ? (
                    <p className="text-sm opacity-70">No queda ningún producto sin añadir que coincida con «{search.trim()}».</p>
                ) : null}

                {results.length > 0 ? (
                    <ul className="menu bg-base-200 rounded-box w-full">
                        {results.map((product) => (
                            <li key={product.id}>
                                <button type="button" onClick={() => add(product)} className="justify-between">
                                    <span className="flex flex-col items-start">
                                        <span className="font-medium">{product.name}</span>
                                        <span className="font-mono text-xs opacity-70">{product.sku}</span>
                                    </span>

                                    <span className="text-xs">Añadir</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                ) : null}
            </div>

            {chosen.length === 0 ? (
                <p className="text-sm opacity-70">
                    Este catálogo todavía no tiene productos. Búscalos arriba y añádelos; podrás ordenarlos arrastrándolos.
                </p>
            ) : (
                <ol className="flex flex-col gap-2">
                    <SortableList ids={ids} onReorder={reorder} labelOf={(id) => chosen.find((row) => row.id === id)?.name ?? 'el producto'}>
                        {chosen.map((product) => (
                            <SortableRow key={product.id} id={product.id} label={product.name}>
                                {({ containerProps, handleProps }) => (
                                    <li
                                        ref={containerProps.ref}
                                        style={containerProps.style}
                                        className={`bg-base-100 flex items-center gap-2 rounded-lg border p-2 ${containerProps.className ?? ''}`}
                                    >
                                        <SortableHandle handleProps={handleProps} />

                                        <span className="flex flex-1 flex-col">
                                            <span className="font-medium">{product.name}</span>
                                            <span className="font-mono text-xs opacity-70">{product.sku}</span>
                                        </span>

                                        <MoneyLabel money={product.price} />

                                        {product.status === 'published' ? null : (
                                            <Badge tone="warning" outline>
                                                No se muestra
                                            </Badge>
                                        )}

                                        <Button size="sm" variant="ghost" onClick={() => remove(product.id)}>
                                            Quitar
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
                <p className="text-sm opacity-70">
                    {isDirty ? 'Tienes cambios sin guardar.' : 'Todo guardado.'} El orden de esta lista es el que verán tus clientes.
                </p>

                <Button onClick={save} disabled={!isDirty} loading={saving}>
                    Guardar orden
                </Button>
            </div>
        </section>
    );
}
