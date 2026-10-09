import { useEffect, useRef, useState } from 'react';

/** La pausa antes de filtrar mientras se escribe. */
export const FILTER_DEBOUNCE_MS = 400;

/**
 * Un filtro de texto de una tabla server-driven que espera a que se deje de
 * escribir: la caja de búsqueda de la cobranza o del directorio pediría si no
 * una página al servidor por cada tecla.
 *
 * Devuelve el valor que se está escribiendo —el que pinta la caja— y cómo
 * cambiarlo; la petición sale sola tras la pausa.
 */
export function useDebouncedFilter(
    initial: string | null,
    send: (value: string | null) => void,
): readonly [value: string, change: (value: string) => void] {
    const [value, setValue] = useState(initial ?? '');
    const sent = useRef(initial ?? '');
    // El último `send`, sin reiniciar la pausa: el de `useServerTable` cambia
    // en cada render, y con él la espera no terminaría nunca mientras la tabla
    // se repinta.
    const latest = useRef(send);

    useEffect(() => {
        latest.current = send;
    });

    useEffect(() => {
        if (value === sent.current) {
            return;
        }

        const timer = window.setTimeout(() => {
            sent.current = value;
            latest.current(value.trim() === '' ? null : value);
        }, FILTER_DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [value]);

    return [value, setValue] as const;
}
