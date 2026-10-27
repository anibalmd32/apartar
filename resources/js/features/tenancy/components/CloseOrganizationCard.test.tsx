import { CloseOrganizationCard } from '@/features/tenancy/components/CloseOrganizationCard';
import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';

it('no deja cerrar sin confirmar y sin escribir la contraseña', () => {
    render(<CloseOrganizationCard closeUrl="/backoffice/suscripcion/cerrar-negocio" />);

    const button = screen.getByRole('button', { name: 'Cerrar el negocio' });
    expect(button).toBeDisabled();

    fireEvent.click(screen.getByLabelText('Entiendo que mi equipo pierde el acceso al cerrar'));
    expect(button).toBeDisabled();

    fireEvent.change(screen.getByLabelText(/Tu contraseña/), { target: { value: 'mi-contraseña' } });
    expect(button).toBeEnabled();
});
