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
    expect(screen.getByRole('group', { name: 'Moneda que se muestra primero' })).toBeInTheDocument();
});

it('en el celular cada botón de moneda tiene un área táctil de 44px', () => {
    render(<MobileHeader organizationName="Moda Valentina" pendingReceipts={1} userMenu={null} />);

    for (const name of ['USD', 'Bs.']) {
        expect(screen.getByRole('button', { name })).toHaveClass('hit-area', 'h-[38px]', 'min-w-11');
    }
});
