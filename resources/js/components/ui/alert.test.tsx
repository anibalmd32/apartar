import { cilWarning } from '@coreui/icons';
import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';
import { Alert } from './alert';
import { Icon } from './icon';

it('un aviso de advertencia usa el icono de advertencia y no un reloj', () => {
    const { container } = render(<Icon icon={cilWarning} />);
    const warning = container.querySelector('svg')?.innerHTML;

    render(<Alert tone="warning">La ficha desaparecerá. No se puede deshacer.</Alert>);

    expect(screen.getByRole('alert').querySelector('svg')?.innerHTML).toBe(warning);
});
