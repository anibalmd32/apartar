import { CopyButton } from '@/features/catalog/components/CopyButton';
import { QuantityStepper } from '@/features/layaway/components/QuantityStepper';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, expect, it, vi } from 'vitest';

afterEach(() => vi.unstubAllGlobals());

it('suma y resta de una en una, con el campo «Cantidad» de siempre en medio', () => {
    const onChange = vi.fn();
    const { rerender } = render(<QuantityStepper value={1} max={3} onChange={onChange} hint="Hasta 3 unidades." />);

    expect(screen.getByLabelText('Cantidad')).toHaveValue('1');
    expect(screen.getByLabelText('Cantidad')).toHaveAccessibleDescription('Hasta 3 unidades.');
    expect(screen.getByRole('button', { name: 'Quitar una unidad' })).toBeDisabled();

    fireEvent.click(screen.getByRole('button', { name: 'Añadir una unidad' }));
    expect(onChange).toHaveBeenLastCalledWith(2);

    rerender(<QuantityStepper value={3} max={3} onChange={onChange} hint="Hasta 3 unidades." />);
    expect(screen.getByRole('button', { name: 'Añadir una unidad' })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Quitar una unidad' }));
    expect(onChange).toHaveBeenLastCalledWith(2);
});

function Stepper({ max, onQuantity }: { max: number; onQuantity: (quantity: number) => void }) {
    const [quantity, setQuantity] = useState(1);

    const change = (next: number) => {
        const clamped = Number.isFinite(next) ? Math.min(Math.max(Math.trunc(next), 1), max) : 1;

        onQuantity(clamped);
        setQuantity(clamped);
    };

    return <QuantityStepper value={quantity} max={max} onChange={change} hint={`Hasta ${max} unidades.`} />;
}

it('borrar la cantidad y escribir 3 deja 3, no 13', () => {
    const onQuantity = vi.fn();
    render(<Stepper max={10} onQuantity={onQuantity} />);
    const input = screen.getByLabelText('Cantidad') as HTMLInputElement;

    fireEvent.change(input, { target: { value: '' } });
    expect(input.value).toBe('');

    fireEvent.change(input, { target: { value: `${input.value}3` } });
    expect(input.value).toBe('3');
    expect(onQuantity).toHaveBeenLastCalledWith(3);
});

it('la cantidad se puede dejar vacía mientras se escribe y al salir vuelve a la que había', () => {
    const onQuantity = vi.fn();
    render(<Stepper max={5} onQuantity={onQuantity} />);
    const input = screen.getByLabelText('Cantidad') as HTMLInputElement;

    fireEvent.change(input, { target: { value: '' } });
    expect(input.value).toBe('');
    expect(onQuantity).not.toHaveBeenCalled();

    fireEvent.blur(input);
    expect(input.value).toBe('1');
});

it('lo que se escribe fuera del rango muestra la cantidad que de verdad queda', () => {
    const onQuantity = vi.fn();
    render(<Stepper max={5} onQuantity={onQuantity} />);
    const input = screen.getByLabelText('Cantidad') as HTMLInputElement;

    fireEvent.change(input, { target: { value: '0' } });
    expect(input.value).toBe('1');

    fireEvent.change(input, { target: { value: '5' } });
    fireEvent.change(input, { target: { value: '9' } });
    expect(input.value).toBe('5');
    expect(onQuantity).toHaveBeenLastCalledWith(5);
});

it('copia lo que se le da y lo confirma', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    vi.stubGlobal('navigator', { clipboard: { writeText } });
    render(<CopyButton value="AP-7K3MQ2XH" label="el código" />);

    fireEvent.click(screen.getByRole('button', { name: 'Copiar el código' }));

    await waitFor(() => expect(screen.getByRole('button', { name: 'Copiado' })).toBeInTheDocument());
    expect(writeText).toHaveBeenCalledWith('AP-7K3MQ2XH');
});
