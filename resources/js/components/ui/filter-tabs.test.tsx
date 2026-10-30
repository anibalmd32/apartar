import { FilterTabs } from '@/components/ui/filter-tabs';
import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

const TABS = [
    { value: null, label: 'Todos', count: 1 },
    { value: 'published', label: 'Publicados', count: 1 },
] as const;

it('desliza en horizontal sin barra ni scroll vertical', () => {
    render(<FilterTabs label="Estado" tabs={TABS} value={null} onChange={() => undefined} />);

    const group = screen.getByRole('group', { name: 'Estado' });

    expect(group).toHaveClass('overflow-x-auto', 'overflow-y-hidden', '[scrollbar-width:none]');
});

it('marca la pestaña activa y avisa del cambio', () => {
    const onChange = vi.fn();
    render(<FilterTabs label="Estado" tabs={TABS} value={null} onChange={onChange} />);

    expect(screen.getByRole('button', { name: /Todos/ })).toHaveAttribute('aria-pressed', 'true');

    fireEvent.click(screen.getByRole('button', { name: /Publicados/ }));

    expect(onChange).toHaveBeenCalledWith('published');
});
