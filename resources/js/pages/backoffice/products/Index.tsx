import { Alert } from '@/components/ui/alert';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { ProductRow } from '@/features/products/components/ProductRow';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type ProductFilters, type ProductListItem, type ProductStatus, type ProductStatusDefinition, type TaxonomyOption } from '@/types/product';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * El inventario del negocio (`REQ-BOF-020` RB-9).
 *
 * **Es una lista, todavía.** Con sus filtros por estado, categoría y stock
 * bajo, su búsqueda por nombre y SKU, y la columna de en cuántos catálogos
 * está cada producto — pero sin la tabla genérica server-driven de
 * `REQ-BOF-045`, que trae `T-038`. Escribirla aquí sería construir dos veces la
 * misma pieza, y la segunda tendría que desmontar la primera.
 *
 * Los filtros viven en la query string y no en el estado del componente: la
 * URL es compartible y reproducible, que es lo mismo que pide `REQ-CAT-006`
 * RB-6 para el catálogo público. Cambiar uno recarga solo el listado.
 *
 * ## Y es donde aterriza el rechazo de publicar
 *
 * `REQ-BOF-020` SP-3 pide «la lista de lo que falta», no un «está
 * incompleto», y la fila desde la que se pulsó «Publicar» no tiene sitio para
 * cuatro mensajes. Así que el resumen —el que los nombra todos de una vez—
 * llega bajo `status` y se pinta arriba; el detalle por campo viaja igual, y
 * lo lee el formulario cuando se abre el producto para arreglarlo. Es el mismo
 * reparto que hace la pantalla de categorías con `category_id`.
 */

interface ProductsPageProps {
    products: ProductListItem[];
    filters: ProductFilters;
    categories: TaxonomyOption[];
    statuses: ProductStatusDefinition[];
    [key: string]: unknown;
}

export default function ProductsIndex({ products, filters, categories, statuses }: ProductsPageProps) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    const [search, setSearch] = useState(filters.search ?? '');

    const statusLabels = Object.fromEntries(statuses.map((status) => [status.value, status.label])) as Record<ProductStatus, string>;

    const applyFilters = (changes: Record<string, string>) => {
        router.get(
            '/backoffice/productos',
            {
                status: filters.status ?? '',
                category: filters.category === null ? '' : String(filters.category),
                low_stock: filters.lowStock ? '1' : '',
                q: filters.search ?? '',
                ...changes,
            },
            { preserveScroll: true, preserveState: true, only: ['products', 'filters'] },
        );
    };

    // La búsqueda espera a que se deje de escribir, como la del catálogo
    // (`REQ-CAT-002` RB-6): una petición por tecla sería una petición por
    // tecla.
    useEffect(() => {
        if (search === (filters.search ?? '')) {
            return;
        }

        const timer = window.setTimeout(() => applyFilters({ q: search }), 300);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    return (
        <BackofficeLayout
            title="Productos"
            description="Lo que vendes. Desde aquí lo creas, lo editas y lo archivas cuando deja de estar a la venta."
        >
            {errors.status ? <Alert tone="warning">{errors.status}</Alert> : null}

            <section className="card bg-base-100 shadow-sm">
                <div className="card-body gap-4">
                    <header className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 className="card-title text-base">Tu inventario</h2>

                        <Link className="btn btn-primary btn-sm" href="/backoffice/productos/crear">
                            Nuevo producto
                        </Link>
                    </header>

                    <div className="grid gap-2 sm:grid-cols-3">
                        <TextField
                            label="Buscar"
                            name="q"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            hint="Por nombre o por SKU."
                            autoComplete="off"
                        />

                        <SelectField
                            label="Estado"
                            name="status"
                            options={[{ value: '', label: 'Todos' }, ...statuses.map((status) => ({ value: status.value, label: status.label }))]}
                            value={filters.status ?? ''}
                            onChange={(event) => applyFilters({ status: event.target.value })}
                        />

                        <SelectField
                            label="Categoría"
                            name="category"
                            options={[
                                { value: '', label: 'Todas' },
                                ...categories.map((category) => ({ value: String(category.id), label: category.name })),
                            ]}
                            value={filters.category === null ? '' : String(filters.category)}
                            onChange={(event) => applyFilters({ category: event.target.value })}
                        />

                        <label className="label cursor-pointer gap-2 sm:col-span-3">
                            <input
                                type="checkbox"
                                className="checkbox checkbox-sm"
                                checked={filters.lowStock}
                                onChange={(event) => applyFilters({ low_stock: event.target.checked ? '1' : '' })}
                            />
                            <span className="label-text">Solo los que se están acabando</span>
                        </label>
                    </div>

                    {products.length === 0 ? (
                        <p className="text-sm opacity-70">No hay productos con estos filtros. Crea el primero desde el botón de arriba.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="table">
                                <thead>
                                    <tr>
                                        <th scope="col">Producto</th>
                                        <th scope="col">Categoría</th>
                                        <th scope="col">Precio</th>
                                        <th scope="col">Estado</th>
                                        <th scope="col" className="text-right">
                                            Acciones
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    {products.map((product) => (
                                        <ProductRow key={product.id} product={product} statusLabels={statusLabels} />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <p className="text-xs opacity-70">
                        Archivar no borra: el producto conserva su historial y su sitio en los catálogos, y deja de mostrarse y de poder apartarse.
                        Restaurarlo lo devuelve como borrador, para que lo revises antes de que vuelva a verse.
                    </p>
                </div>
            </section>
        </BackofficeLayout>
    );
}
