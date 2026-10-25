import { ReceiptDropzone } from '@/features/subscription/components/ReceiptDropzone';
import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

const props = { label: 'Comprobante', accept: 'image/*,application/pdf', hint: 'Foto o PDF, hasta 5 MB.' };

it('el campo de archivo conserva su etiqueta y su pista', () => {
    const onFile = vi.fn();
    render(<ReceiptDropzone {...props} file={null} onFile={onFile} />);

    const input = screen.getByLabelText('Comprobante');
    expect(input).toHaveAttribute('type', 'file');
    expect(input).toHaveAccessibleDescription('Foto o PDF, hasta 5 MB.');

    const file = new File(['x'], 'pago.png', { type: 'image/png' });
    fireEvent.change(input, { target: { files: [file] } });
    expect(onFile).toHaveBeenCalledWith(file);
});

it('soltar un archivo encima lo elige, y se ve su nombre', () => {
    const onFile = vi.fn();
    const file = new File(['x'], 'pago.pdf', { type: 'application/pdf' });
    const { rerender } = render(<ReceiptDropzone {...props} file={null} onFile={onFile} />);

    fireEvent.drop(screen.getByTestId('receipt-dropzone'), { dataTransfer: { files: [file] } });
    expect(onFile).toHaveBeenCalledWith(file);

    rerender(<ReceiptDropzone {...props} file={file} onFile={onFile} error="El archivo es demasiado grande." />);
    expect(screen.getByText('pago.pdf')).toBeInTheDocument();
    expect(screen.getByRole('alert')).toHaveTextContent('El archivo es demasiado grande.');
});
