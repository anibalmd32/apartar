import { ReceiptImage } from '@/features/platform/components/ReceiptImage';
import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

it('reserva el espacio y muestra un esqueleto hasta que la captura carga', () => {
    render(<ReceiptImage src="/plataforma/comprobantes/2/archivo" alt="Comprobante de Tecno Hogar" onError={vi.fn()} />);

    const image = screen.getByRole('img', { name: 'Comprobante de Tecno Hogar' });
    expect(screen.getByRole('status', { name: 'Cargando el comprobante' })).toBeInTheDocument();
    expect(image.parentElement).toHaveClass('aspect-[3/4]');
    expect(image).toHaveClass('opacity-0');

    fireEvent.load(image);

    expect(screen.queryByRole('status', { name: 'Cargando el comprobante' })).not.toBeInTheDocument();
    expect(image).toHaveClass('opacity-100');
});

it('avisa si la captura no se puede mostrar', () => {
    const onError = vi.fn();
    render(<ReceiptImage src="/plataforma/comprobantes/2/archivo" alt="Comprobante de Tecno Hogar" onError={onError} />);

    fireEvent.error(screen.getByRole('img', { name: 'Comprobante de Tecno Hogar' }));

    expect(onError).toHaveBeenCalled();
});
