import { ProductImageGallery } from '@/features/products/components/ProductImageGallery';
import { useGalleryDraft } from '@/features/products/hooks/useGalleryDraft';
import { type ProductImageView } from '@/types/product';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({ router: { post: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn() } }));

const photo = (id: number, alt: string): ProductImageView => ({
    id,
    set: { thumb: `/thumb-${id}.webp`, card: `/card-${id}.webp`, zoom: `/zoom-${id}.webp`, alt },
    isProcessing: false,
    needsReview: false,
});

const IMAGES = [photo(1, 'Frente'), photo(2, 'Lado'), photo(3, 'Espalda')];

function Harness() {
    const gallery = useGalleryDraft(IMAGES);

    return (
        <>
            <ProductImageGallery productId={7} images={IMAGES} maxImages={10} errors={{}} gallery={gallery} />
            <output data-testid="dirty">{String(gallery.dirty)}</output>
            <output data-testid="order">{gallery.order.map((entry) => entry.id).join(',')}</output>
        </>
    );
}

const tiles = () => within(screen.getByRole('list', { name: 'Fotos del producto' })).getAllByRole('listitem');

const choose = (number: number) => fireEvent.click(screen.getByRole('button', { name: `Elegir la imagen ${number}` }));

it('la galería ya no tiene un botón aparte para guardarla', () => {
    render(<Harness />);

    expect(screen.queryByRole('button', { name: 'Guardar galería' })).not.toBeInTheDocument();
});

it('«Hacer principal» pone la foto de primera y cuenta como cambio sin guardar', () => {
    render(<Harness />);

    expect(screen.getByTestId('dirty')).toHaveTextContent('false');

    choose(3);
    fireEvent.click(screen.getByRole('button', { name: 'Hacer principal' }));

    expect(screen.getByTestId('order')).toHaveTextContent('3,1,2');
    expect(screen.getByTestId('dirty')).toHaveTextContent('true');
    expect(within(tiles()[0]!).getByText('Principal')).toBeInTheDocument();
    expect(within(tiles()[0]!).getByRole('img')).toHaveAttribute('alt', 'Espalda');
    expect(screen.queryByRole('button', { name: 'Hacer principal' })).not.toBeInTheDocument();
    expect(screen.getByText('Sin guardar')).toBeInTheDocument();
});

it('las flechas mueven la foto elegida un puesto y se desactivan en los extremos', () => {
    render(<Harness />);

    expect(screen.getByRole('button', { name: 'Mover antes la imagen 1' })).toBeDisabled();

    fireEvent.click(screen.getByRole('button', { name: 'Mover después la imagen 1' }));
    fireEvent.click(screen.getByRole('button', { name: 'Mover después la imagen 2' }));

    expect(screen.getByTestId('order')).toHaveTextContent('2,3,1');
    expect(screen.getByRole('button', { name: 'Mover después la imagen 3' })).toBeDisabled();
});

it('las fotos se muestran como miniaturas en cuadrícula de cuatro en el celular', () => {
    render(<Harness />);

    expect(screen.getByRole('list', { name: 'Fotos del producto' })).toHaveClass('grid', 'grid-cols-4');
});

it('los botones para ordenar miden 44 px en el celular', () => {
    render(<Harness />);

    expect(screen.getByRole('button', { name: 'Mover después la imagen 1' })).toHaveClass('touch:min-h-11', 'touch:min-w-11');
    expect(screen.getByRole('button', { name: 'Quitar la imagen 1' })).toHaveClass('touch:min-h-11', 'touch:min-w-11');
});

it('cambiar el texto alternativo también cuenta como cambio sin guardar', () => {
    render(<Harness />);

    choose(2);
    fireEvent.change(screen.getByLabelText('Texto alternativo'), { target: { value: 'Vista lateral' } });

    expect(screen.getByTestId('dirty')).toHaveTextContent('true');
});
