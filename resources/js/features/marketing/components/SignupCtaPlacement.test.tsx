import { SiteHeader } from '@/features/marketing/components/SiteHeader';
import Login from '@/pages/backoffice/auth/Login';
import { render } from '@testing-library/react';
import { type ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', async () => ({
    usePage: () => ({ props: {} }),
    useForm: () => ({
        data: { email: '', password: '', remember_email: false },
        setData: vi.fn(),
        post: vi.fn(),
        processing: false,
        errors: {},
        reset: vi.fn(),
    }),
    Head: () => null,
    Link: (await import('@/test/fakes')).FakeLink,
}));

vi.mock('@/layouts/backoffice/access-layout', () => ({
    default: ({ footer, children }: { footer?: ReactNode; children: ReactNode }) => (
        <main>
            {children}
            {footer}
        </main>
    ),
}));

beforeEach(() => {
    vi.stubGlobal('route', (name: string) => `/${name}`);
});

function signupLinks(): HTMLAnchorElement[] {
    return Array.from(document.querySelectorAll<HTMLAnchorElement>('a[href="/register"]'));
}

it('la cabecera del sitio invita a registrarse con el mismo botón azul que el resto, también en el celular', () => {
    render(<SiteHeader />);

    const [cta] = signupLinks();

    expect(cta).toHaveTextContent(/^Empezar gratis$/);
    expect(cta).toHaveClass('pz-btn', 'pz-btn-blue');
    expect(cta).not.toHaveClass('hidden');
});

it('el acceso al negocio invita a registrarse con «Empezar gratis» en el sitio principal', () => {
    window.apartarSurfaces = { backoffice: '', platform: '', site: 'https://apartar.app' };
    render(<Login />);

    const links = Array.from(document.querySelectorAll<HTMLAnchorElement>('a[href="https://apartar.app/registro"]'));

    expect(links).toHaveLength(1);
    expect(links[0]).toHaveTextContent(/^Empezar gratis$/);
    expect(document.querySelector('a[href="https://apartar.app/mi-cuenta/acceso"]')).not.toBeNull();
    delete window.apartarSurfaces;
});

it('«Entrar» en la cabecera del sitio mide 44px en el celular', () => {
    render(<SiteHeader />);

    const login = Array.from(document.querySelectorAll<HTMLAnchorElement>('a[href="/login"]'));

    expect(login).toHaveLength(1);
    expect(login[0]).toHaveClass('touch:min-h-11');
});

it('«Entra a Mi cuenta» del acceso al negocio es un objetivo de 44px', () => {
    render(<Login />);

    expect(document.querySelector('a[href="/mi-cuenta/acceso"]')).toHaveClass('touch:min-h-11', 'inline-flex');
});
