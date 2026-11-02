import { NumberField } from '@/components/ui/number-field';
import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { expect, it, vi } from 'vitest';

interface HarnessProps {
    initial: number | null;
    onValue: (value: number | null) => void;
    min?: number;
    max?: number;
}

function Harness({ initial, onValue, min, max }: HarnessProps) {
    const [value, setValue] = useState(initial);

    return (
        <NumberField
            label="Número de cuotas"
            value={value}
            min={min}
            max={max}
            onChange={(next) => {
                setValue(next);
                onValue(next);
            }}
            required
        />
    );
}

function typeKey(input: HTMLElement, key: string) {
    fireEvent.change(input, { target: { value: (input as HTMLInputElement).value + key } });
}

it('se puede vaciar y lo que se escribe después es el número tecleado, no «0» delante', () => {
    const onValue = vi.fn();
    render(<Harness initial={0} onValue={onValue} />);
    const input = screen.getByLabelText('Número de cuotas');

    fireEvent.change(input, { target: { value: '' } });
    expect(input).toHaveValue('');
    expect(onValue).toHaveBeenLastCalledWith(null);

    typeKey(input, '5');
    expect(input).toHaveValue('5');
    expect(onValue).toHaveBeenLastCalledWith(5);
});

it('escribir detrás de un cero no deja el cero a la izquierda', () => {
    const onValue = vi.fn();
    render(<Harness initial={0} onValue={onValue} />);
    const input = screen.getByLabelText('Número de cuotas');

    typeKey(input, '6');

    expect(input).toHaveValue('6');
    expect(onValue).toHaveBeenLastCalledWith(6);
});

it('al salir del campo ajusta el número a los límites', () => {
    const onValue = vi.fn();
    render(<Harness initial={4} min={1} max={36} onValue={onValue} />);
    const input = screen.getByLabelText('Número de cuotas');

    fireEvent.change(input, { target: { value: '50' } });
    fireEvent.blur(input);
    expect(input).toHaveValue('36');
    expect(onValue).toHaveBeenLastCalledWith(36);

    fireEvent.change(input, { target: { value: '0' } });
    fireEvent.blur(input);
    expect(input).toHaveValue('1');
    expect(onValue).toHaveBeenLastCalledWith(1);
});

it('vacío sigue vacío al salir, para que la validación lo marque como obligatorio', () => {
    const onValue = vi.fn();
    render(<Harness initial={3} min={1} onValue={onValue} />);
    const input = screen.getByLabelText('Número de cuotas');

    fireEvent.change(input, { target: { value: '' } });
    fireEvent.blur(input);

    expect(input).toHaveValue('');
    expect(onValue).toHaveBeenLastCalledWith(null);
});

it('solo acepta dígitos y abre el teclado numérico', () => {
    const onValue = vi.fn();
    render(<Harness initial={null} onValue={onValue} />);
    const input = screen.getByLabelText('Número de cuotas');

    fireEvent.change(input, { target: { value: '1a2-' } });

    expect(input).toHaveValue('12');
    expect(onValue).toHaveBeenLastCalledWith(12);
    expect(input).toHaveAttribute('inputmode', 'numeric');
    expect(input).toHaveAttribute('pattern', '[0-9]*');
});
