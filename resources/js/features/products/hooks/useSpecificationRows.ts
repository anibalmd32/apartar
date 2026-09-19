import { type ProductFormValues, type SpecificationFormRow } from '@/types/product';
import { useRef, useState } from 'react';

/**
 * Las filas de la ficha técnica mientras se editan
 * (`REQ-BOF-021` RB-1, SP-6).
 *
 * Añadir, quitar y mover, con **claves locales**. Esa es toda la idea y es lo
 * que SP-6 pide: una fila recién añadida no tiene `id` de base hasta que se
 * guarda, así que si el `key` de React y el orden dependieran del `id`, tres
 * filas nuevas compartirían `null` — React las reutilizaría mal al reordenar y
 * el texto acabaría en la fila equivocada delante de quien lo está
 * escribiendo. La clave se asigna al nacer la fila y no cambia al moverla.
 *
 * ## El orden de este array **es** el orden
 *
 * No hay campo `position` en el cliente: la lista se envía tal como se ve y el
 * servidor la renumera contigua desde 1 (RB-4). Llevar aquí un número que el
 * servidor va a recalcular sería una segunda verdad sobre el orden, y la que
 * se quedaría vieja es siempre la del formulario.
 *
 * ## Y el `id` viaja sin usarse
 *
 * Se conserva por información —para que la pantalla pueda distinguir una fila
 * guardada de una nueva— y **el servidor lo ignora**: el guardado es un
 * reemplazo completo (RB-5), así que las filas se borran y se reinsertan. Un
 * `id` que sobreviviera al guardado sería una promesa que el reemplazo no
 * puede cumplir.
 */

/** Lo que se manda al servidor por cada fila: sin `key` y sin `id`. */
export interface SpecificationPayloadRow {
    group: string;
    label: string;
    value: string;
}

/**
 * Las filas iniciales: las guardadas del producto, o ninguna.
 *
 * Ninguna y no una vacía de cortesía: una ficha en blanco con una fila a medio
 * rellenar sería un `422` esperando a que alguien pulse Guardar sin haberla
 * querido.
 */
function initialRows(nextKey: () => string, product?: ProductFormValues | null): SpecificationFormRow[] {
    return (product?.specifications ?? []).map((specification) => ({
        key: nextKey(),
        id: specification.id,
        group: specification.group ?? '',
        label: specification.label,
        value: specification.value,
    }));
}

export function useSpecificationRows(product?: ProductFormValues | null) {
    // Un contador y no `crypto.randomUUID()`: la clave solo tiene que ser
    // única dentro de esta lista y estable mientras la pantalla vive, y un
    // contador lo es sin depender de una API que jsdom no siempre trae.
    const counter = useRef(0);
    const nextKey = () => {
        counter.current += 1;

        return `row-${counter.current}`;
    };

    const [rows, setRows] = useState<SpecificationFormRow[]>(() => initialRows(nextKey, product));

    const add = () => {
        setRows((current) => [...current, { key: nextKey(), id: null, group: '', label: '', value: '' }]);
    };

    const remove = (key: string) => {
        setRows((current) => current.filter((row) => row.key !== key));
    };

    const update = (key: string, field: 'group' | 'label' | 'value', value: string) => {
        setRows((current) => current.map((row) => (row.key === key ? { ...row, [field]: value } : row)));
    };

    /**
     * Mueve una fila una posición arriba o abajo.
     *
     * Se intercambian **los valores** y no las claves: la clave viaja con su
     * fila, que es lo que mantiene el foco y el texto donde estaban mientras
     * alguien reordena una lista que está escribiendo.
     */
    const move = (index: number, direction: -1 | 1) => {
        setRows((current) => {
            const next = [...current];
            const target = index + direction;

            const moved = next[index];
            const displaced = next[target];

            // Los extremos se comprueban por el valor y no por el índice: es
            // lo mismo que mirar los límites del array, y así TypeScript sabe
            // que el intercambio no mete un `undefined` en la lista.
            if (moved === undefined || displaced === undefined) {
                return current;
            }

            next[index] = displaced;
            next[target] = moved;

            return next;
        });
    };

    /**
     * Las filas tal como las espera el servidor, en su orden.
     *
     * Se recortan los espacios aquí y también en el servidor, y las dos veces
     * hacen falta: esta para que el aviso de etiqueta duplicada (RB-7) compare
     * lo mismo que se va a guardar, y aquella porque el formulario no es la
     * única puerta.
     */
    const payload = (): SpecificationPayloadRow[] =>
        rows.map((row) => ({ group: row.group.trim(), label: row.label.trim(), value: row.value.trim() }));

    /**
     * Las claves de las filas cuya etiqueta se repite dentro de su sección
     * (RB-7).
     *
     * **Se avisa y no se bloquea.** Hay fichas legítimas con la misma etiqueta
     * en unidades distintas —«Peso: 2 kg» y «Peso: 4.4 lb»—, así que el
     * servidor lo permite; lo que la pantalla hace es señalarlo por si fue un
     * descuido. La comparación ignora mayúsculas, igual que la agrupación de
     * `REQ-CAT-004` SP-2.
     */
    const duplicateKeys = (): ReadonlySet<string> => {
        const seen = new Map<string, string>();
        const duplicates = new Set<string>();

        for (const row of rows) {
            const label = row.label.trim().toLowerCase();

            if (label === '') {
                continue;
            }

            const signature = `${row.group.trim().toLowerCase()}::${label}`;
            const first = seen.get(signature);

            if (first === undefined) {
                seen.set(signature, row.key);

                continue;
            }

            duplicates.add(first);
            duplicates.add(row.key);
        }

        return duplicates;
    };

    return { rows, add, remove, update, move, payload, duplicateKeys };
}
