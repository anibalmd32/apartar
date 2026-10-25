import { FrequencyField } from '@/features/products/components/FrequencyField';
import { InventorySortSelect } from '@/features/products/components/InventorySortSelect';
import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

it('la frecuencia habitual se elige en el desplegable, sin campo de días', () => {
    const onChange = vi.fn();
    render(<FrequencyField value={15} onChange={onChange} />);

    expect(screen.getByLabelText('Frecuencia')).toHaveValue('15');
    expect(screen.queryByLabelText(/Días entre cuotas/)).not.toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Frecuencia'), { target: { value: '30' } });
    expect(onChange).toHaveBeenCalledWith(30);
});

it('«Personalizada» deja el campo de días, y una cifra guardada fuera de los atajos abre ahí', () => {
    const onChange = vi.fn();
    const { rerender } = render(<FrequencyField value={15} onChange={onChange} />);

    fireEvent.change(screen.getByLabelText('Frecuencia'), { target: { value: 'custom' } });
    expect(screen.getByLabelText(/Días entre cuotas/)).toHaveValue(15);
    expect(onChange).not.toHaveBeenCalled();

    rerender(<FrequencyField value={10} onChange={onChange} error="Demasiado" />);
    expect(screen.getByLabelText('Frecuencia')).toHaveValue('custom');
    expect(screen.getByRole('alert')).toHaveTextContent('Demasiado');
});

it('«Ordenar» manda el orden de la lista blanca, y «Recientes» vuelve al defecto', () => {
    const onChange = vi.fn();
    const { rerender } = render(<InventorySortSelect sort={null} onChange={onChange} />);

    expect(screen.getByLabelText('Ordenar')).toHaveDisplayValue('Ordenar: Recientes');

    fireEvent.change(screen.getByLabelText('Ordenar'), { target: { value: '-price' } });
    expect(onChange).toHaveBeenCalledWith('-price');

    rerender(<InventorySortSelect sort="-created" onChange={onChange} />);
    expect(screen.getByLabelText('Ordenar')).toHaveDisplayValue('Ordenar: Recientes');

    rerender(<InventorySortSelect sort="name" onChange={onChange} />);
    fireEvent.change(screen.getByLabelText('Ordenar'), { target: { value: '' } });
    expect(onChange).toHaveBeenLastCalledWith(null);
});
