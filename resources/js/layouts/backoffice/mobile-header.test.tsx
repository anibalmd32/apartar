import { render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

import { MobileHeader } from './mobile-header';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { teamMember: { unreadChats: 2 } } }),
    Link: () => null,
    router: { visit: vi.fn() },
}));

vi.mock('@/features/currency/hooks/useCurrencyDisplay', () => ({
    useCurrencyDisplay: () => ({ isVesPrimary: false, setDisplay: vi.fn() }),
}));

it('en el celular la cabecera trae la búsqueda y los pendientes', () => {
    render(<MobileHeader organizationName="Moda Valentina" pendingReceipts={1} userMenu={null} />);

    expect(screen.getByText('Moda Valentina')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Buscar' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Pendientes: 3' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Ver precios en (bolívares|dólares)|no hay tasa/ })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Cambiar a modo (oscuro|claro)/ })).toBeInTheDocument();
});

it('en el celular la moneda es un solo botón de 44px para que el nombre del negocio quepa', () => {
    render(<MobileHeader organizationName="Moda Valentina" pendingReceipts={1} userMenu={null} />);

    expect(screen.getByRole('button', { name: /Ver precios en (bolívares|dólares)|no hay tasa/ })).toHaveClass('touch:size-11');
    expect(screen.queryByRole('group', { name: 'Moneda que se muestra primero' })).toBeNull();
});
