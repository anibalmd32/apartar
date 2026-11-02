import Home from '@/pages/marketing/Home';
import { render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', async () => ({
    usePage: () => ({ props: {} }),
    Head: () => null,
    Link: (await import('@/test/fakes')).FakeLink,
}));

vi.mock('@/layouts/marketing-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));

beforeEach(() => {
    vi.stubGlobal('route', (name: string) => `/${name}`);
});

function signupLinks(): HTMLAnchorElement[] {
    return Array.from(document.querySelectorAll<HTMLAnchorElement>('a[href="/register"]'));
}

it('cada llamada a registrarse dice «Empezar gratis» y tiene la misma forma de 48px en píldora', () => {
    render(<Home />);

    const links = signupLinks();

    expect(links).toHaveLength(2);
    links.forEach((link) => {
        expect(link).toHaveTextContent(/^Empezar gratis$/);
        expect(link).toHaveClass('h-12', 'rounded-full', 'max-sm:w-full');
    });
    expect(screen.getByRole('link', { name: 'Ver planes y precios' })).toHaveClass('h-12', 'rounded-full');
});

it('el bloque final invita una sola vez a empezar, sin repetir «Empieza»', () => {
    render(<Home />);

    const block = screen.getByRole('heading', { name: 'Empieza a vender en cuotas hoy' }).parentElement!;

    expect(block.textContent?.match(/Empieza/g)).toHaveLength(1);
    expect(block.querySelector('a[href="/register"]')).toHaveTextContent('Empezar gratis');
});

it('en el celular las ventajas van una debajo de otra y los botones se separan del párrafo', () => {
    render(<Home />);

    const perks = screen.getByText('Sin tarjeta').closest('ul')!;

    expect(perks).toHaveClass('flex-col', 'lg:flex-row');
    expect(signupLinks()[0]?.parentElement).toHaveClass('mt-2.5');
});
