import { SiteHeader } from '@/features/marketing/components/SiteHeader';
import Login from '@/pages/backoffice/auth/Login';
import { render } from '@testing-library/react';
import { type ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', async () => ({
    usePage: () => ({ props: {} }),
    useForm: () => ({
        data: { email: '', password: '', remember: false },
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

it('la cabecera del sitio invita a registrarse con la misma píldora de 48px que el resto', () => {
    render(<SiteHeader />);

    const [cta] = signupLinks();

    expect(cta).toHaveTextContent(/^Empezar gratis$/);
    expect(cta).toHaveClass('h-12', 'rounded-full', 'lg:inline-flex');
    expect(cta).not.toHaveClass('h-10');
});

it('el acceso al negocio invita a registrarse con «Empezar gratis»', () => {
    render(<Login />);

    const links = signupLinks();

    expect(links).toHaveLength(1);
    expect(links[0]).toHaveTextContent(/^Empezar gratis$/);
});
