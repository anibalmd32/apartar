import OrganizationsIndex from '@/pages/platform/organizations/Index';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

const { routerGet } = vi.hoisted(() => ({ routerGet: vi.fn() }));

vi.mock('@/layouts/platform-layout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));
vi.mock('@inertiajs/react', () => ({
    router: { get: routerGet },
    Link: ({ href, children, className }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

it('al aplicar la hoja solo lleva a la URL los filtros activos', () => {
    const filters = { plan: null, estado: null, inactivas: false, vencidas: false, al_limite: false, cerradas: false, q: null };
    render(<OrganizationsIndex rows={[]} hasMore={false} page={1} filters={filters} plans={{ pro: 'Pro' }} />);

    fireEvent.click(screen.getByRole('button', { name: 'Filtros' }));
    const sheet = within(screen.getByRole('dialog', { name: 'Filtros' }));
    fireEvent.click(sheet.getByRole('checkbox', { name: 'Incluir cerradas' }));
    fireEvent.click(sheet.getByRole('button', { name: 'Aplicar' }));

    expect(routerGet.mock.calls[0]?.[1]).toEqual({ cerradas: 1 });
});
