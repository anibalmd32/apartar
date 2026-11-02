import { PlatformMenuLinks, PlatformNav, PlatformTabBar } from '@/layouts/platform/nav';
import { Menu, MenuButton, MenuItems } from '@headlessui/react';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

let url = '/plataforma';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url, props: { platformOperator: { pendingReceipts: 0, activeSupportGrants: 0, unreadMail: 2 } } }),
    Link: ({ children, ...rest }: { children: ReactNode } & Record<string, unknown>) => <a {...rest}>{children}</a>,
}));

it('la barra del celular usa los mismos nombres que el menú lateral', () => {
    render(
        <>
            <PlatformNav />
            <PlatformTabBar />
        </>,
    );

    const bar = within(screen.getByRole('navigation', { name: 'Accesos rápidos' }));

    ['Organizaciones', 'Comprobantes', 'Correos'].forEach((name) => {
        expect(bar.getByRole('link', { name })).toBeInTheDocument();
        expect(within(screen.getByRole('navigation', { name: 'Plataforma' })).getByRole('link', { name: new RegExp(name) })).toBeInTheDocument();
    });
    expect(bar.queryByText('Orgs')).not.toBeInTheDocument();
});

it('Correos abre en la bandeja y sigue marcado en las métricas', () => {
    url = '/plataforma/correos';
    render(<PlatformTabBar />);

    const link = screen.getByRole('link', { name: 'Correos' });

    expect(link).toHaveAttribute('href', '/plataforma/correos/bandeja');
    expect(link).toHaveAttribute('aria-current', 'page');
});

it('el menú del avatar lleva a Auditoría y Ajustes', () => {
    render(
        <Menu>
            <MenuButton>Abrir</MenuButton>
            <MenuItems>
                <PlatformMenuLinks />
            </MenuItems>
        </Menu>,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Abrir' }));

    expect(screen.getByRole('menuitem', { name: 'Auditoría' })).toHaveAttribute('href', '/plataforma/auditoria');
    expect(screen.getByRole('menuitem', { name: 'Ajustes' })).toHaveAttribute('href', '/plataforma/ajustes');
});
