import TeamIndex from '@/pages/backoffice/team/Index';
import { type TeamMember } from '@/types/backoffice';
import { render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url: '/backoffice/equipo', props: { errors: {}, planState: null } }),
    useForm: (initial: Record<string, string>) => ({
        data: initial,
        setData: vi.fn(),
        post: vi.fn(),
        processing: false,
        errors: {},
        reset: vi.fn(),
    }),
    router: { visit: vi.fn() },
    Link: ({ href, children }: { href: string; children: ReactNode }) => <a href={href}>{children}</a>,
}));

vi.mock('@/layouts/backoffice-layout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));

const member = (overrides: Partial<TeamMember> = {}): TeamMember => ({
    id: 1,
    name: 'Ana Pérez',
    email: 'ana@apartar.test',
    role: 'member',
    isActive: true,
    invitedAt: null,
    lastLoginAt: null,
    isPending: false,
    isSelf: false,
    isLastOwner: false,
    ...overrides,
});

const usage = { used: 2, limit: 5, isAtLimit: false, percentage: 40 };

it('en el celular cada persona del equipo se apila como tarjeta con sus datos rotulados y sus acciones', () => {
    render(<TeamIndex members={[member(), member({ id: 2, name: 'Luis Mora', isActive: false })]} usage={usage} />);

    const section = screen.getByRole('region', { name: 'Personas con acceso' });
    expect(section).toHaveClass('table-stack');

    const [, row] = within(section).getAllByRole('row');
    const cells = within(row!).getAllByRole('cell');

    expect(cells[0]).toHaveAttribute('data-cell', 'primary');
    expect(cells.slice(1, 4).map((cell) => cell.getAttribute('data-label'))).toEqual(['Rol', 'Estado', 'Último acceso']);
    expect(cells[4]).toHaveAttribute('data-cell', 'actions');
    expect(within(cells[4]!).getByRole('button', { name: 'Desactivar a Ana Pérez' })).toBeInTheDocument();
});

it('en el celular las acciones de una persona del equipo son solo iconos y el texto queda para escritorio', () => {
    render(<TeamIndex members={[member(), member({ id: 2, name: 'Luis Mora', isPending: true })]} usage={usage} />);

    const section = screen.getByRole('region', { name: 'Personas con acceso' });
    const labels = ['Desactivar a Ana Pérez', 'Reenviar el enlace a Luis Mora', 'Revocar la invitación de Luis Mora'];

    labels.forEach((name) => {
        const button = within(section).getByRole('button', { name });

        expect(button.querySelector('svg')).not.toBeNull();
        expect(button.querySelector('span')).toHaveClass('max-md:sr-only');
    });
});

it('el botón de invitar queda separado del rol cuando el formulario va en una columna', () => {
    render(<TeamIndex members={[member()]} usage={usage} />);

    expect(screen.getByRole('button', { name: 'Enviar invitación' })).toHaveClass('mt-3');
});
