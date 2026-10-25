import { RateSourceCards } from '@/features/tenancy/components/RateSourceCards';
import { offsetLabel, ReminderOffsetsEditor } from '@/features/tenancy/components/ReminderOffsetsEditor';
import { type RateView } from '@/types/exchange';
import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

const sourceOptions = [
    { value: 'bcv', label: 'BCV' },
    { value: 'parallel', label: 'Paralelo' },
    { value: 'manual', label: 'Manual' },
] as const;

const rateSources: RateView[] = [
    { rate: 36.52, source: 'bcv', sourceLabel: 'BCV', publishedAt: new Date().toISOString(), isStale: false },
    { rate: null, source: 'parallel', sourceLabel: 'Paralelo', publishedAt: null, isStale: false },
];

it('cada fuente es un radio con su nombre, y la cifra como descripción', () => {
    const onChange = vi.fn();
    render(
        <RateSourceCards
            value="bcv"
            onChange={onChange}
            sourceOptions={sourceOptions}
            rateSources={rateSources}
            manualRate="36.4"
            hint="Con esta tasa…"
        />,
    );

    expect(screen.getByRole('group', { name: 'Fuente de la tasa' })).toBeInTheDocument();
    expect(screen.getByRole('radio', { name: 'BCV' })).toBeChecked();
    expect(screen.getByRole('radio', { name: 'BCV' })).toHaveAccessibleDescription(/Bs\. 36,52/);
    expect(screen.getByText('Todavía sin tasa registrada')).toBeInTheDocument();
    expect(screen.getByText('Bs. 36,40')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('radio', { name: 'Manual' }));
    expect(onChange).toHaveBeenCalledWith('manual');
});

it('las píldoras dicen el recordatorio en palabras', () => {
    expect([-3, 0, 1, 7].map(offsetLabel)).toEqual(['3 días antes', 'El día', '1 día después', '7 días después']);
});

it('quitar y agregar recordatorios escribe la lista de enteros, con el signo según antes o después', () => {
    const onChange = vi.fn();
    render(<ReminderOffsetsEditor label="Recordatorios" value={[-3, 0, 1]} onChange={onChange} />);

    fireEvent.click(screen.getByRole('button', { name: 'Quitar el día' }));
    expect(onChange).toHaveBeenLastCalledWith([-3, 1]);

    fireEvent.click(screen.getByRole('button', { name: 'Agregar' }));
    fireEvent.change(screen.getByLabelText('Días'), { target: { value: '5' } });
    fireEvent.click(screen.getByRole('button', { name: 'Añadir' }));
    expect(onChange).toHaveBeenLastCalledWith([-5, -3, 0, 1]);

    fireEvent.click(screen.getByRole('button', { name: 'Agregar' }));
    fireEvent.change(screen.getByLabelText('Cuándo'), { target: { value: 'after' } });
    fireEvent.change(screen.getByLabelText('Días'), { target: { value: '1' } });
    expect(screen.getByRole('button', { name: 'Añadir' })).toBeDisabled();
});
