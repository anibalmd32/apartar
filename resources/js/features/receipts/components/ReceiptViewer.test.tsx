import { ReceiptViewer } from '@/features/receipts/components/ReceiptViewer';
import { type ReceiptReviewProps } from '@/types/receipt';
import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';

const URL = '/backoffice/comprobantes/9/archivo?signature=x';

function viewer(kind: 'image' | 'pdf') {
    const receipt = { file: { url: URL, kind } } as unknown as ReceiptReviewProps['receipt'];

    return render(<ReceiptViewer receipt={receipt} />);
}

it('acerca, aleja y gira la captura', () => {
    viewer('image');
    const image = screen.getByRole('img', { name: 'Captura del comprobante' });

    fireEvent.click(screen.getByRole('button', { name: 'Acercar' }));
    expect(image.style.transform).toBe('scale(1.25) rotate(0deg)');
    expect(screen.getByText('125 %')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Girar' }));
    expect(image.style.transform).toBe('scale(1.25) rotate(90deg)');

    fireEvent.click(screen.getByRole('button', { name: 'Alejar' }));
    fireEvent.click(screen.getByRole('button', { name: 'Alejar' }));
    fireEvent.click(screen.getByRole('button', { name: 'Alejar' }));
    expect(image.style.transform).toBe('scale(0.5) rotate(90deg)');
    expect(screen.getByRole('button', { name: 'Alejar' })).toBeDisabled();
});

it('descarga y abre el original', () => {
    viewer('image');

    expect(screen.getByRole('link', { name: 'Descargar' })).toHaveAttribute('href', URL);
    expect(screen.getByRole('link', { name: 'Descargar' })).toHaveAttribute('download');
    expect(screen.getByRole('link', { name: 'Abrir el archivo completo' })).toHaveAttribute('target', '_blank');
});

it('con el PDF deja el zoom y el giro al visor del navegador', () => {
    viewer('pdf');

    expect(screen.queryByRole('button', { name: 'Acercar' })).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Descargar' })).toBeInTheDocument();
});
