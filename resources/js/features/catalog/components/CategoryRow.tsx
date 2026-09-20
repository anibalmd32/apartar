import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { SelectField } from '@/components/ui/select-field';
import { SortableHandle, SortableRow } from '@/components/ui/sortable';
import { type CategoryView } from '@/types/taxonomy';
import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Una categoría en la lista, con lo que se puede hacer sobre ella
 * (`REQ-BOF-023`).
 *
 * ## El botón de eliminar no promete que vaya a eliminar
 *
 * Quien decide es el servidor: si la categoría tiene productos, responde `422`
 * con el conteo y la sugerencia de reasignar (SP-1). Aquí **no se adivina** —la
 * cifra de la fila incluye borradores y archivados, y aun así podría haber
 * productos en la papelera que solo ve la base de datos—, así que el botón
 * sigue activo y el motivo llega después. Deshabilitarlo con la cifra de la
 * pantalla sería prometer una regla que la pantalla no puede sostener.
 *
 * Lo que sí se ofrece por delante es la salida: con productos dentro, la fila
 * enseña el desplegable de reasignación, que es literalmente lo que el mensaje
 * del `422` va a pedir.
 *
 * ## Se arrastra, desde `T-031`
 *
 * RB-2 pedía orden manual por arrastre y hasta ahora eran dos botones de subir
 * y bajar, porque el proyecto no tenía ninguna dependencia de *drag & drop* y
 * la API nativa no responde al dedo. La dependencia llegó con `T-031` y el
 * gesto cambió; **el guardado no se enteró**, que era justo la idea: sigue
 * siendo la lista entera de identificadores por el mismo `PATCH`.
 *
 * El tirador es un `<button>` y el arrastre funciona con el teclado
 * —espacio para levantar, flechas para mover—, que es lo que los dos botones
 * daban gratis y no se podía perder al cambiarlos.
 */

interface CategoryRowProps {
    category: CategoryView;
    /** Las demás categorías, como destinos posibles de la reasignación. */
    others: readonly CategoryView[];
    onEdit: () => void;
}

export function CategoryRow({ category, others, onEdit }: CategoryRowProps) {
    const url = `/backoffice/categorias/${category.id}`;

    const [target, setTarget] = useState('');

    const remove = () => router.delete(url, { preserveScroll: true });

    const reassign = () => {
        if (target === '') {
            return;
        }

        router.post(`${url}/reasignar`, { category_id: Number(target) }, { preserveScroll: true, onSuccess: () => setTarget('') });
    };

    return (
        <SortableRow id={category.id} label={category.name}>
            {({ containerProps, handleProps }) => (
                <tr ref={containerProps.ref} style={containerProps.style} className={containerProps.className}>
                    <td>
                        <div className="flex flex-col">
                            <span className="font-medium">{category.name}</span>
                            <span className="font-mono text-xs opacity-70">{category.slug}</span>
                        </div>
                    </td>

                    <td>
                        {/* RB-7 · el conteo incluye borradores y archivados: es lo que
                            explica por qué esta categoría se va a negar a desaparecer. */}
                        <Badge tone={category.productsCount > 0 ? 'info' : 'neutral'}>
                            {category.productsCount === 1 ? '1 producto' : `${category.productsCount} productos`}
                        </Badge>
                    </td>

                    <td>
                        <SortableHandle handleProps={handleProps} />
                    </td>

                    <td className="text-right">
                        <div className="flex flex-wrap items-end justify-end gap-1">
                            {category.productsCount > 0 && others.length > 0 ? (
                                <div className="flex items-end gap-1">
                                    <SelectField
                                        label={`Mover los productos de ${category.name} a`}
                                        hideLabel
                                        name={`reassign-${category.id}`}
                                        className="select-sm"
                                        options={[
                                            { value: '', label: 'Mover sus productos a…' },
                                            ...others.map((other) => ({ value: String(other.id), label: other.name })),
                                        ]}
                                        value={target}
                                        onChange={(event) => setTarget(event.target.value)}
                                    />

                                    <Button size="sm" variant="ghost" onClick={reassign} disabled={target === ''}>
                                        Mover
                                    </Button>
                                </div>
                            ) : null}

                            <Button size="sm" variant="ghost" onClick={onEdit}>
                                Editar
                            </Button>

                            <Button size="sm" variant="ghost" onClick={remove}>
                                Eliminar
                            </Button>
                        </div>
                    </td>
                </tr>
            )}
        </SortableRow>
    );
}
