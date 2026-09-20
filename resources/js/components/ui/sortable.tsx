import { cn } from '@/lib/utils';
import {
    DndContext,
    KeyboardSensor,
    PointerSensor,
    closestCenter,
    useSensor,
    useSensors,
    type Announcements,
    type DragEndEvent,
    type ScreenReaderInstructions,
    type UniqueIdentifier,
} from '@dnd-kit/core';
import { restrictToParentElement, restrictToVerticalAxis } from '@dnd-kit/modifiers';
import { SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { type CSSProperties, type ReactNode } from 'react';

/**
 * Listas que se reordenan arrastrando (`REQ-CAT-023` RB-8).
 *
 * Es el único sitio del proyecto que toca *drag & drop*, y por eso está aquí:
 * las cuatro listas que se ordenan a mano —productos de un catálogo,
 * categorías, filas de la ficha técnica e imágenes de producto— repetirían si
 * no las mismas reglas de accesibilidad, que son justo lo que el README de
 * este directorio dice que los átomos existen para no repetir.
 *
 * ## Por qué una dependencia y no la API del navegador
 *
 * `T-026`, `T-028` y `T-029` dejaron sus listas con botones de subir y bajar y
 * el motivo escrito: la API nativa de *drag and drop* **no responde al dedo**,
 * y este backoffice se usa desde el teléfono. `@dnd-kit` trabaja sobre eventos
 * de puntero, así que el mismo gesto vale para el ratón y para el dedo.
 *
 * ## El teclado no es un extra, es la mitad del gesto
 *
 * Un arrastre que solo funcione con el puntero deja fuera a quien navega con
 * el teclado — y sustituye unos botones que **sí** funcionaban con él. Por eso
 * el `KeyboardSensor` va puesto siempre, el tirador es un `<button>` de
 * verdad, y los anuncios van en español: los de la librería están en inglés y
 * quien los oye está leyendo una pantalla escrita en español.
 *
 * ## Dos piezas y no una
 *
 * `SortableList` monta el contexto y decide qué significa soltar;
 * `SortableRow` envuelve cada elemento y entrega sus dos juegos de props por
 * separado —uno para el elemento que se mueve, otro para el tirador— porque
 * cada lista tiene su propia etiqueta: una es un `<tr>`, otra una tarjeta. La
 * alternativa, pintar el marcado aquí, habría obligado a las cuatro a
 * parecerse.
 */

/** Cómo se llama cada elemento cuando el lector de pantalla lo anuncia. */
type LabelOf = (id: UniqueIdentifier) => string;

const INSTRUCTIONS: ScreenReaderInstructions = {
    draggable:
        'Para reordenar, pulsa la barra espaciadora y mueve con las flechas arriba y abajo. ' +
        'Pulsa espacio otra vez para soltar, o Escape para dejarlo como estaba.',
};

function announcementsFor(labelOf: LabelOf): Announcements {
    return {
        onDragStart: ({ active }) => `Has levantado ${labelOf(active.id)}.`,
        onDragOver: ({ active, over }) =>
            over === null ? `${labelOf(active.id)} está fuera de la lista.` : `${labelOf(active.id)} está sobre el sitio de ${labelOf(over.id)}.`,
        onDragEnd: ({ active, over }) =>
            over === null
                ? `Has soltado ${labelOf(active.id)} sin cambiar el orden.`
                : `Has soltado ${labelOf(active.id)} en el sitio de ${labelOf(over.id)}.`,
        onDragCancel: ({ active }) => `Has dejado ${labelOf(active.id)} donde estaba.`,
    };
}

interface SortableListProps {
    /** Los identificadores en el orden en que se ven ahora. */
    ids: readonly UniqueIdentifier[];
    /** Se llama con los índices de origen y destino; nunca con el mismo dos veces. */
    onReorder: (from: number, to: number) => void;
    labelOf: LabelOf;
    children: ReactNode;
}

export function SortableList({ ids, onReorder, labelOf, children }: SortableListProps) {
    const sensors = useSensors(
        // Los 8 píxeles son lo que separa un arrastre de un clic: sin ellos,
        // pulsar el tirador en una pantalla táctil empezaría a mover la fila
        // antes de que la persona decidiera moverla.
        useSensor(PointerSensor, { activationConstraint: { distance: 8 } }),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
    );

    const handleDragEnd = ({ active, over }: DragEndEvent) => {
        if (over === null || active.id === over.id) {
            return;
        }

        const from = ids.indexOf(active.id);
        const to = ids.indexOf(over.id);

        // Entre soltar y recibir el evento, la lista pudo cambiar: un índice
        // que ya no existe es un reordenamiento que no se puede aplicar, y
        // aplicarlo con `-1` movería la fila al principio sin que nadie lo
        // pidiera.
        if (from === -1 || to === -1) {
            return;
        }

        onReorder(from, to);
    };

    return (
        <DndContext
            sensors={sensors}
            collisionDetection={closestCenter}
            // La lista es vertical y no se sale de su caja: sin los
            // modificadores, arrastrar hacia el lado despega la fila del sitio
            // donde va a caer y el gesto deja de explicarse solo.
            modifiers={[restrictToVerticalAxis, restrictToParentElement]}
            accessibility={{ announcements: announcementsFor(labelOf), screenReaderInstructions: INSTRUCTIONS }}
            onDragEnd={handleDragEnd}
        >
            <SortableContext items={[...ids]} strategy={verticalListSortingStrategy}>
                {children}
            </SortableContext>
        </DndContext>
    );
}

/** Lo que `SortableRow` entrega para el elemento que se mueve. */
interface SortableSlots {
    /** Al elemento arrastrable: la referencia y la transformación mientras se mueve. */
    readonly containerProps: {
        readonly ref: (node: HTMLElement | null) => void;
        readonly style: CSSProperties;
        readonly className: string | undefined;
    };
    /** Al tirador, que tiene que ser un `<button>`. */
    readonly handleProps: Record<string, unknown>;
    readonly isDragging: boolean;
}

interface SortableRowProps {
    id: UniqueIdentifier;
    /** Cómo se llama este elemento, para el nombre accesible del tirador. */
    label: string;
    children: (slots: SortableSlots) => ReactNode;
}

export function SortableRow({ id, label, children }: SortableRowProps) {
    const { attributes, listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } = useSortable({ id });

    return (
        <>
            {children({
                containerProps: {
                    ref: setNodeRef,
                    style: { transform: CSS.Transform.toString(transform), transition },
                    // Mientras se arrastra, la fila se levanta sobre las demás:
                    // sin esto, la que se mueve pasa por debajo y parece que se
                    // ha perdido.
                    className: cn(isDragging && 'relative z-10 opacity-80'),
                },
                handleProps: {
                    ...attributes,
                    ...listeners,
                    ref: setActivatorNodeRef,
                    'aria-label': `Reordenar ${label}`,
                },
                isDragging,
            })}
        </>
    );
}

/**
 * El tirador, con el mismo aspecto en las cuatro listas.
 *
 * Es un `<button>` y no un `<div>` con `role`: tiene que entrar en el orden de
 * tabulación por sí solo, porque el `KeyboardSensor` no puede levantar nada
 * que no se pueda enfocar.
 */
export function SortableHandle({ handleProps }: { handleProps: Record<string, unknown> }) {
    return (
        <button type="button" className="btn btn-ghost btn-sm cursor-grab px-2 active:cursor-grabbing" {...handleProps}>
            <span aria-hidden="true">⠿</span>
        </button>
    );
}
