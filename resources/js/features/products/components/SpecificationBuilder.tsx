import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { SectionCard } from '@/components/ui/section-card';
import { SortableHandle, SortableList, SortableRow } from '@/components/ui/sortable';
import { MAX_SPECIFICATION_LENGTH, MAX_SPECIFICATIONS, type SpecificationRows } from '@/features/products/hooks/useSpecificationRows';
import { cn } from '@/lib/utils';
import { cilPlus, cilTrash } from '@coreui/icons';
import { type KeyboardEvent, useEffect, useId, useRef } from 'react';

interface SpecificationBuilderProps {
    rows: SpecificationRows;
    errors: Partial<Record<string, string>>;
}

export function SpecificationBuilder({ rows: sheet, errors }: SpecificationBuilderProps) {
    const baseId = useId();
    const listRef = useRef<HTMLUListElement>(null);
    const pendingFocus = useRef<string | null>(null);

    const { rows, add, remove, update, move } = sheet;
    const duplicates = sheet.duplicateKeys();
    const isFull = rows.length >= MAX_SPECIFICATIONS;

    useEffect(() => {
        const key = pendingFocus.current;

        if (key === null) {
            return;
        }

        const input = listRef.current?.querySelector<HTMLInputElement>(`input[data-row="${key}"]`);

        if (input) {
            input.focus();
            pendingFocus.current = null;
        }
    }, [rows]);

    const addAfter = (afterKey?: string) => {
        if (isFull) {
            return;
        }

        pendingFocus.current = add(afterKey);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>, key: string) => {
        if (event.key !== 'Enter' || event.nativeEvent.isComposing) {
            return;
        }

        event.preventDefault();
        addAfter(key);
    };

    const labelAt = (index: number) => `la característica ${index + 1}`;

    return (
        <SectionCard title="Características" subtitle="Escribe una por línea. En la página del producto se muestran como una lista, en este orden.">
            {errors.specifications ? (
                <p role="alert" className="label text-error text-xs">
                    {errors.specifications}
                </p>
            ) : null}

            {rows.length === 0 ? (
                <p className="text-ink-3 text-sm">Sin características. Puedes publicar el producto igual: la lista se omite cuando está vacía.</p>
            ) : (
                <ul ref={listRef} className="flex flex-col gap-2">
                    <SortableList
                        ids={rows.map((row) => row.key)}
                        onReorder={move}
                        labelOf={(key) => labelAt(rows.findIndex((row) => row.key === key))}
                    >
                        {rows.map((row, index) => {
                            const error =
                                errors[`specifications.${index}`] ??
                                (duplicates.has(row.key) ? 'Esta característica ya está en la lista.' : undefined);
                            const errorId = `${baseId}-${row.key}-error`;

                            return (
                                <SortableRow key={row.key} id={row.key} label={labelAt(index)}>
                                    {({ containerProps, handleProps }) => (
                                        <li
                                            ref={containerProps.ref}
                                            style={containerProps.style}
                                            className={cn('flex flex-col gap-1', containerProps.className)}
                                        >
                                            <div className="flex items-center gap-2">
                                                <SortableHandle handleProps={handleProps} />

                                                <label className={cn('input w-full', error && 'input-error')}>
                                                    <input
                                                        data-row={row.key}
                                                        className="grow"
                                                        name={`specifications[${index}]`}
                                                        value={row.text}
                                                        onChange={(event) => update(row.key, event.target.value)}
                                                        onKeyDown={(event) => onKeyDown(event, row.key)}
                                                        placeholder="Ej.: Pantalla de 6,5 pulgadas"
                                                        aria-label={`Característica ${index + 1}`}
                                                        aria-invalid={error ? true : undefined}
                                                        aria-describedby={error ? errorId : undefined}
                                                        maxLength={MAX_SPECIFICATION_LENGTH}
                                                        autoComplete="off"
                                                    />
                                                </label>

                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => remove(row.key)}
                                                    aria-label={`Quitar ${labelAt(index)}`}
                                                    className="btn-square text-ink-3 hover:text-error"
                                                >
                                                    <Icon icon={cilTrash} />
                                                </Button>
                                            </div>

                                            {error ? (
                                                <p id={errorId} role="alert" className="text-error pl-10 text-xs">
                                                    {error}
                                                </p>
                                            ) : null}
                                        </li>
                                    )}
                                </SortableRow>
                            );
                        })}
                    </SortableList>
                </ul>
            )}

            <Button type="button" size="sm" variant="soft" onClick={() => addAfter()} disabled={isFull} className="self-start">
                <Icon icon={cilPlus} />
                Agregar característica
            </Button>

            <p className="text-ink-3 text-xs">
                Hasta {MAX_SPECIFICATIONS} características. Pulsa Intro para pasar a la siguiente y arrastra por el tirador de la izquierda para
                cambiar el orden.
            </p>
        </SectionCard>
    );
}
