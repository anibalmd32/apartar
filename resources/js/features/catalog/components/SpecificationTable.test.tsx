import { SpecificationTable } from '@/features/catalog/components/SpecificationTable';
import { type SpecificationGroupView } from '@/types/product';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

/**
 * La ficha técnica pintada (`REQ-CAT-004` RB-2, RB-4, SP-4).
 *
 * El orden y la agrupación los resuelve el servidor —`SpecificationGroups`,
 * con sus tests— así que aquí solo se prueba lo que este componente decide: el
 * rótulo «General», que sin filas no pinta nada, y **el escapado**, que es una
 * regla negativa y por eso necesita una prueba que la afirme.
 */

function group(name: string | null, items: { label: string; value: string }[]): SpecificationGroupView {
    return { group: name, items };
}

describe('la ficha técnica', () => {
    it('pinta cada sección con sus pares', () => {
        render(
            <SpecificationTable
                groups={[
                    group('Motor', [
                        { label: 'Potencia', value: '750 W' },
                        { label: 'Velocidades', value: '2' },
                    ]),
                ]}
            />,
        );

        expect(screen.getByText('Motor')).toBeInTheDocument();
        expect(screen.getByText('Potencia')).toBeInTheDocument();
        expect(screen.getByText('750 W')).toBeInTheDocument();
        expect(screen.getByText('Velocidades')).toBeInTheDocument();
    });

    /**
     * RB-2 · el grupo sin nombre se lee «General», y **el rótulo lo pone la
     * pantalla**: el servidor manda `null` para que una ficha sin secciones no
     * se confunda con una que tiene una sección llamada así.
     */
    it('llama General a la sección sin nombre', () => {
        render(<SpecificationTable groups={[group(null, [{ label: 'Material', value: 'Acero' }])]} />);

        expect(screen.getByText('General')).toBeInTheDocument();
    });

    /**
     * Y respeta el orden en que llegan: la sección sin nombre va primero
     * porque el servidor ya la puso primero, no porque este componente la
     * reordene.
     */
    it('no reordena lo que le llega', () => {
        render(
            <SpecificationTable
                groups={[group(null, [{ label: 'Material', value: 'Acero' }]), group('Motor', [{ label: 'Potencia', value: '750 W' }])]}
            />,
        );

        const headings = screen.getAllByRole('heading').map((heading) => heading.textContent);

        expect(headings).toEqual(['General', 'Motor']);
    });

    /**
     * `REQ-BOF-021` SP-5 · sin filas no se pinta un encabezado vacío: la
     * sección se omite entera.
     */
    it('no pinta nada cuando no hay especificaciones', () => {
        const { container } = render(<SpecificationTable groups={[]} />);

        expect(container).toBeEmptyDOMElement();
    });

    /**
     * **El test de XSS de RB-4 y SP-4.**
     *
     * Un negocio escribe `<script>` en el valor de una característica —por
     * descuido o a propósito— y eso tiene que llegar al cliente **como texto**.
     * Lo que se afirma son las dos mitades: que el texto se lee tal cual, y
     * que no existe ningún elemento `script` en lo renderizado. La segunda es
     * la que importa: si alguien añadiera un `dangerouslySetInnerHTML` a este
     * componente, la primera seguiría pasando y esta no.
     */
    it('escapa el HTML del valor en lugar de ejecutarlo', () => {
        const attack = '<script>alert("xss")</script>';

        const { container } = render(<SpecificationTable groups={[group('Motor', [{ label: 'Potencia', value: attack }])]} />);

        expect(screen.getByText(attack)).toBeInTheDocument();
        expect(container.querySelector('script')).toBeNull();
        expect(container.innerHTML).not.toContain('<script>');
    });

    /**
     * Y la misma regla para la etiqueta, que es el campo que se olvida: un
     * `<img onerror>` en la característica se lee igual de texto.
     */
    it('escapa también el HTML de la característica', () => {
        const attack = '<img src=x onerror="alert(1)">';

        const { container } = render(<SpecificationTable groups={[group(null, [{ label: attack, value: 'Acero' }])]} />);

        expect(screen.getByText(attack)).toBeInTheDocument();
        expect(container.querySelector('img')).toBeNull();
    });

    /**
     * `REQ-CAT-004` SP-1 · un valor largo con saltos de línea se muestra
     * entero: nunca se recorta en silencio.
     */
    it('conserva los saltos de línea del valor', () => {
        const value = 'Alto: 120 cm\nAncho: 80 cm';

        render(<SpecificationTable groups={[group(null, [{ label: 'Medidas', value }])]} />);

        const rendered = screen.getByText(/Alto: 120 cm/);

        expect(rendered.textContent).toBe(value);
        expect(rendered).toHaveClass('whitespace-pre-line');
    });
});
