import { RateField } from '@/features/receipts/components/RateField';
import { type RateOfDayState } from '@/features/receipts/hooks/useRateOfDay';
import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { expect, it, vi } from 'vitest';

const FOUND: RateOfDayState = { rate: { rate: '158.50000000', sourceLabel: 'BCV', date: '2026-09-17' }, loading: false, known: true };

function Harness({ ofDay, onRate }: { ofDay: RateOfDayState; onRate: (rate: string) => void }) {
    const [value, setValue] = useState('');
    const [manual, setManual] = useState(false);

    return (
        <RateField
            value={value}
            onChange={(rate) => {
                setValue(rate);
                onRate(rate);
            }}
            manual={manual}
            onManualChange={setManual}
            day="2026-09-17"
            today="2026-11-01"
            ofDay={ofDay}
        />
    );
}

it('pone sola la tasa del día del pago, bloqueada, y no la manda como manual', () => {
    const onRate = vi.fn();
    render(<Harness ofDay={FOUND} onRate={onRate} />);

    const input = screen.getByLabelText('Tasa (Bs por dólar)');
    expect(input).toHaveValue('158,50');
    expect(input).toBeDisabled();
    expect(screen.getByText(/Tasa BCV del 17\/09\/2026, la que se registra/)).toBeInTheDocument();
    expect(onRate).not.toHaveBeenCalled();
});

it('«Escribir otra» la desbloquea como manual y «Usar la del día» la devuelve', () => {
    const onRate = vi.fn();
    render(<Harness ofDay={FOUND} onRate={onRate} />);

    fireEvent.click(screen.getByRole('button', { name: 'Escribir otra' }));
    const input = screen.getByLabelText('Tasa (Bs por dólar)');
    expect(input).toBeEnabled();
    expect(onRate).toHaveBeenLastCalledWith('158,50');
    fireEvent.change(input, { target: { value: '160,1' } });
    expect(onRate).toHaveBeenLastCalledWith('160,1');
    expect(screen.getByText(/Queda registrada como tasa manual/)).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Usar la del 17/09/2026' }));
    expect(onRate).toHaveBeenLastCalledWith('');
    expect(screen.getByLabelText('Tasa (Bs por dólar)')).toBeDisabled();
});

it('sin tasa conocida para ese día pide escribirla', () => {
    render(<Harness ofDay={{ rate: null, loading: false, known: true }} onRate={vi.fn()} />);

    expect(screen.getByLabelText('Tasa (Bs por dólar)')).toBeEnabled();
    expect(screen.getByText('No hay tasa conocida para el 17/09/2026: escribe la que usaste.')).toBeInTheDocument();
});
