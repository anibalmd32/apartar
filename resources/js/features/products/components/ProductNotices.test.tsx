import { ProductNotices } from '@/features/products/components/ProductNotices';
import { type ProductFormValues } from '@/types/product';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

const { posts } = vi.hoisted(() => ({ posts: { calls: [] as string[] } }));

vi.mock('@inertiajs/react', () => ({
    useForm: () => ({ errors: {}, processing: false, post: (url: string) => posts.calls.push(url) }),
}));

beforeEach(() => {
    posts.calls = [];
});

function product(photoReview: ProductFormValues['photoReview']): ProductFormValues {
    return { id: 4, status: 'draft', photoReview } as ProductFormValues;
}

it('mientras Apartar revisa las fotos lo dice y explica que no se puede publicar', () => {
    render(<ProductNotices product={product({ status: 'pending', subject: 'un animal', rejectionReason: null })} reviewRequested={false} />);

    expect(screen.getByRole('alert')).toHaveTextContent('Las fotos parecen mostrar un animal.');
    expect(screen.getByRole('alert')).toHaveTextContent('podrás publicarlo cuando las apruebe');
});

it('un producto nuevo con la revisión pedida avisa antes de crearse', () => {
    render(<ProductNotices product={null} reviewRequested />);

    expect(screen.getByRole('alert')).toHaveTextContent('Fotos en revisión');
});

it('tras un rechazo muestra el motivo y deja pedir otra revisión', () => {
    render(
        <ProductNotices
            product={product({ status: 'rejected', subject: 'un animal', rejectionReason: 'No vendemos animales.' })}
            reviewRequested={false}
        />,
    );

    expect(screen.getByRole('alert')).toHaveTextContent('No vendemos animales.');

    fireEvent.click(screen.getByRole('button', { name: 'Pedir otra revisión' }));

    expect(posts.calls).toEqual(['/backoffice/productos/4/revision-fotos']);
});

it('con las fotos aprobadas no muestra ningún aviso', () => {
    render(<ProductNotices product={product({ status: 'approved', subject: 'un animal', rejectionReason: null })} reviewRequested={false} />);

    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});
