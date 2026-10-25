import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { SectionCard } from '@/components/ui/section-card';
import { SortableHandle, SortableList, SortableRow } from '@/components/ui/sortable';
import { TextField } from '@/components/ui/text-field';
import { type useSpecificationRows } from '@/features/products/hooks/useSpecificationRows';
import { cilPlus, cilTrash } from '@coreui/icons';
import { useId } from 'react';

interface SpecificationBuilderProps {
    rows: ReturnType<typeof useSpecificationRows>;
    groups: readonly string[];
    errors: Partial<Record<string, string>>;
}

export function SpecificationBuilder({ rows: sheet, groups, errors }: SpecificationBuilderProps) {
    const groupsListId = useId();

    const { rows, add, remove, update, move } = sheet;
    const duplicates = sheet.duplicateKeys();

    const labelAt = (index: number) => `la fila ${index + 1}`;

    return (
        <SectionCard title="Especificaciones" subtitle="Agrupa detalles técnicos. Se muestran en la ficha del producto.">
            <datalist id={groupsListId}>
                {groups.map((group) => (
                    <option key={group} value={group} />
                ))}
            </datalist>

            {errors.specifications ? (
                <p role="alert" className="label text-error text-xs">
                    {errors.specifications}
                </p>
            ) : null}

            {rows.length === 0 ? (
                <p className="text-ink-3 text-sm">
                    Sin características. Puedes publicar el producto igual: la ficha se omite entera cuando está vacía.
                </p>
            ) : (
                <ul className="flex flex-col gap-3">
                    <SortableList
                        ids={rows.map((row) => row.key)}
                        onReorder={move}
                        labelOf={(key) => labelAt(rows.findIndex((row) => row.key === key))}
                    >
                        {rows.map((row, index) => (
                            <SortableRow key={row.key} id={row.key} label={labelAt(index)}>
                                {({ containerProps, handleProps }) => (
                                    <li
                                        ref={containerProps.ref}
                                        style={containerProps.style}
                                        className={`border-line grid grid-cols-1 gap-x-2 border-b pb-3 last:border-0 sm:grid-cols-12 ${containerProps.className ?? ''}`}
                                    >
                                        <div className="flex items-end sm:col-span-1">
                                            <SortableHandle handleProps={handleProps} />
                                        </div>

                                        <div className="sm:col-span-3">
                                            <TextField
                                                label="Sección"
                                                name={`specifications[${index}][group]`}
                                                list={groupsListId}
                                                value={row.group}
                                                onChange={(event) => update(row.key, 'group', event.target.value)}
                                                error={errors[`specifications.${index}.group`]}
                                                maxLength={80}
                                                autoComplete="off"
                                            />
                                        </div>

                                        <div className="sm:col-span-3">
                                            <TextField
                                                label="Característica"
                                                name={`specifications[${index}][label]`}
                                                value={row.label}
                                                onChange={(event) => update(row.key, 'label', event.target.value)}
                                                error={errors[`specifications.${index}.label`]}
                                                maxLength={60}
                                                autoComplete="off"
                                                required
                                            />
                                        </div>

                                        <div className="sm:col-span-4">
                                            <TextField
                                                label="Valor"
                                                name={`specifications[${index}][value]`}
                                                value={row.value}
                                                onChange={(event) => update(row.key, 'value', event.target.value)}
                                                error={errors[`specifications.${index}.value`]}
                                                maxLength={500}
                                                autoComplete="off"
                                                required
                                            />
                                        </div>

                                        <div className="flex items-end sm:col-span-1">
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => remove(row.key)}
                                                aria-label={`Quitar ${labelAt(index)}`}
                                                className="btn-square text-ink-3 hover:text-error mb-1"
                                            >
                                                <Icon icon={cilTrash} />
                                            </Button>
                                        </div>

                                        {duplicates.has(row.key) ? (
                                            <p className="text-ink-3 text-xs sm:col-span-12">
                                                Repites esta característica dentro de la misma sección. Se guarda igual: a veces es a propósito, como
                                                un peso en kilos y en libras.
                                            </p>
                                        ) : null}
                                    </li>
                                )}
                            </SortableRow>
                        ))}
                    </SortableList>
                </ul>
            )}

            <Button type="button" size="sm" variant="soft" onClick={add} className="self-start">
                <Icon icon={cilPlus} />
                Añadir característica
            </Button>

            <p className="text-ink-3 text-xs">
                Hasta 50 características. Las que dejes sin sección se muestran primero, bajo el rótulo «General». Arrastra por el tirador de la
                izquierda para cambiar el orden.
            </p>
        </SectionCard>
    );
}
