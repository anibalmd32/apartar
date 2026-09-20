import { SortableHandle, SortableList, SortableRow } from '@/components/ui/sortable';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

/**
 * El átomo del arrastre (`REQ-CAT-023` RB-8).
 *
 * **Lo que se prueba es el contrato accesible, no el gesto.** Simular un
 * arrastre en jsdom —que no tiene diseño ni cajas— probaría `@dnd-kit`, no
 * este archivo. Lo que sí es nuestro y se puede romper sin que nadie lo note
 * es lo que sustituyó a los botones de subir y bajar: que cada tirador sea un
 * `<button>` enfocable, con nombre propio, y que las instrucciones de teclado
 * lleguen — porque un arrastre sin teclado no reemplaza a unos botones que sí
 * lo tenían.
 */

function renderList(names: string[]) {
    return render(
        <ul>
            <SortableList ids={names} onReorder={vi.fn()} labelOf={(id) => String(id)}>
                {names.map((name) => (
                    <SortableRow key={name} id={name} label={name}>
                        {({ containerProps, handleProps }) => (
                            <li ref={containerProps.ref} style={containerProps.style} className={containerProps.className}>
                                <SortableHandle handleProps={handleProps} />
                                <span>{name}</span>
                            </li>
                        )}
                    </SortableRow>
                ))}
            </SortableList>
        </ul>,
    );
}

describe('el atajo de arrastre', () => {
    /**
     * El tirador tiene que ser enfocable por sí solo: el `KeyboardSensor` no
     * puede levantar nada que no reciba el foco, así que un `<div>` con
     * `role="button"` dejaría el arrastre fuera del teclado sin que nada
     * fallara.
     */
    it('da a cada elemento un tirador enfocable con su propio nombre', () => {
        renderList(['Taladro', 'Sierra']);

        const handle = screen.getByRole('button', { name: 'Reordenar Taladro' });

        expect(handle.tagName).toBe('BUTTON');
        expect(screen.getByRole('button', { name: 'Reordenar Sierra' })).toBeInTheDocument();
    });

    /**
     * Y las instrucciones van **en español**: las que trae la librería están
     * en inglés, y quien las oye está leyendo una pantalla escrita en español.
     */
    it('explica en español cómo se reordena con el teclado', () => {
        renderList(['Taladro']);

        expect(screen.getByText(/pulsa la barra espaciadora/i)).toBeInTheDocument();
        expect(screen.getByText(/Escape/)).toBeInTheDocument();
    });
});
