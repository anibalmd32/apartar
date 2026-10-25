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
    ids: readonly UniqueIdentifier[];
    onReorder: (from: number, to: number) => void;
    labelOf: LabelOf;
    children: ReactNode;
}

export function SortableList({ ids, onReorder, labelOf, children }: SortableListProps) {
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 8 } }),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
    );

    const handleDragEnd = ({ active, over }: DragEndEvent) => {
        if (over === null || active.id === over.id) {
            return;
        }

        const from = ids.indexOf(active.id);
        const to = ids.indexOf(over.id);

        if (from === -1 || to === -1) {
            return;
        }

        onReorder(from, to);
    };

    return (
        <DndContext
            sensors={sensors}
            collisionDetection={closestCenter}
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

interface SortableSlots {
    readonly containerProps: {
        readonly ref: (node: HTMLElement | null) => void;
        readonly style: CSSProperties;
        readonly className: string | undefined;
    };
    readonly handleProps: Record<string, unknown>;
    readonly isDragging: boolean;
}

interface SortableRowProps {
    id: UniqueIdentifier;
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

export function SortableHandle({ handleProps }: { handleProps: Record<string, unknown> }) {
    return (
        <button type="button" className="btn btn-ghost btn-sm cursor-grab px-2 active:cursor-grabbing" {...handleProps}>
            <span aria-hidden="true">⠿</span>
        </button>
    );
}
