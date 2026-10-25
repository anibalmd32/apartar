import { ReceiptFilePreview } from '@/features/receipts/components/ReceiptFilePreview';
import { formatFileSize } from '@/features/receipts/hooks/useFilePreview';
import { fakeFile, stubObjectUrls } from '@/test/receipt-fixtures';
import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';

const { createObjectURL, revokeObjectURL } = stubObjectUrls();

it('sin archivo no pinta nada', () => {
    const { container } = render(<ReceiptFilePreview file={null} />);

    expect(container).toBeEmptyDOMElement();
});

it('enseña la captura en miniatura, con su nombre y su peso', () => {
    render(<ReceiptFilePreview file={fakeFile('captura.png', 'image/png', 350 * 1024)} />);

    expect(screen.getByRole('img', { name: 'captura.png' })).toHaveAttribute('src', 'blob:captura-1');
    expect(screen.getByText('350 KB')).toBeInTheDocument();
});

it('de un PDF dice el nombre y el peso, sin crear una URL', () => {
    render(<ReceiptFilePreview file={fakeFile('transferencia.pdf', 'application/pdf', 1.5 * 1024 * 1024)} />);

    expect(screen.queryByRole('img')).toBeNull();
    expect(screen.getByText('transferencia.pdf')).toBeInTheDocument();
    expect(screen.getByText('1,5 MB')).toBeInTheDocument();
    expect(createObjectURL).not.toHaveBeenCalled();
});

it('revoca la URL anterior al cambiar de archivo y la última al desmontar', () => {
    const { rerender, unmount } = render(<ReceiptFilePreview file={fakeFile('primera.png', 'image/png')} />);
    expect(screen.getByRole('img', { name: 'primera.png' })).toHaveAttribute('src', 'blob:captura-1');

    rerender(<ReceiptFilePreview file={fakeFile('segunda.jpg', 'image/jpeg')} />);
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:captura-1');
    expect(screen.getByRole('img', { name: 'segunda.jpg' })).toHaveAttribute('src', 'blob:captura-2');

    rerender(<ReceiptFilePreview file={fakeFile('tercera.pdf', 'application/pdf')} />);
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:captura-2');
    expect(screen.queryByRole('img')).toBeNull();

    rerender(<ReceiptFilePreview file={fakeFile('cuarta.webp', 'image/webp')} />);
    unmount();
    expect(revokeObjectURL).toHaveBeenLastCalledWith('blob:captura-3');
    expect(revokeObjectURL).toHaveBeenCalledTimes(3);
});

it('formatea el peso en KB o en MB con coma decimal', () => {
    expect(formatFileSize(200)).toBe('1 KB');
    expect(formatFileSize(512 * 1024)).toBe('512 KB');
    expect(formatFileSize(1_048_100)).toBe('1 MB');
    expect(formatFileSize(5 * 1024 * 1024)).toBe('5 MB');
});

it('reconoce una captura sin tipo por su extensión, y no llama PDF a lo que no lo es', () => {
    const { rerender } = render(<ReceiptFilePreview file={fakeFile('captura.webp', '')} />);
    expect(screen.getByRole('img', { name: 'captura.webp' })).toBeInTheDocument();

    rerender(<ReceiptFilePreview file={fakeFile('datos.bin', 'application/octet-stream')} />);
    expect(screen.queryByRole('img')).toBeNull();
    expect(screen.getByText('Archivo')).toBeInTheDocument();
    expect(screen.queryByText('PDF')).toBeNull();
});
