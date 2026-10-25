import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { SectionCard } from '@/components/ui/section-card';
import { SortableList } from '@/components/ui/sortable';
import { CategoryRow } from '@/features/catalog/components/CategoryRow';
import { TaxonomyForm } from '@/features/catalog/components/TaxonomyForm';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type CategoryView } from '@/types/taxonomy';
import { cilCheckAlt, cilMenu } from '@coreui/icons';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface CategoriesPageProps {
    categories: CategoryView[];
    [key: string]: unknown;
}

export default function CategoriesIndex({ categories }: CategoriesPageProps) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    const [order, setOrder] = useState<CategoryView[]>(categories);
    const [editing, setEditing] = useState<number | null>(null);

    useEffect(() => setOrder(categories), [categories]);

    const editable = order.find((category) => category.id === editing);
    const dirty = order.some((category, index) => category.id !== categories[index]?.id);

    const move = (from: number, to: number) => {
        setOrder((current) => {
            const next = [...current];
            const [moved] = next.splice(from, 1);

            if (moved === undefined) {
                return current;
            }

            next.splice(to, 0, moved);

            return next;
        });
    };

    const saveOrder = () =>
        router.patch('/backoffice/categorias/orden', { categories: order.map((category) => category.id) }, { preserveScroll: true });

    return (
        <BackofficeLayout
            title="Categorías"
            description="Cómo ordenas tu inventario. Tus clientes filtran el catálogo con ellas, así que el orden que fijes aquí es el que ven."
            actions={
                <Button variant="secondary" onClick={saveOrder} disabled={!dirty}>
                    <Icon icon={cilCheckAlt} />
                    Guardar orden
                </Button>
            }
        >
            {errors.category_id ? <Alert tone="warning">{errors.category_id}</Alert> : null}

            <SectionCard
                title={editable ? `Editar «${editable.name}»` : 'Añadir una categoría'}
                subtitle="Cada negocio tiene sus propias categorías: nadie más las ve y no se comparan con las de otros."
            >
                <TaxonomyForm
                    key={editable?.id ?? 'nueva'}
                    createUrl="/backoffice/categorias"
                    editUrl={(id) => `/backoffice/categorias/${id}`}
                    entry={editable}
                    noun="categoría"
                    maxLength={120}
                    onDone={editable ? () => setEditing(null) : undefined}
                />
            </SectionCard>

            <div className="flex flex-col gap-2.5">
                <p className="text-ink-3 flex items-center gap-2 text-xs">
                    <Icon icon={cilMenu} size={14} />
                    Arrastra las filas para cambiar el orden y pulsa «Guardar orden».
                </p>

                <SectionCard flush aria-label="Tus categorías" className="overflow-hidden">
                    {order.length === 0 ? (
                        <p className="text-ink-3 p-5 text-sm">Todavía no tienes ninguna. Añade la primera arriba.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="table min-w-[640px]">
                                <thead>
                                    <tr>
                                        <th scope="col">
                                            <span className="sr-only">Orden</span>
                                        </th>
                                        <th scope="col">Categoría</th>
                                        <th scope="col">Productos</th>
                                        <th scope="col" className="text-right">
                                            Acciones
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <SortableList
                                        ids={order.map((category) => category.id)}
                                        onReorder={move}
                                        labelOf={(id) => order.find((category) => category.id === id)?.name ?? 'la categoría'}
                                    >
                                        {order.map((category) => (
                                            <CategoryRow
                                                key={category.id}
                                                category={category}
                                                others={order.filter((other) => other.id !== category.id)}
                                                onEdit={() => setEditing(category.id)}
                                            />
                                        ))}
                                    </SortableList>
                                </tbody>
                            </table>
                        </div>
                    )}
                </SectionCard>

                <p className="text-ink-3 text-xs">
                    Una categoría con productos no se puede eliminar: muévelos antes a otra. El conteo incluye los borradores y los archivados.
                </p>
            </div>
        </BackofficeLayout>
    );
}
