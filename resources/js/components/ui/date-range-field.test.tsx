import { DateRangeField } from '@/components/ui/date-range-field';
import { render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

function field(from = '', to = '') {
    render(<DateRangeField label="Fechas" fromLabel="Desde" from={from} to={to} onFromChange={vi.fn()} onToChange={vi.fn()} />);
}

it('toda la caja abre el calendario: la etiqueta y el icono están dentro del botón', () => {
    field();

    const from = screen.getByRole('button', { name: 'Desde: sin fecha' });

    expect(from).toHaveTextContent('Desde');
    expect(from).toHaveClass('input');
    expect(from.querySelector('svg')).not.toBeNull();
});

it('«Hasta» se escribe igual que «Desde», con mayúscula e icono', () => {
    field();

    const to = screen.getByRole('button', { name: 'Hasta: sin fecha' });

    expect(to).toHaveTextContent(/^Hasta/);
    expect(to.querySelector('svg')).not.toBeNull();
});

it('en reposo es una caja neutra y con fecha solo marca el borde', () => {
    field('2026-10-12');

    expect(screen.getByRole('button', { name: 'Desde: 12/10/2026' })).toHaveClass('border-primary');
    expect(screen.getByRole('button', { name: 'Hasta: sin fecha' })).not.toHaveClass('border-primary');
    expect(screen.getByRole('button', { name: 'Hasta: sin fecha' })).not.toHaveClass('bg-primary-soft');
});
