import Claim from '@/pages/portal/Claim';
import { fakeRoute } from '@/test/fakes';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

const { useForm, post, setData } = vi.hoisted(() => ({ useForm: vi.fn(), post: vi.fn(), setData: vi.fn() }));

vi.mock('@inertiajs/react', async () => ({
    useForm,
    usePage: () => ({ props: {} }),
    Head: () => null,
    Link: (await import('@/test/fakes')).FakeLink,
}));

beforeEach(() => {
    vi.stubGlobal('route', fakeRoute);
    post.mockReset();
    useForm.mockReturnValue({ data: { document: '', code: '' }, setData, post, processing: false, errors: {} });
});

it('pide cédula y código y envía al reclamo', () => {
    render(<Claim storeUrl="/mi-cuenta/reclamar" />);

    expect(screen.getByLabelText('Cédula o RIF')).toBeInTheDocument();
    expect(screen.getByLabelText('Código de apartado')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Recuperar' }));

    expect(post).toHaveBeenCalledWith('/mi-cuenta/reclamar');
});

it('enseña el error genérico junto al código', () => {
    useForm.mockReturnValue({
        data: { document: '', code: '' },
        setData,
        post,
        processing: false,
        errors: { code: 'No encontramos un apartado con esa cédula y ese código.' },
    });

    render(<Claim storeUrl="/mi-cuenta/reclamar" />);

    expect(screen.getByText('No encontramos un apartado con esa cédula y ese código.')).toBeInTheDocument();
});
