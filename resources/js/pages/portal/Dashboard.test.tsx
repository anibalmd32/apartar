import Dashboard from '@/pages/portal/Dashboard';
import { fakeRoute } from '@/test/fakes';
import { type PortalDashboardProps } from '@/types/portal';
import { render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

/**
 * La portada mínima del portal (`REQ-IDN-008` RB-4, `T-049`).
 */

vi.mock('@inertiajs/react', async () => ({ Head: () => null, Link: (await import('@/test/fakes')).FakeLink }));

const PROPS: PortalDashboardProps = {
    organization: { name: 'Ferretería Oriente' },
    customer: { fullName: 'María González', documentNumber: 'V-12.345.678' },
    layaways: [
        { code: 'AP-7K3M9QRT', productName: 'Nevera Mabe', quantity: 1, status: 'active', statusLabel: 'Al día', total: '$200.00' },
        { code: 'AP-QX2H8MNB', productName: 'Taladro', quantity: 2, status: 'expired', statusLabel: 'Vencido sin inicial', total: '$90.00' },
    ],
};

beforeEach(() => {
    vi.stubGlobal('route', fakeRoute);
});

/** RB-4 · todos los apartados de la ficha, también los terminales (RB-7). */
it('lista todos los apartados de la ficha con su código y su estado', () => {
    render(<Dashboard {...PROPS} />);

    expect(screen.getAllByRole('listitem')).toHaveLength(2);
    expect(screen.getByText('AP-7K3M9QRT')).toBeInTheDocument();
    expect(screen.getByText('Vencido sin inicial')).toBeInTheDocument();
    expect(screen.getByText(/2 ×/)).toBeInTheDocument();
});

/** La sesión acotada es de un negocio, y lo dice; y se puede salir. */
it('dice con qué negocio es la sesión y ofrece salir', () => {
    render(<Dashboard {...PROPS} />);

    expect(screen.getByRole('heading', { name: 'Tus apartados en Ferretería Oriente' })).toBeInTheDocument();
    expect(screen.getByText('María González')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Salir' })).toHaveAttribute('href', '/mi-cuenta/salir');
});

it('sin apartados, lo dice', () => {
    render(<Dashboard {...PROPS} layaways={[]} />);

    expect(screen.getByText('No tienes apartados en este negocio.')).toBeInTheDocument();
});
