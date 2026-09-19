import { Button } from '@/components/ui/button';
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
 * ## Subir y bajar, no arrastrar
 *
 * RB-1 dice «reordenar por arrastre» y el gesto natural es ese, pero el
 * proyecto todavía no tiene ninguna dependencia de *drag & drop* —llega con
 * `useSortableProducts` en `T-031`— y la API nativa del navegador no responde
 * al dedo, que es con lo que se usa este backoffice. Es la misma decisión que
 * `T-026` tomó con el orden de las categorías, y se cierra igual: lo que
 * manda el formulario es **la lista entera en su orden**, así que cambiar el
 * gesto no toca ni el contrato ni el servidor.
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
                        {rows.map((row, index) => (
                            <li key={row.key} className="border-base-300 grid gap-2 border-b pb-3 last:border-0 sm:grid-cols-12">
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

                                <div className="flex items-end gap-1 sm:col-span-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => move(index, -1)}
                                        disabled={index === 0}
                                        aria-label={`Subir la fila ${index + 1}`}
                                    >
                                        ↑
                                    </Button>

                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => move(index, 1)}
                                        disabled={index === rows.length - 1}
                                        aria-label={`Bajar la fila ${index + 1}`}
                                    >
                                        ↓
                                    </Button>

                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => remove(row.key)}
                                        aria-label={`Quitar la fila ${index + 1}`}
                                    >
                                        ✕
                                    </Button>
                                </div>

                                {duplicates.has(row.key) ? (
                                    <p className="text-xs opacity-70 sm:col-span-12">
                                        Repites esta característica dentro de la misma sección. Se guarda igual: a veces es a propósito, como un peso
                                        en kilos y en libras.
                                    </p>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                )}

                <p className="text-xs opacity-70">
                    Hasta 50 características. Las que dejes sin sección se muestran primero, bajo el rótulo «General».
                </p>
            </div>
        </section>
    );
}
