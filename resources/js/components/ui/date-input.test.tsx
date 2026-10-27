import { DateInput } from '@/components/ui/date-input';
import { formatIsoDate, parseIsoDate, shiftIsoDate } from '@/lib/iso-date';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date(2026, 9, 27, 10, 0, 0));
});

afterEach(() => {
    vi.useRealTimers();
});

it('valida y formatea fechas ISO sin depender de la zona horaria', () => {
    expect(parseIsoDate('2026-02-30')).toBeNull();
    expect(formatIsoDate('2026-10-05')).toBe('05/10/2026');
    expect(shiftIsoDate('2026-10-31', 1)).toBe('2026-11-01');
});

it('muestra la fecha en formato venezolano y elige un día del calendario', () => {
    const onChange = vi.fn();
    render(<DateInput ariaLabel="Fecha del pago" value="2026-10-05" onChange={onChange} />);

    const trigger = screen.getByRole('button', { name: 'Fecha del pago: 05/10/2026' });
    act(() => fireEvent.click(trigger));

    expect(screen.getByText('Octubre de 2026')).toBeInTheDocument();
    expect(screen.getByRole('gridcell', { name: / 5 de octubre de 2026/ })).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByRole('gridcell', { name: /27 de octubre de 2026/ })).toHaveAttribute('aria-current', 'date');

    act(() => fireEvent.click(screen.getByRole('gridcell', { name: /12 de octubre de 2026/ })));

    expect(onChange).toHaveBeenCalledWith('2026-10-12');
});

it('respeta el mínimo y el máximo, y pasa de mes', () => {
    render(<DateInput ariaLabel="Nueva fecha" value="" min="2026-10-20" max="2026-11-03" onChange={vi.fn()} />);

    act(() => fireEvent.click(screen.getByRole('button', { name: 'Nueva fecha: sin fecha' })));

    expect(screen.getByRole('gridcell', { name: /19 de octubre/ })).toBeDisabled();
    expect(screen.getByRole('gridcell', { name: /20 de octubre/ })).toBeEnabled();

    act(() => fireEvent.click(screen.getByRole('button', { name: 'Mes siguiente' })));

    expect(screen.getByText('Noviembre de 2026')).toBeInTheDocument();
    expect(screen.getByRole('gridcell', { name: / 3 de noviembre/ })).toBeEnabled();
    expect(screen.getByRole('gridcell', { name: / 4 de noviembre/ })).toBeDisabled();
});

it('ofrece Hoy y, si no es obligatoria, Borrar', () => {
    const onChange = vi.fn();
    render(<DateInput ariaLabel="Caduca el" value="2026-11-15" onChange={onChange} />);

    act(() => fireEvent.click(screen.getByRole('button', { name: 'Caduca el: 15/11/2026' })));
    act(() => fireEvent.click(screen.getByRole('button', { name: 'Hoy' })));
    expect(onChange).toHaveBeenLastCalledWith('2026-10-27');

    act(() => fireEvent.click(screen.getByRole('button', { name: 'Caduca el: 15/11/2026' })));
    act(() => fireEvent.click(screen.getByRole('button', { name: 'Borrar' })));
    expect(onChange).toHaveBeenLastCalledWith('');
});

it('se mueve por los días con el teclado', () => {
    const onChange = vi.fn();
    render(<DateInput ariaLabel="Día del pago" value="2026-10-05" onChange={onChange} />);

    act(() => fireEvent.click(screen.getByRole('button', { name: 'Día del pago: 05/10/2026' })));
    const grid = screen.getByRole('grid', { name: 'Calendario' });

    act(() => fireEvent.keyDown(grid, { key: 'ArrowDown' }));
    expect(screen.getByRole('gridcell', { name: /12 de octubre/ })).toHaveAttribute('tabindex', '0');

    act(() => fireEvent.keyDown(grid, { key: 'PageDown' }));
    expect(screen.getByText('Noviembre de 2026')).toBeInTheDocument();
});

it('envía el valor en un campo oculto cuando lleva nombre', () => {
    const { container } = render(<DateInput name="expires_at" value="2026-12-01" onChange={vi.fn()} />);

    expect(container.querySelector('input[type="hidden"][name="expires_at"]')).toHaveValue('2026-12-01');
});
