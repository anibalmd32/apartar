import { UserMenu } from '@/components/shell/user-menu';
import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';

it('en el celular el botón del usuario mide 44px para tocarlo con el dedo', () => {
    render(<UserMenu name="Valentina Rojas" onLogout={() => undefined} />);

    expect(screen.getByRole('button', { name: 'Menú de Valentina Rojas' })).toHaveClass('max-lg:size-11', 'max-lg:flex');
});
