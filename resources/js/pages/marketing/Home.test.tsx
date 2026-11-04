import Home from '@/pages/marketing/Home';
import { fireEvent, render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', async () => ({
    usePage: () => ({ props: {} }),
    Head: () => null,
    Link: (await import('@/test/fakes')).FakeLink,
}));

vi.mock('@/layouts/marketing-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));

const RATES = [
    { date: '2026-11-04', rate: '889.57540000' },
    { date: '2026-11-03', rate: '887.12000000' },
];

beforeEach(() => {
    vi.stubGlobal('route', (name: string) => `/${name}`);
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-11-04T15:00:00Z'));
});

afterEach(() => {
    vi.useRealTimers();
});

function signupLinks(): HTMLAnchorElement[] {
    return Array.from(document.querySelectorAll<HTMLAnchorElement>('a[href="/register"]'));
}

it('cada llamada a registrarse dice «Empezar gratis» y usa el botón de marcador azul', () => {
    render(<Home rates={RATES} />);

    const links = signupLinks();

    expect(links).toHaveLength(2);
    links.forEach((link) => {
        expect(link).toHaveTextContent(/^Empezar gratis$/);
        expect(link).toHaveClass('pz-btn', 'pz-btn-blue', 'max-sm:w-full');
    });
    expect(screen.getByRole('link', { name: 'Ver planes y precios' })).toHaveClass('pz-btn', 'pz-btn-line');
});

it('el bloque final invita una sola vez a empezar, sin repetir «Empieza»', () => {
    render(<Home rates={RATES} />);

    const block = screen.getByRole('heading', { name: 'Empieza a vender en cuotas hoy' }).parentElement!;

    expect(block.textContent?.match(/Empieza/g)).toHaveLength(1);
    expect(block.querySelector('a[href="/register"]')).toHaveTextContent('Empezar gratis');
});

it('en el celular las ventajas van una debajo de otra y los botones se separan del párrafo', () => {
    render(<Home rates={RATES} />);

    const perks = screen.getByText('Sin tarjeta').closest('ul')!;

    expect(perks).toHaveClass('flex-col', 'sm:flex-row');
    expect(signupLinks()[0]?.parentElement).toHaveClass('mt-8');
});

it('la pizarra escribe el día de hoy y la tasa BCV real que manda el servidor', () => {
    render(<Home rates={RATES} />);

    expect(screen.getByText('Hoy, miércoles 4 de noviembre')).toBeInTheDocument();
    expect(screen.getAllByText('Bs. 889,58').length).toBeGreaterThan(0);
    expect(screen.getByText('= Bs. 42.699,84 hoy')).toBeInTheDocument();
});

it('si la última tasa no es de hoy, dice cuándo se publicó', () => {
    render(<Home rates={[{ date: '2026-11-02', rate: '889.57540000' }]} />);

    expect(screen.getByText('(publicada el 2 nov)', { exact: false })).toBeInTheDocument();
});

it('sin tasas guardadas no inventa montos en bolívares', () => {
    render(<Home rates={[]} />);

    expect(screen.queryByText(/Bs\./)).not.toBeInTheDocument();
    expect(screen.queryByText('Tasa BCV de los últimos días')).not.toBeInTheDocument();
});

it('elegir otro número de cuotas reescribe la cuenta y lo que resta', () => {
    render(<Home rates={RATES} />);

    const example = screen.getByRole('figure', { name: 'Ejemplo de un apartado' });

    expect(example).toHaveTextContent('Resta$24,00');

    fireEvent.click(screen.getByRole('button', { name: '4 cuotas' }));

    expect(screen.getByRole('button', { name: '4 cuotas' })).toHaveAttribute('aria-pressed', 'true');
    expect(example).toHaveTextContent('Resta$27,00');
    expect(example).toHaveTextContent('Cuota 4');
});

it('al cargar, la cuenta de ejemplo se ve completa y solo se reescribe con trazo cuando se cambia', () => {
    render(<Home rates={RATES} />);

    const example = screen.getByRole('figure', { name: 'Ejemplo de un apartado' });

    expect(example.querySelectorAll('.pz-write, .pz-circle')).toHaveLength(0);

    fireEvent.click(screen.getByRole('button', { name: '2 cuotas' }));

    expect(example.querySelectorAll('.pz-write').length).toBeGreaterThan(0);
    expect(example.querySelector('.pz-circle')).not.toBeNull();
});
