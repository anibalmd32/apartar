import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { SelectField } from '@/components/ui/select-field';
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
 * ## Subir y bajar, no arrastrar
 *
 * RB-2 pide orden manual y el gesto natural es el arrastre, pero el proyecto
 * no tiene todavía ninguna dependencia de *drag & drop* —llega con
 * `useSortableProducts` en `T-031`— y escribirla a mano con la API nativa del
 * navegador no funciona con el dedo, que es con lo que se usa este backoffice.
 * Los dos botones hacen el mismo trabajo, se usan con el teclado y guardan por
 * **el mismo contrato**: la lista entera de identificadores en su orden, que
 * es lo que el arrastre mandará el día que llegue.
 */

interface CategoryRowProps {
    category: CategoryView;
    /** Las demás categorías, como destinos posibles de la reasignación. */
    others: readonly CategoryView[];
    isFirst: boolean;
    isLast: boolean;
    onEdit: () => void;
    onMove: (direction: -1 | 1) => void;
}

export function CategoryRow({ category, others, isFirst, isLast, onEdit, onMove }: CategoryRowProps) {
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
        <tr>
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
                <div className="flex gap-1">
                    <Button size="sm" variant="ghost" onClick={() => onMove(-1)} disabled={isFirst} aria-label={`Subir ${category.name}`}>
                        ↑
                    </Button>

                    <Button size="sm" variant="ghost" onClick={() => onMove(1)} disabled={isLast} aria-label={`Bajar ${category.name}`}>
                        ↓
                    </Button>
                </div>
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
    );
}
