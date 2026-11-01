import Document from '@/pages/portal/Document';
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

const props = { mode: 'scoped' as const, customerName: 'Rosa Díaz', businessName: 'Bodega La Fe', typed: '', storeUrl: '/mi-cuenta/cedula' };

beforeEach(() => {
    vi.stubGlobal('route', fakeRoute);
    post.mockReset();
    useForm.mockImplementation((initial: { document: string }) => ({ data: initial, setData, post, processing: false, errors: {} }));
});

it('explica por qué pide la cédula y no deja seguir sin escribirla', () => {
    render(<Document {...props} />);

    expect(screen.getByText(/Bodega La Fe cargó tu apartado sin tu cédula/)).toBeInTheDocument();
    expect(screen.getByLabelText('Cédula o RIF')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Guardar y continuar' })).toBeDisabled();
});

it('con la cuenta de Google explica que la cédula solo sirve para avisar', () => {
    render(<Document {...props} mode="account" businessName="Mi cuenta" />);

    expect(screen.getByText(/te pediremos el código de uno de ellos/)).toBeInTheDocument();
});

it('trae la cédula que escribió al entrar y la envía', () => {
    render(<Document {...props} typed="V20111222" />);

    expect(useForm).toHaveBeenCalledWith({ document: 'V20111222' });
    fireEvent.click(screen.getByRole('button', { name: 'Guardar y continuar' }));

    expect(post).toHaveBeenCalledWith('/mi-cuenta/cedula');
});
