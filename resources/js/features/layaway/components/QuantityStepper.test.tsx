import { CopyButton } from '@/features/catalog/components/CopyButton';
import { QuantityStepper } from '@/features/layaway/components/QuantityStepper';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';

afterEach(() => vi.unstubAllGlobals());

it('suma y resta de una en una, con el campo «Cantidad» de siempre en medio', () => {
    const onChange = vi.fn();
    const { rerender } = render(<QuantityStepper value={1} max={3} onChange={onChange} hint="Hasta 3 unidades." />);

    expect(screen.getByLabelText('Cantidad')).toHaveValue(1);
    expect(screen.getByLabelText('Cantidad')).toHaveAccessibleDescription('Hasta 3 unidades.');
    expect(screen.getByRole('button', { name: 'Quitar una unidad' })).toBeDisabled();

    fireEvent.click(screen.getByRole('button', { name: 'Añadir una unidad' }));
    expect(onChange).toHaveBeenLastCalledWith(2);

    rerender(<QuantityStepper value={3} max={3} onChange={onChange} hint="Hasta 3 unidades." />);
    expect(screen.getByRole('button', { name: 'Añadir una unidad' })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Quitar una unidad' }));
    expect(onChange).toHaveBeenLastCalledWith(2);
});

it('copia lo que se le da y lo confirma', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    vi.stubGlobal('navigator', { clipboard: { writeText } });
    render(<CopyButton value="AP-7K3MQ2XH" label="el código" />);

    fireEvent.click(screen.getByRole('button', { name: 'Copiar el código' }));

    await waitFor(() => expect(screen.getByRole('button', { name: 'Copiado' })).toBeInTheDocument());
    expect(writeText).toHaveBeenCalledWith('AP-7K3MQ2XH');
});
