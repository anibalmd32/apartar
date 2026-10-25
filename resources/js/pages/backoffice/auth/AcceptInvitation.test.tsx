import AcceptInvitation from '@/pages/backoffice/auth/AcceptInvitation';
import { render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    useForm: () => ({
        data: { password: '', password_confirmation: '' },
        setData: vi.fn(),
        post: vi.fn(),
        processing: false,
        errors: {},
        reset: vi.fn(),
    }),
}));

vi.mock('@/layouts/backoffice/access-layout', () => ({
    default: ({ header, children }: { header?: ReactNode; children: ReactNode }) => (
        <main>
            {header}
            {children}
        </main>
    ),
}));

it('dice el rol y cuánto le queda al enlace', () => {
    render(
        <AcceptInvitation token="t" name="Carla" email="carla@ejemplo.test" organizationName="Boutique Mariela" role="member" expiresInHours={47} />,
    );

    expect(screen.getByText('Te invita como Miembro del equipo')).toBeInTheDocument();
    expect(screen.getByText('Esta invitación vence en 47 horas.')).toBeInTheDocument();
});

it('quien ya tiene cuenta se une con su contraseña de siempre', () => {
    render(<AcceptInvitation token="t" name="Carla" email="carla@ejemplo.test" organizationName="Zapatería Luz" hasAccount />);

    expect(screen.getByLabelText('Tu contraseña de Apartar')).toBeInTheDocument();
    expect(screen.queryByLabelText('Repite la contraseña')).toBeNull();
});
