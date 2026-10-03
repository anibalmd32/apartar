import LoginLink from '@/pages/portal/LoginLink';
import LoginLinkUnusable from '@/pages/portal/LoginLinkUnusable';
import { fakeRoute } from '@/test/fakes';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

/**
 * El enlace por correo: confirmar (`REQ-IDN-003` RB-9) y el que ya no sirve
 * (SP-1, SP-2).
 */

const { useForm, post } = vi.hoisted(() => ({ useForm: vi.fn(), post: vi.fn() }));

vi.mock('@inertiajs/react', async () => ({ useForm, Head: () => null, Link: (await import('@/test/fakes')).FakeLink }));

beforeEach(() => {
    vi.stubGlobal('route', fakeRoute);
    post.mockReset();
    useForm.mockReturnValue({ post, processing: false });
});

/** RB-9 · abrir el enlace no entra: entra el botón, por `POST`. */
it('no canjea al abrirse: canjea al pulsar', () => {
    render(<LoginLink token="7.abc123" />);

    expect(post).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('button', { name: 'Entrar' }));

    expect(post).toHaveBeenCalledWith('/mi-cuenta/acceso/enlace/7.abc123');
});

/** SP-1 · el que no sirve ofrece volver a entrar. */
it('el enlace que no sirve dice por qué y ofrece volver', () => {
    render(<LoginLinkUnusable message="Este enlace ya no sirve: caducó, ya se usó o no es correcto." />);

    expect(screen.getByRole('alert')).toHaveTextContent('Este enlace ya no sirve');
    expect(screen.getByRole('link', { name: 'Volver a entrar' })).toHaveAttribute('href', '/mi-cuenta/acceso');
});
