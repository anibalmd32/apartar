import SupportIndex from '@/pages/platform/support/Index';
import { render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    router: { get: vi.fn(), post: vi.fn() },
    usePage: () => ({ url: '/plataforma/soporte', props: {} }),
    Link: ({ children, ...rest }: { children: ReactNode } & Record<string, unknown>) => <a {...rest}>{children}</a>,
}));
vi.mock('@/layouts/platform-layout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));

it('sin accesos abiertos lleva a las organizaciones, desde donde se abren', () => {
    render(<SupportIndex grants={[]} filter="vigentes" page={1} hasMore={false} />);

    expect(screen.getByRole('link', { name: 'Ir a organizaciones' })).toHaveAttribute('href', '/plataforma/organizaciones');
});
