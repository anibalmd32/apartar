import { Search } from 'lucide-react';

/**
 * El buscador del catálogo (`REQ-CAT-002`).
 *
 * Solo el campo: la espera de 300 ms y la visita son de `useCatalogFilters`,
 * que es quien sabe qué más hay seleccionado. Aquí se escribe y se enseña lo
 * escrito, sin retraso —un campo que tarda en pintar la tecla parece roto—.
 *
 * `type="search"` para que el teclado del móvil ofrezca «Buscar» y el
 * navegador ponga su botón de borrar; `maxLength` es el mismo tope que recorta
 * el servidor (SP-3), para que lo que se ve sea lo que se busca. Y no hay
 * `<form>`: pulsar Intro no recarga la página, porque la búsqueda ya salió.
 *
 * Busca **en este catálogo** y lo dice: no hay búsqueda entre negocios
 * (RB-1), y el texto de ayuda evita que alguien lo espere.
 */

/** El mismo que `FullTextTerm::MAX_LENGTH`. */
export const SEARCH_MAX_LENGTH = 100;

interface SearchInputProps {
    value: string;
    onChange: (text: string) => void;
}

export function SearchInput({ value, onChange }: SearchInputProps) {
    return (
        <label className="input w-full">
            <Search aria-hidden className="size-4 opacity-60" />
            <input
                type="search"
                className="grow"
                value={value}
                maxLength={SEARCH_MAX_LENGTH}
                placeholder="Buscar en este catálogo"
                aria-label="Buscar en este catálogo"
                autoComplete="off"
                onChange={(event) => onChange(event.target.value)}
            />
        </label>
    );
}
