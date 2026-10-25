import { SortableHandle, SortableList, SortableRow } from '@/components/ui/sortable';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

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
    it('da a cada elemento un tirador enfocable con su propio nombre', () => {
        renderList(['Taladro', 'Sierra']);

        const handle = screen.getByRole('button', { name: 'Reordenar Taladro' });

        expect(handle.tagName).toBe('BUTTON');
        expect(screen.getByRole('button', { name: 'Reordenar Sierra' })).toBeInTheDocument();
    });

    it('explica en español cómo se reordena con el teclado', () => {
        renderList(['Taladro']);

        expect(screen.getByText(/pulsa la barra espaciadora/i)).toBeInTheDocument();
        expect(screen.getByText(/Escape/)).toBeInTheDocument();
    });
});
