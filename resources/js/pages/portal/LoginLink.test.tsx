import LoginLink from '@/pages/portal/LoginLink';
import LoginLinkUnusable from '@/pages/portal/LoginLinkUnusable';
import { fakeRoute } from '@/test/fakes';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

const { useForm, post } = vi.hoisted(() => ({ useForm: vi.fn(), post: vi.fn() }));

vi.mock('@inertiajs/react', async () => ({ useForm, Head: () => null, Link: (await import('@/test/fakes')).FakeLink }));

beforeEach(() => {
    vi.stubGlobal('route', fakeRoute);
    post.mockReset();
    useForm.mockReturnValue({ data: { remember: true }, setData: vi.fn(), post, processing: false });
});

it('no canjea al abrirse: canjea al pulsar', () => {
    render(<LoginLink token="7.abc123" />);

    expect(post).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('button', { name: 'Entrar a Mi cuenta' }));

    expect(post).toHaveBeenCalledWith('/mi-cuenta/acceso/enlace/7.abc123');
});

it('confirma quién entra y ofrece recordar el dispositivo', () => {
    render(<LoginLink token="7.abc123" preview={{ businessName: 'Boutique Mariela', document: 'V-18.***.567' }} />);

    expect(screen.getByText('Vas a entrar a tus apartados en Boutique Mariela con la cédula V-18.***.567.')).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: 'Recordarme en este dispositivo por 30 días' })).toBeChecked();
});

it('el enlace que no sirve dice por qué y ofrece volver', () => {
    render(<LoginLinkUnusable message="Este enlace ya no sirve: caducó, ya se usó o no es correcto." />);

    expect(screen.getByRole('alert')).toHaveTextContent('Este enlace ya no sirve');
    expect(screen.getByRole('link', { name: 'Volver a entrar' })).toHaveAttribute('href', '/mi-cuenta/acceso');
});
