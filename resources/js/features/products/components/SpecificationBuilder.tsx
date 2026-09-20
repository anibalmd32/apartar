import { Button } from '@/components/ui/button';
import { SortableHandle, SortableList, SortableRow } from '@/components/ui/sortable';
import { TextField } from '@/components/ui/text-field';
import { type useSpecificationRows } from '@/features/products/hooks/useSpecificationRows';
import { useId } from 'react';

/**
 * El constructor de la ficha técnica (`REQ-BOF-021` RB-1, RB-6, RB-7).
 *
 * Filas de «característica: valor» con su sección opcional, que se añaden, se
 * quitan y se ordenan. Tres decisiones de esta pantalla no son decoración:
 *
 *  - **el orden de la lista es el orden**, y el servidor lo renumera contiguo
 *    desde 1 al guardar (RB-4). Aquí no hay ningún campo de posición que
 *    alguien pueda escribir a mano;
 *  - **la sección se autocompleta** con las que el negocio ya usa (RB-6),
 *    servidas por `SpecificationGroupsQuery`. Nunca las del negocio de al lado
 *    (SP-8), y eso lo garantiza el *global scope*, no este archivo;
 *  - **la etiqueta repetida se avisa, no se bloquea** (RB-7): hay fichas
 *    legítimas con «Peso» dos veces en unidades distintas. El servidor la
 *    acepta y lo único que hace la pantalla es señalarla por si fue un
 *    descuido.
 *
 * ## Se arrastra, desde `T-031`
 *
 * RB-1 pedía «reordenar por arrastre» y hasta ahora eran dos botones de subir
 * y bajar, porque el proyecto no tenía dependencia de *drag & drop* y la API
 * nativa no responde al dedo. La dependencia llegó con `T-031` y **el guardado
 * no se enteró**: la ficha entera sigue viajando en su orden dentro del `PUT`
 * del producto, sin endpoint propio (`REQ-BOF-021` RB-8).
 *
 * El tirador va al principio de la fila y el botón de quitar al final: se
 * agarra por donde empieza lo que se mueve. Y el arrastre funciona con el
 * teclado —espacio para levantar, flechas para mover—, que es lo que los dos
 * botones daban gratis y no se podía perder al cambiarlos.
 *
 * ## Y aquí no se escapa nada
 *
 * El `value` se guarda tal cual y se escapa **al renderizar**
 * (`REQ-CAT-004` RB-4). Sanearlo al escribir convertiría la medida «< 2 kg» en
 * otra cosa delante de quien la está tecleando.
 */

interface SpecificationBuilderProps {
    rows: ReturnType<typeof useSpecificationRows>;
    /** Las secciones ya usadas por el negocio, para autocompletar (RB-6). */
    groups: readonly string[];
    /** Errores del servidor, con el índice de la fila dentro de la clave (SP-1). */
    errors: Partial<Record<string, string>>;
}

export function SpecificationBuilder({ rows: sheet, groups, errors }: SpecificationBuilderProps) {
    const groupsListId = useId();

    const { rows, add, remove, update, move } = sheet;
    const duplicates = sheet.duplicateKeys();

    /**
     * Cómo se nombra una fila en los anuncios del arrastre.
     *
     * Por su **posición** y no por su texto: una fila recién añadida está
     * vacía, y «has levantado la fila» a secas no dice cuál de las nueve.
     */
    const labelAt = (index: number) => `la fila ${index + 1}`;

    return (
        <section className="card bg-base-100 shadow-sm">
            <div className="card-body gap-4">
                <header className="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 className="card-title text-base">Ficha técnica</h2>

                    <Button type="button" size="sm" variant="ghost" onClick={add}>
                        Añadir característica
                    </Button>
                </header>

                {/* RB-6 · las secciones del propio negocio. Una `datalist`
                    nativa y no un combo propio: sugiere sin impedir escribir
                    una nueva, que es exactamente lo que pide un
                    autocompletado. */}
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
                    <p className="text-sm opacity-70">
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
                                            className={`border-base-300 grid gap-2 border-b pb-3 last:border-0 sm:grid-cols-12 ${containerProps.className ?? ''}`}
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
                                                >
                                                    ✕
                                                </Button>
                                            </div>

                                            {duplicates.has(row.key) ? (
                                                <p className="text-xs opacity-70 sm:col-span-12">
                                                    Repites esta característica dentro de la misma sección. Se guarda igual: a veces es a propósito,
                                                    como un peso en kilos y en libras.
                                                </p>
                                            ) : null}
                                        </li>
                                    )}
                                </SortableRow>
                            ))}
                        </SortableList>
                    </ul>
                )}

                <p className="text-xs opacity-70">
                    Hasta 50 características. Las que dejes sin sección se muestran primero, bajo el rótulo «General». Arrastra por el tirador de la
                    izquierda para cambiar el orden.
                </p>
            </div>
        </section>
    );
}
