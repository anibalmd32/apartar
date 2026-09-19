import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { CategoryRow } from '@/features/catalog/components/CategoryRow';
import { TaxonomyForm } from '@/features/catalog/components/TaxonomyForm';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type CategoryView } from '@/types/taxonomy';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * Las categorías del inventario (`REQ-BOF-023`).
 *
 * ## El orden se reordena en local y se guarda a propósito
 *
 * Mover una fila no manda nada: cambia la lista que se está viendo y enciende
 * el botón «Guardar orden». Es la misma decisión que `REQ-CAT-023` RB-8 toma
 * para los productos de un catálogo —persistencia explícita, no autoguardado
 * por gesto— y aquí importa igual: quien está colocando cinco categorías hace
 * ocho movimientos, y ocho peticiones dejarían el orden a medias si la tercera
 * falla.
 *
 * Lo que se envía es **la lista entera de identificadores**, no «esta bajó una
 * posición». El servidor renumera `1..n` con lo que reciba y manda al final lo
 * que no venga, así que dos personas ordenando a la vez no tienen un conflicto
 * que resolver: gana quien guardó después (SP-5) y no desaparece nadie.
 *
 * ## Los dos `422` de esta pantalla aterrizan arriba
 *
 * Borrar una categoría con productos y cambiar el enlace de una con productos
 * publicados son rechazos que llegan del servidor **después** de pulsar, no
 * cosas que la pantalla pueda anticipar. Se pintan como aviso con su conteo
 * dentro, que es lo que convierte «no puedes» en «reasigna estos tres».
 */

interface CategoriesPageProps {
    categories: CategoryView[];
    [key: string]: unknown;
}

export default function CategoriesIndex({ categories }: CategoriesPageProps) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    const [order, setOrder] = useState<CategoryView[]>(categories);
    const [editing, setEditing] = useState<number | null>(null);

    // La lista vuelve del servidor tras cada guardado: sin esto, crear una
    // categoría dejaría la pantalla mostrando la lista de antes.
    useEffect(() => setOrder(categories), [categories]);

    const editable = order.find((category) => category.id === editing);
    const dirty = order.some((category, index) => category.id !== categories[index]?.id);

    const move = (index: number, direction: -1 | 1) => {
        const next = [...order];
        const target = index + direction;

        const moved = next[index];
        const displaced = next[target];

        // Los dos extremos se comprueban por el valor y no por el índice: es
        // lo mismo que mirar los límites del array, y así TypeScript sabe que
        // el intercambio no mete un `undefined` en la lista.
        if (moved === undefined || displaced === undefined) {
            return;
        }

        next[index] = displaced;
        next[target] = moved;

        setOrder(next);
    };

    const saveOrder = () =>
        router.patch('/backoffice/categorias/orden', { categories: order.map((category) => category.id) }, { preserveScroll: true });

    return (
        <BackofficeLayout
            title="Categorías"
            description="Cómo ordenas tu inventario. Tus clientes filtran el catálogo con ellas, así que el orden que fijes aquí es el que ven."
        >
            {errors.category_id ? <Alert tone="warning">{errors.category_id}</Alert> : null}

            <section className="card bg-base-100 shadow-sm">
                <div className="card-body gap-4">
                    <h2 className="card-title text-base">{editable ? 'Editar categoría' : 'Añadir una categoría'}</h2>

                    <TaxonomyForm
                        key={editable?.id ?? 'nueva'}
                        createUrl="/backoffice/categorias"
                        editUrl={(id) => `/backoffice/categorias/${id}`}
                        entry={editable}
                        noun="categoría"
                        maxLength={120}
                        onDone={editable ? () => setEditing(null) : undefined}
                    />

                    <p className="text-xs opacity-70">
                        Cada negocio tiene sus propias categorías: nadie más las ve y no se comparan con las de otros.
                    </p>
                </div>
            </section>

            <section className="card bg-base-100 shadow-sm">
                <div className="card-body gap-4">
                    <header className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 className="card-title text-base">Tus categorías</h2>

                        <Button size="sm" onClick={saveOrder} disabled={!dirty}>
                            Guardar orden
                        </Button>
                    </header>

                    {order.length === 0 ? (
                        <p className="text-sm opacity-70">Todavía no tienes ninguna. Añade la primera arriba.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="table">
                                <thead>
                                    <tr>
                                        <th scope="col">Categoría</th>
                                        <th scope="col">Productos</th>
                                        <th scope="col">Orden</th>
                                        <th scope="col" className="text-right">
                                            Acciones
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    {order.map((category, index) => (
                                        <CategoryRow
                                            key={category.id}
                                            category={category}
                                            others={order.filter((other) => other.id !== category.id)}
                                            isFirst={index === 0}
                                            isLast={index === order.length - 1}
                                            onEdit={() => setEditing(category.id)}
                                            onMove={(direction) => move(index, direction)}
                                        />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <p className="text-xs opacity-70">
                        Una categoría con productos no se puede eliminar: muévelos antes a otra. El conteo incluye los borradores y los archivados.
                    </p>
                </div>
            </section>
        </BackofficeLayout>
    );
}
