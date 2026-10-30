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
} from '@dnd-kit/core';
import { SortableContext, arrayMove, rectSortingStrategy, sortableKeyboardCoordinates, useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { type ReactNode } from 'react';

const INSTRUCTIONS: ScreenReaderInstructions = {
    draggable: 'Para mover, pulsa la barra espaciadora y usa las flechas. Pulsa espacio otra vez para soltar, o Escape para dejarlo como estaba.',
};

interface SortableGridProps<T extends string> {
    ids: readonly T[];
    labelOf: (id: T) => string;
    onChange: (ids: T[]) => void;
    className?: string;
    children: ReactNode;
}

export function SortableGrid<T extends string>({ ids, labelOf, onChange, className, children }: SortableGridProps<T>) {
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
    );

    const label = (id: unknown) => labelOf(String(id) as T);

    const announcements: Announcements = {
        onDragStart: ({ active }) => `Has levantado ${label(active.id)}.`,
        onDragOver: ({ active, over }) => (over === null ? `${label(active.id)} está fuera.` : `${label(active.id)} está sobre ${label(over.id)}.`),
        onDragEnd: ({ active, over }) => (over === null ? `Has soltado ${label(active.id)} sin moverlo.` : `Has soltado ${label(active.id)}.`),
        onDragCancel: ({ active }) => `Has dejado ${label(active.id)} donde estaba.`,
    };

    const handleDragEnd = ({ active, over }: DragEndEvent) => {
        if (over === null || active.id === over.id) {
            return;
        }

        const from = ids.indexOf(String(active.id) as T);
        const to = ids.indexOf(String(over.id) as T);

        if (from !== -1 && to !== -1) {
            onChange(arrayMove([...ids], from, to));
        }
    };

    return (
        <DndContext
            sensors={sensors}
            collisionDetection={closestCenter}
            accessibility={{ announcements, screenReaderInstructions: INSTRUCTIONS }}
            onDragEnd={handleDragEnd}
        >
            <SortableContext items={[...ids]} strategy={rectSortingStrategy}>
                <div className={className}>{children}</div>
            </SortableContext>
        </DndContext>
    );
}

interface SortableTileProps {
    id: string;
    label: string;
    editing: boolean;
    className?: string;
    children: ReactNode;
}

export function SortableTile({ id, label, editing, className, children }: SortableTileProps) {
    const { attributes, listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } = useSortable({ id, disabled: !editing });

    return (
        <div
            ref={setNodeRef}
            style={{ transform: CSS.Transform.toString(transform), transition }}
            className={cn(
                'relative min-w-0',
                editing && 'outline-primary/40 rounded-2xl outline-2 outline-offset-2 outline-dashed',
                isDragging && 'z-10 opacity-80',
                className,
            )}
        >
            <div className={cn('h-full', editing && 'pointer-events-none select-none')}>{children}</div>
            {editing ? (
                <button
                    type="button"
                    ref={setActivatorNodeRef}
                    className="btn btn-sm btn-soft btn-primary absolute right-2 bottom-2 cursor-grab touch-none px-2 active:cursor-grabbing"
                    aria-label={`Mover ${label}`}
                    {...attributes}
                    {...listeners}
                >
                    <span aria-hidden="true">⠿</span>
                </button>
            ) : null}
        </div>
    );
}
