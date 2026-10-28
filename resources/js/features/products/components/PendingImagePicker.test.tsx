import { PendingImagePicker } from '@/features/products/components/PendingImagePicker';
import { usePendingImages } from '@/features/products/hooks/usePendingImages';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

function Harness({ max = 10, errors = {} }: { max?: number; errors?: Partial<Record<string, string>> }) {
    const photos = usePendingImages(max);

    return (
        <>
            <PendingImagePicker photos={photos} errors={errors} />
            <output data-testid="files">{photos.files.map((file) => file.name).join(',')}</output>
        </>
    );
}

function image(name: string, type = 'image/png', size = 10): File {
    const file = new File(['x'], name, { type });
    Object.defineProperty(file, 'size', { value: size });

    return file;
}

function pick(...files: File[]) {
    fireEvent.change(screen.getByLabelText('Elegir fotos'), { target: { files } });
}

let created = 0;

beforeEach(() => {
    created = 0;
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: vi.fn(() => `blob:foto-${++created}`), revokeObjectURL: vi.fn() }));
});

it('agrega las fotos elegidas con su vista previa y la primera es la principal', () => {
    render(<Harness />);

    pick(image('frente.png'), image('lado.jpg', 'image/jpeg'));

    expect(screen.getByAltText('Foto 1')).toHaveAttribute('src', 'blob:foto-1');
    expect(screen.getByAltText('Foto 2')).toHaveAttribute('src', 'blob:foto-2');
    expect(screen.getByText('Principal')).toBeInTheDocument();
    expect(screen.getByTestId('files')).toHaveTextContent('frente.png,lado.jpg');
    expect(screen.getByText(/Puedes agregar 8 fotos más/)).toBeInTheDocument();
});

it('quita una foto y libera su vista previa', () => {
    render(<Harness />);

    pick(image('frente.png'), image('lado.png'));
    fireEvent.click(screen.getByRole('button', { name: 'Quitar la foto 1' }));

    expect(screen.getByTestId('files')).toHaveTextContent('lado.png');
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:foto-1');
});

it('rechaza lo que no es imagen, lo que pesa más de 8 MB y lo que no cabe', () => {
    render(<Harness max={2} />);

    pick(image('nota.pdf', 'application/pdf'), image('enorme.png', 'image/png', 9 * 1024 * 1024), image('a.png'), image('b.png'), image('c.png'));

    expect(screen.getByRole('alert')).toHaveTextContent(
        'Solo se admiten fotos JPG, PNG o WebP. Cada imagen puede pesar hasta 8 MB. Un producto admite 2 imágenes.',
    );
    expect(screen.getByTestId('files')).toHaveTextContent('a.png,b.png');
    expect(screen.getByLabelText('Elegir fotos')).toBeDisabled();
});

it('muestra el error del servidor sobre una foto concreta', () => {
    render(<Harness errors={{ 'images.0': 'El archivo no es una imagen válida.' }} />);

    expect(screen.getByRole('alert')).toHaveTextContent('El archivo no es una imagen válida.');
});
