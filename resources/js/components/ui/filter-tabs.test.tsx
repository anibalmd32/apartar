import { FilterTabs } from '@/components/ui/filter-tabs';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';

const TABS = [
    { value: null, label: 'Todos', count: 1 },
    { value: 'published', label: 'Publicados', count: 1 },
] as const;

function layout(sizes: { scrollWidth: number; clientWidth: number; offsetLeft: (element: HTMLElement) => number; offsetWidth: number }) {
    vi.spyOn(HTMLElement.prototype, 'scrollWidth', 'get').mockImplementation(() => sizes.scrollWidth);
    vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockImplementation(() => sizes.clientWidth);
    vi.spyOn(HTMLElement.prototype, 'offsetWidth', 'get').mockImplementation(() => sizes.offsetWidth);
    vi.spyOn(HTMLElement.prototype, 'offsetLeft', 'get').mockImplementation(function (this: HTMLElement) {
        return sizes.offsetLeft(this);
    });
}

afterEach(() => {
    vi.restoreAllMocks();
});

it('desliza en horizontal y desvanece el borde cuando quedan pestañas por ver', () => {
    layout({ scrollWidth: 600, clientWidth: 300, offsetLeft: () => 0, offsetWidth: 80 });
    render(<FilterTabs label="Estado" tabs={TABS} value={null} onChange={() => undefined} />);

    const group = screen.getByRole('group', { name: 'Estado' });

    expect(group).toHaveClass('scroll-row');
    expect(group).toHaveAttribute('data-fade-end');
    expect(group).not.toHaveAttribute('data-fade-start');
});

it('si la pestaña activa queda fuera de la vista, desliza la fila hasta mostrarla', () => {
    layout({
        scrollWidth: 600,
        clientWidth: 300,
        offsetLeft: (element) => (element.textContent?.startsWith('Publicados') ? 500 : 0),
        offsetWidth: 80,
    });
    render(<FilterTabs label="Estado" tabs={TABS} value="published" onChange={() => undefined} />);

    expect(screen.getByRole('group', { name: 'Estado' }).scrollLeft).toBe(390);
});

it('marca la pestaña activa y avisa del cambio', () => {
    const onChange = vi.fn();
    render(<FilterTabs label="Estado" tabs={TABS} value={null} onChange={onChange} />);

    expect(screen.getByRole('button', { name: /Todos/ })).toHaveAttribute('aria-pressed', 'true');

    fireEvent.click(screen.getByRole('button', { name: /Publicados/ }));

    expect(onChange).toHaveBeenCalledWith('published');
});

it('cada pestaña mide 44 px de alto en el celular', () => {
    render(<FilterTabs label="Estado" tabs={TABS} value={null} onChange={() => undefined} />);

    expect(screen.getByRole('button', { name: /Publicados/ })).toHaveClass('touch:min-h-11');
});
