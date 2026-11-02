import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { FormActionBar } from '@/components/ui/form-action-bar';
import { Icon } from '@/components/ui/icon';
import { SectionCard } from '@/components/ui/section-card';
import { SortableList } from '@/components/ui/sortable';
import { CategoryRow } from '@/features/catalog/components/CategoryRow';
import { TaxonomyForm } from '@/features/catalog/components/TaxonomyForm';
import { useLeaveGuard } from '@/hooks/use-leave-guard';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { moved } from '@/lib/reorder';
import { bo } from '@/lib/surfaces';
import { type CategoryView } from '@/types/taxonomy';
import { cilCheckAlt } from '@coreui/icons';
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
    const [saving, setSaving] = useState(false);

    useEffect(() => setOrder(categories), [categories]);

    const editable = order.find((category) => category.id === editing);
    const dirty = order.some((category, index) => category.id !== categories[index]?.id);

    const move = (from: number, to: number) => setOrder((current) => moved(current, from, to));

    const saveOrder = () =>
        router.patch(
            bo('/categorias/orden'),
            { categories: order.map((category) => category.id) },
            { preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false) },
        );

    const leaveGuard = useLeaveGuard(dirty && !saving, {
        title: '¿Salir sin guardar el orden?',
        description: 'Cambiaste el orden de tus categorías y todavía no lo guardaste. Si sales ahora, se pierde.',
    });

    return (
        <BackofficeLayout
            title="Categorías"
            description="Cómo ordenas tu inventario. Tus clientes filtran el catálogo con ellas, así que el orden que fijes aquí es el que ven."
        >
            {errors.category_id ? <Alert tone="warning">{errors.category_id}</Alert> : null}

            <SectionCard
                title={editable ? `Editar «${editable.name}»` : 'Añadir una categoría'}
                subtitle="Cada negocio tiene sus propias categorías: nadie más las ve y no se comparan con las de otros."
            >
                <TaxonomyForm
                    key={editable?.id ?? 'nueva'}
                    createUrl={bo('/categorias')}
                    editUrl={(id) => bo(`/categorias/${id}`)}
                    entry={editable}
                    noun="categoría"
                    maxLength={120}
                    onDone={editable ? () => setEditing(null) : undefined}
                />
            </SectionCard>

            <div className="flex flex-col gap-2.5">
                <div className="flex items-center justify-between gap-3">
                    <p className="text-ink-3 text-xs">
                        <span className="max-md:hidden">Arrastra las filas</span>
                        <span className="md:hidden">Usa las flechas</span> para cambiar el orden y guárdalo al terminar.
                    </p>

                    {dirty ? (
                        <Button size="sm" variant="ghost" className="touch:min-h-11 shrink-0" onClick={() => setOrder(categories)}>
                            Deshacer
                        </Button>
                    ) : null}
                </div>

                <SectionCard
                    flush
                    aria-label="Tus categorías"
                    className={
                        order.length === 0
                            ? 'overflow-hidden'
                            : 'max-md:overflow-visible max-md:border-0 max-md:bg-transparent max-md:shadow-none md:overflow-hidden'
                    }
                >
                    {order.length === 0 ? (
                        <p className="text-ink-3 p-5 text-sm">Todavía no tienes ninguna. Añade la primera arriba.</p>
                    ) : (
                        <div className="table-stack md:overflow-x-auto">
                            <table className="table md:min-w-[640px]">
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
                                        {order.map((category, index) => (
                                            <CategoryRow
                                                key={category.id}
                                                category={category}
                                                others={order.filter((other) => other.id !== category.id)}
                                                onEdit={() => setEditing(category.id)}
                                                onMoveUp={index === 0 ? undefined : () => move(index, index - 1)}
                                                onMoveDown={index === order.length - 1 ? undefined : () => move(index, index + 1)}
                                            />
                                        ))}
                                    </SortableList>
                                </tbody>
                            </table>
                        </div>
                    )}
                </SectionCard>

                {order.length > 1 ? (
                    <FormActionBar sticky={dirty} className="max-lg:-mx-4 max-lg:px-4 lg:justify-end">
                        <Button variant={dirty ? 'primary' : 'secondary'} onClick={saveOrder} disabled={!dirty} loading={saving}>
                            <Icon icon={cilCheckAlt} />
                            Guardar orden
                        </Button>
                    </FormActionBar>
                ) : null}

                <p className="text-ink-3 text-xs">
                    Una categoría con productos no se puede eliminar: muévelos antes a otra. El conteo incluye los borradores y los archivados.
                </p>
            </div>
            {leaveGuard}
        </BackofficeLayout>
    );
}
